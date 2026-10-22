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
| AUTO-CREATE / MIGRATE TABLES
|--------------------------------------------------------------------------
*/
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS subjects (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    school_id         INT NOT NULL,
    subject_name      VARCHAR(150) NOT NULL,
    subject_code      VARCHAR(40)  DEFAULT NULL,
    description       TEXT         DEFAULT NULL,
    status            ENUM('active','inactive') DEFAULT 'active',
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_subjects_school (school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

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

/*
|--------------------------------------------------------------------------
| FETCH SCHOOL'S ACTIVE ACADEMIC LEVELS
|--------------------------------------------------------------------------
*/
$active_levels = [];
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

$valid_level_enums = [];
$level_map         = [];
$level_colors      = [
    'Nursery' => 'pink',
    'Primary' => 'blue',
    'O_Level' => 'green',
    'A_Level' => 'orange',
];

foreach ($active_levels as $key => $label_in) {
    $enum_val = $LEVEL_KEY_TO_ENUM[$key] ?? null;
    if ($enum_val !== null) {
        $valid_level_enums[] = $enum_val;
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
| FLASH MESSAGES
|--------------------------------------------------------------------------
*/
$message      = "";
$message_type = "";

if (($_GET['added']       ?? '') === '1') { $message = "Subject added successfully.";       $message_type = "success"; }
if (($_GET['updated']     ?? '') === '1') { $message = "Subject updated successfully.";     $message_type = "success"; }
if (($_GET['deleted']     ?? '') === '1') { $message = "Subject removed successfully.";     $message_type = "success"; }
if (($_GET['activated']   ?? '') === '1') { $message = "Subject activated successfully.";   $message_type = "success"; }
if (($_GET['deactivated'] ?? '') === '1') { $message = "Subject deactivated successfully."; $message_type = "success"; }

/* Special message for missing teachers */
if (isset($_GET['gaps']) && (int)$_GET['gaps'] > 0) {
    $g = (int)$_GET['gaps'];
    $message = "Subject saved. Note: {$g} class" . ($g === 1 ? '' : 'es') . " still have no teacher assigned.";
    $message_type = "error";
}

if (isset($_GET['error'])) { $message = urldecode($_GET['error']); $message_type = "error"; }

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/
$q       = trim($_GET['q'] ?? '');
$level   = $_GET['level']   ?? '';
$status  = $_GET['status']  ?? '';
$coverage = $_GET['coverage'] ?? ''; // full | partial | none

$where  = ["s.school_id = ?"];
$params = [$school_id];
$types  = "i";

if ($q !== '') {
    $where[] = "(s.subject_name LIKE ? OR s.subject_code LIKE ? OR s.description LIKE ?)";
    $like = "%{$q}%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= "sss";
}
if (in_array($level, $valid_level_enums, true)) {
    $where[] = "EXISTS (
        SELECT 1 FROM subject_classes sc
        INNER JOIN classes c ON c.id = sc.class_id
        WHERE sc.school_id = s.school_id
          AND sc.subject_id = s.id
          AND c.level = ?
    )";
    $params[] = $level; $types .= "s";
} else {
    $level = '';
}
if (in_array($status, ['active','inactive'], true)) {
    $where[] = "s.status = ?"; $params[] = $status; $types .= "s";
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

$count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM subjects s WHERE $where_sql");
$total = 0;
if ($count_stmt) {
    mysqli_stmt_bind_param($count_stmt, $types, ...$params);
    mysqli_stmt_execute($count_stmt);
    $cr = mysqli_stmt_get_result($count_stmt);
    $total = $cr ? (int) mysqli_fetch_assoc($cr)['total'] : 0;
    mysqli_stmt_close($count_stmt);
}
$total_pages = max(1, (int) ceil($total / $per_page));

$list_stmt = mysqli_prepare($conn, "
    SELECT s.*
    FROM subjects s
    WHERE $where_sql
    ORDER BY s.created_at DESC
    LIMIT ? OFFSET ?
");
$subjects_res = null;
if ($list_stmt) {
    $types_full  = $types . "ii";
    $params_full = array_merge($params, [$per_page, $offset]);
    mysqli_stmt_bind_param($list_stmt, $types_full, ...$params_full);
    mysqli_stmt_execute($list_stmt);
    $subjects_res = mysqli_stmt_get_result($list_stmt);
}

/* Collect subject IDs for detail fetch */
$subject_ids = [];
if ($subjects_res) {
    $tmp = [];
    while ($row = mysqli_fetch_assoc($subjects_res)) {
        $tmp[] = $row;
    }
    foreach ($tmp as $row) $subject_ids[] = (int)$row['id'];

    /* Reset pointer */
    $subjects_res = $tmp;
}

/*
|--------------------------------------------------------------------------
| FETCH DETAILS (classes, teachers, credits) FOR ALL VISIBLE SUBJECTS
|--------------------------------------------------------------------------
| Returns per subject:
|   - class_count
|   - levels        [ 'Primary' => true, ... ]
|   - teachers      [ teacher_id => ['name'=>..., 'classes'=>...] ]
|   - unassigned    int
|   - credits_min, credits_max
|   - hours_min,   hours_max
|   - marks_min,   marks_max
*/
$subject_details = [];

if (!empty($subject_ids)) {
    $placeholders = implode(',', array_fill(0, count($subject_ids), '?'));
    $types_d = "i" . str_repeat('i', count($subject_ids));
    $params_d = array_merge([$school_id], $subject_ids);

    $sql = "
        SELECT
            sc.subject_id,
            sc.class_id,
            sc.credits,
            sc.week_hours,
            sc.max_marks,
            c.class_name,
            c.level AS class_level,
            sct.teacher_id,
            t.name AS teacher_name
        FROM subject_classes sc
        INNER JOIN classes c ON c.id = sc.class_id
        LEFT JOIN subject_class_teachers sct
               ON sct.subject_id = sc.subject_id
              AND sct.class_id = sc.class_id
        LEFT JOIN teachers t ON t.id = sct.teacher_id AND t.school_id = sc.school_id
        WHERE sc.school_id = ? AND sc.subject_id IN ($placeholders)
        ORDER BY c.level ASC, c.class_name ASC
    ";

    $d_stmt = mysqli_prepare($conn, $sql);
    if ($d_stmt) {
        mysqli_stmt_bind_param($d_stmt, $types_d, ...$params_d);
        mysqli_stmt_execute($d_stmt);
        $d_res = mysqli_stmt_get_result($d_stmt);

        while ($row = mysqli_fetch_assoc($d_res)) {
            $sid = (int)$row['subject_id'];

            if (!isset($subject_details[$sid])) {
                $subject_details[$sid] = [
                    'class_count'   => 0,
                    'levels'        => [],
                    'teachers'      => [],   // teacher_id => ['name'=>..., 'classes'=>int]
                    'unassigned'    => 0,
                    'credits_min'   => null, 'credits_max' => null,
                    'hours_min'     => null, 'hours_max'   => null,
                    'marks_min'     => null, 'marks_max'   => null,
                ];
            }

            $subject_details[$sid]['class_count']++;

            $lv = $row['class_level'];
            if ($lv) $subject_details[$sid]['levels'][$lv] = true;

            /* Teacher */
            $tid = (int)($row['teacher_id'] ?? 0);
            if ($tid > 0 && !empty($row['teacher_name'])) {
                if (!isset($subject_details[$sid]['teachers'][$tid])) {
                    $subject_details[$sid]['teachers'][$tid] = [
                        'name'    => $row['teacher_name'],
                        'classes' => 0,
                    ];
                }
                $subject_details[$sid]['teachers'][$tid]['classes']++;
            } else {
                $subject_details[$sid]['unassigned']++;
            }

            /* Credits / Hours / Marks (min/max) */
            if ($row['credits'] !== null) {
                $c = (float)$row['credits'];
                if ($subject_details[$sid]['credits_min'] === null || $c < $subject_details[$sid]['credits_min']) $subject_details[$sid]['credits_min'] = $c;
                if ($subject_details[$sid]['credits_max'] === null || $c > $subject_details[$sid]['credits_max']) $subject_details[$sid]['credits_max'] = $c;
            }
            if ($row['week_hours'] !== null) {
                $h = (float)$row['week_hours'];
                if ($subject_details[$sid]['hours_min'] === null || $h < $subject_details[$sid]['hours_min']) $subject_details[$sid]['hours_min'] = $h;
                if ($subject_details[$sid]['hours_max'] === null || $h > $subject_details[$sid]['hours_max']) $subject_details[$sid]['hours_max'] = $h;
            }
            if ($row['max_marks'] !== null) {
                $m = (int)$row['max_marks'];
                if ($subject_details[$sid]['marks_min'] === null || $m < $subject_details[$sid]['marks_min']) $subject_details[$sid]['marks_min'] = $m;
                if ($subject_details[$sid]['marks_max'] === null || $m > $subject_details[$sid]['marks_max']) $subject_details[$sid]['marks_max'] = $m;
            }
        }
        mysqli_stmt_close($d_stmt);
    }
}

/*
|--------------------------------------------------------------------------
| STATS
|--------------------------------------------------------------------------
*/
function stat_count($conn, $school_id, $field = null, $value = null) {
    if ($field === null) {
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM subjects WHERE school_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $school_id);
    } else {
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM subjects WHERE school_id = ? AND $field = ?");
        mysqli_stmt_bind_param($stmt, "is", $school_id, $value);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $n   = $res ? (int) mysqli_fetch_assoc($res)['total'] : 0;
    mysqli_stmt_close($stmt);
    return $n;
}

function stat_subjects_in_level($conn, $school_id, $level) {
    $stmt = mysqli_prepare($conn, "
        SELECT COUNT(DISTINCT sc.subject_id) AS total
        FROM subject_classes sc
        INNER JOIN classes c ON c.id = sc.class_id
        WHERE sc.school_id = ? AND c.level = ?
    ");
    mysqli_stmt_bind_param($stmt, "is", $school_id, $level);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $n   = $res ? (int) mysqli_fetch_assoc($res)['total'] : 0;
    mysqli_stmt_close($stmt);
    return $n;
}

function stat_subjects_without_teacher($conn, $school_id) {
    /* Subjects that have at least one class without a teacher */
    $stmt = mysqli_prepare($conn, "
        SELECT COUNT(DISTINCT sc.subject_id) AS total
        FROM subject_classes sc
        LEFT JOIN subject_class_teachers sct
            ON sct.subject_id = sc.subject_id AND sct.class_id = sc.class_id
        WHERE sc.school_id = ?
          AND (sct.teacher_id IS NULL OR sct.teacher_id = 0)
    ");
    mysqli_stmt_bind_param($stmt, "i", $school_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $n   = $res ? (int) mysqli_fetch_assoc($res)['total'] : 0;
    mysqli_stmt_close($stmt);
    return $n;
}

$total_subjects    = stat_count($conn, $school_id);
$active_subjects   = stat_count($conn, $school_id, 'status', 'active');
$inactive_subjects = stat_count($conn, $school_id, 'status', 'inactive');
$unassigned_count  = stat_subjects_without_teacher($conn, $school_id);

/* Level counts (subjects that have classes in that level) */
$level_counts = [];
foreach ($valid_level_enums as $lv) {
    $level_counts[$lv] = stat_subjects_in_level($conn, $school_id, $lv);
}

/*
|--------------------------------------------------------------------------
| EXPORT QUERY STRING
|--------------------------------------------------------------------------
*/
$export_qs = http_build_query([
    'q'      => $q,
    'level'  => $level,
    'status' => $status,
]);
$has_filters = ($q !== '' || $level !== '' || $status !== '');
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subjects | Educ Bridge</title>
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
            width: 260px; background: var(--sidebar-bg); color: var(--sidebar-text);
            display: flex; flex-direction: column;
            position: fixed; top:0; left:0; height:100vh; z-index:100;
            transition: background 0.4s ease, color 0.4s ease, transform 0.3s ease, border-color 0.4s ease;
            border-right: 1px solid var(--sidebar-border);
            box-shadow: var(--shadow-sidebar);
        }
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

        /* MAIN */
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

        /* CONTENT */
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
        .page-head .head-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }

        .btn { display:inline-flex; align-items:center; gap:8px; padding:12px 20px; border-radius:10px; font-size:14px; font-weight:600; text-decoration:none; cursor:pointer; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-primary); transition:var(--transition); font-family:'Inter',sans-serif; }
        .btn:hover { border-color:var(--primary); color:var(--primary); transform:translateY(-1px); }
        .btn.primary { background:var(--primary); color:#fff; border-color:var(--primary); box-shadow:0 6px 18px rgba(37,99,235,0.32); }
        .btn.primary:hover { background:var(--primary-dark); color:#fff; }
        .btn.pdf { border-color:rgba(220,38,38,0.35); color:#dc2626; }
        .btn.pdf:hover { background:#dc2626; color:#fff; border-color:#dc2626; }
        .btn.excel { border-color:rgba(5,150,105,0.35); color:#059669; }
        .btn.excel:hover { background:#059669; color:#fff; border-color:#059669; }

        /* SUMMARY CARDS */
        .summaries {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }
        .sum-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: var(--transition);
        }
        .sum-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary);
        }
        .sum-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; color: #fff; flex-shrink: 0;
        }
        .sum-icon.blue    { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .sum-icon.green   { background: linear-gradient(135deg, #10b981, #34d399); }
        .sum-icon.pink    { background: linear-gradient(135deg, #ec4899, #f472b6); }
        .sum-icon.orange  { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .sum-icon.purple  { background: linear-gradient(135deg, #7c3aed, #a78bfa); }
        .sum-icon.red     { background: linear-gradient(135deg, #ef4444, #f87171); }
        .sum-icon.teal    { background: linear-gradient(135deg, #14b8a6, #2dd4bf); }
        .sum-icon.slate   { background: linear-gradient(135deg, #475569, #64748b); }

        .sum-info .num {
            font-family: 'Poppins', sans-serif;
            font-size: 24px; font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
            letter-spacing: -0.4px;
        }
        .sum-info .lbl {
            font-size: 12.5px;
            color: var(--text-muted);
            margin-top: 3px;
            font-weight: 500;
        }

        /* FILTERS */
        .filters { background:var(--bg-card); border:1px solid var(--border-color); border-radius:14px; padding:16px 18px; margin-bottom:20px; display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
        .filter-group { position:relative; display:flex; align-items:center; flex:1 1 180px; min-width:160px; }
        .filter-group i { position:absolute; left:13px; color:var(--text-muted); font-size:13px; pointer-events:none; }
        .filter-group input, .filter-group select { width:100%; padding:10px 14px 10px 36px; border:1.5px solid var(--border-color); border-radius:10px; background:var(--bg-alt); color:var(--text-primary); font-family:inherit; font-size:13.5px; outline:none; transition:var(--transition); }
        .filter-group select { padding-left:36px; cursor:pointer; }
        .filter-group input:focus, .filter-group select:focus { border-color:var(--primary); background:var(--bg-card); box-shadow:0 0 0 3px rgba(37,99,235,0.1); }

        .filter-summary { display:flex; align-items:center; flex-wrap:wrap; gap:8px; padding:10px 14px; background:var(--primary-light); border:1px solid rgba(37,99,235,0.25); border-radius:10px; margin-bottom:16px; font-size:13px; color:var(--primary); font-weight:600; }
        [data-theme="dark"] .filter-summary { background: rgba(59,130,246,0.12); color:#93c5fd; }
        .filter-summary .fs-tag { display:inline-flex; align-items:center; gap:5px; background:rgba(255,255,255,0.55); padding:3px 10px; border-radius:50px; font-size:12px; font-weight:700; }
        [data-theme="dark"] .filter-summary .fs-tag { background: rgba(0,0,0,0.25); }

        /* TABLE */
        .table-card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:16px; overflow: visible; }
        .table-wrap { overflow-x:auto; }
        .table-wrap, .table-card { overflow: visible !important; }
        table { width:100%; border-collapse:collapse; min-width:1200px; }
        thead th { background:var(--bg-alt); text-align:left; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.6px; color:var(--text-muted); padding:14px 16px; border-bottom:1px solid var(--border-color); white-space:nowrap; }
        tbody td { padding:14px 16px; font-size:13.5px; color:var(--text-primary); border-bottom:1px solid var(--border-color); vertical-align:middle; }
        tbody tr:hover { background:var(--bg-alt); }
        tbody tr:last-child td { border-bottom:none; }

        .s-name { display:flex; align-items:center; gap:12px; }
        .s-avatar {
            width: 42px; height: 42px; border-radius: 12px;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: #fff; display: flex; align-items: center; justify-content: center;
            font-family: 'Poppins', sans-serif; font-size: 14px; font-weight: 700;
            flex-shrink: 0;
        }
        .s-name-info .n { font-weight: 700; font-size: 14.5px; color: var(--text-primary); }
        .s-name-info .s { font-size: 12px; color: var(--text-muted); margin-top: 1px; }

        .pill { display:inline-block; padding:3px 10px; border-radius:50px; font-size:11.5px; font-weight:700; letter-spacing:0.3px; line-height:1.5; }
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

        .level-pills { display: flex; flex-wrap: wrap; gap: 4px; }

        .teacher-summary {
            display: flex; align-items: center; gap: 10px;
            font-size: 13px;
        }
        .teacher-summary .ts-icon {
            width: 32px; height: 32px; border-radius: 50%;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: #fff; display: flex; align-items: center; justify-content: center;
            font-family: 'Poppins', sans-serif; font-size: 12px; font-weight: 700;
            flex-shrink: 0;
        }
        .teacher-summary .ts-icon.multi {
            background: linear-gradient(135deg, #7c3aed, #ec4899);
        }
        .teacher-summary .ts-icon.unassigned {
            background: var(--bg-alt);
            color: var(--text-muted);
            border: 1.5px dashed var(--border-color);
        }
        .teacher-summary .ts-text .t {
            font-weight: 700; font-size: 13px; color: var(--text-primary);
        }
        .teacher-summary .ts-text .s {
            font-size: 11.5px; color: var(--text-muted); margin-top: 1px;
        }
        .teacher-summary .ts-text .t.muted { color: var(--text-muted); font-style: italic; font-weight: 500; }

        .coverage-badge {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 11px; font-weight: 700;
            padding: 3px 9px; border-radius: 50px;
            letter-spacing: 0.3px;
        }
        .coverage-badge.full    { background: rgba(16,185,129,0.12); color: #059669; }
        .coverage-badge.partial { background: rgba(245,158,11,0.15); color: #b45309; }
        .coverage-badge.none    { background: rgba(239,68,68,0.12);  color: #dc2626; }
        [data-theme="dark"] .coverage-badge.full    { color: #34d399; }
        [data-theme="dark"] .coverage-badge.partial { color: #fbbf24; }
        [data-theme="dark"] .coverage-badge.none    { color: #f87171; }

        .range-cell { font-family: 'Poppins', sans-serif; font-weight: 700; font-size: 13.5px; }
        .range-cell.muted { color: var(--text-muted); font-weight: 500; font-family: 'Inter', sans-serif; font-style: italic; }
        .range-cell .sep { color: var(--text-muted); font-weight: 500; margin: 0 1px; }

        /* Actions menu */
        .actions-cell { position: relative; text-align: right; width: 80px; }
        .actions-btn {
            width: 38px; height: 38px;
            border-radius: 10px;
            border: 1.5px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-secondary);
            display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer;
            font-size: 16px;
            transition: var(--transition);
            padding: 0;
            line-height: 1;
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
            min-width: 220px;
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
        .actions-menu a.danger { color: var(--danger); }
        .actions-menu a.danger i { color: var(--danger); }
        .actions-menu a.danger:hover { background: var(--danger-light); }
        .actions-menu a.activate-link i { color: #059669 !important; }
        .actions-menu a.warn-link i { color: #f59e0b !important; }
        .actions-menu .divider { height: 1px; background: var(--border-color); margin: 4px 6px; }

        .empty-state { text-align:center; padding:60px 20px; color:var(--text-muted); }
        .empty-state i { font-size:50px; opacity:0.35; margin-bottom:14px; display:block; }
        .empty-state h3 { font-family:'Poppins',sans-serif; font-size:17px; font-weight:700; color:var(--text-primary); margin-bottom:6px; }
        .empty-state p { font-size:14px; line-height:1.6; margin-bottom:18px; }

        /* Pagination */
        .pagination { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:16px 20px; border-top:1px solid var(--border-color); flex-wrap:wrap; }
        .pagination .info { font-size:13px; color:var(--text-muted); }
        .pagination .pages { display:flex; gap:6px; }
        .pagination .pages a, .pagination .pages span { min-width:36px; height:36px; display:flex; align-items:center; justify-content:center; border-radius:8px; padding:0 10px; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); text-decoration:none; font-size:13px; font-weight:600; transition:var(--transition); }
        .pagination .pages a:hover { border-color:var(--primary); color:var(--primary); }
        .pagination .pages .active { background:var(--primary); color:#fff; border-color:var(--primary); }
        .pagination .pages .disabled { opacity:0.5; cursor:not-allowed; }

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
            <a href="school-classes.php" class="nav-item">
                <i class="fas fa-layer-group nav-icon"></i> Classes
            </a>
            <a href="school-subjects.php" class="nav-item active">
                <i class="fas fa-book nav-icon"></i> Subjects
                <?php if ($total_subjects > 0): ?>
                    <span class="nav-badge"><?= $total_subjects; ?></span>
                <?php endif; ?>
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
                    <div class="page-title">Subjects</div>
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
                    <h1><i class="fas fa-book"></i> Subjects</h1>
                    <div class="sub">All subjects taught at <?= htmlspecialchars($school_name); ?></div>
                </div>

                <div class="head-actions">
                    <a href="school-export-subjects.php?type=pdf&<?= htmlspecialchars($export_qs); ?>"
                       class="btn pdf" target="_blank">
                        <i class="fas fa-file-pdf"></i> PDF <?= $has_filters ? '(filtered)' : '(all)'; ?>
                    </a>
                    <a href="school-export-subjects.php?type=excel&<?= htmlspecialchars($export_qs); ?>"
                       class="btn excel">
                        <i class="fas fa-file-excel"></i> Excel <?= $has_filters ? '(filtered)' : '(all)'; ?>
                    </a>
                    <a href="school-add-subject.php" class="btn primary">
                        <i class="fas fa-plus"></i> Create New Subject
                    </a>
                </div>
            </div>

            <!-- FILTER SUMMARY -->
            <?php if ($has_filters): ?>
                <div class="filter-summary">
                    <i class="fas fa-filter"></i>
                    <span>Active filters:</span>
                    <?php if ($q !== ''): ?>
                        <span class="fs-tag"><i class="fas fa-search"></i> "<?= htmlspecialchars($q); ?>"</span>
                    <?php endif; ?>
                    <?php if ($level !== ''): ?>
                        <span class="fs-tag"><i class="fas fa-layer-group"></i> <?= htmlspecialchars($level_map[$level] ?? $level); ?></span>
                    <?php endif; ?>
                    <?php if ($status !== ''): ?>
                        <span class="fs-tag"><i class="fas fa-toggle-on"></i> <?= ucfirst(htmlspecialchars($status)); ?></span>
                    <?php endif; ?>
                    <a href="school-subjects.php" style="color:inherit;text-decoration:underline;margin-left:auto;font-weight:700;font-size:12.5px;">
                        Clear
                    </a>
                </div>
            <?php endif; ?>

            <!-- SUMMARY CARDS -->
            <div class="summaries">
                <div class="sum-card">
                    <div class="sum-icon blue"><i class="fas fa-book"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $total_subjects; ?></div>
                        <div class="lbl">Total Subjects</div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon green"><i class="fas fa-check-circle"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $active_subjects; ?></div>
                        <div class="lbl">Active</div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon red"><i class="fas fa-ban"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $inactive_subjects; ?></div>
                        <div class="lbl">Inactive</div>
                    </div>
                </div>
                <?php foreach ($valid_level_enums as $lv_enum):
                    $lv_count = $level_counts[$lv_enum] ?? 0;
                    $col_map  = ['Nursery'=>'pink','Primary'=>'purple','O_Level'=>'teal','A_Level'=>'orange'];
                    $col      = $col_map[$lv_enum] ?? 'slate';
                ?>
                    <div class="sum-card">
                        <div class="sum-icon <?= $col; ?>"><i class="fas fa-graduation-cap"></i></div>
                        <div class="sum-info">
                            <div class="num"><?= $lv_count; ?></div>
                            <div class="lbl"><?= htmlspecialchars($level_map[$lv_enum] ?? $lv_enum); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="sum-card">
                    <div class="sum-icon slate"><i class="fas fa-user-slash"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $unassigned_count; ?></div>
                        <div class="lbl">With gaps</div>
                    </div>
                </div>
            </div>

            <!-- FILTERS -->
            <form method="GET" action="school-subjects.php" class="filters">
                <div class="filter-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="q" placeholder="Search subject name or code" value="<?= htmlspecialchars($q); ?>">
                </div>

                <div class="filter-group">
                    <i class="fas fa-layer-group"></i>
                    <select name="level">
                        <option value="">All Levels</option>
                        <?php foreach ($valid_level_enums as $lv_enum): ?>
                            <option value="<?= htmlspecialchars($lv_enum); ?>" <?= $level === $lv_enum ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($level_map[$lv_enum] ?? $lv_enum); ?>
                            </option>
                        <?php endforeach; ?>
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
                <a href="school-subjects.php" class="btn"><i class="fas fa-rotate"></i> Reset</a>
            </form>

            <!-- TABLE -->
            <div class="table-card">
                <?php if (!empty($subjects_res)): ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th>Levels</th>
                                    <th>Classes</th>
                                    <th>Credits</th>
                                    <th>Week Hrs</th>
                                    <th>Max Marks</th>
                                    <th>Teachers</th>
                                    <th>Coverage</th>
                                    <th>Status</th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subjects_res as $s): ?>
                                    <?php
                                        $sid = (int) $s['id'];
                                        $det = $subject_details[$sid] ?? [
                                            'class_count' => 0,
                                            'levels' => [],
                                            'teachers' => [],
                                            'unassigned' => 0,
                                            'credits_min' => null, 'credits_max' => null,
                                            'hours_min' => null, 'hours_max' => null,
                                            'marks_min' => null, 'marks_max' => null,
                                        ];

                                        /* Initials for avatar */
                                        $s_init = "";
                                        foreach (explode(" ", trim($s['subject_name'])) as $part) {
                                            if ($part !== "") {
                                                $s_init .= strtoupper($part[0]);
                                                if (strlen($s_init) >= 2) break;
                                            }
                                        }
                                        if ($s_init === "") $s_init = "S";

                                        /* Levels list */
                                        $lvl_keys = array_keys($det['levels']);

                                        /* Teachers */
                                        $teacher_count = count($det['teachers']);
                                        $unassigned    = (int)$det['unassigned'];
                                        $total_classes = (int)$det['class_count'];

                                        /* Coverage */
                                        if ($total_classes === 0)        $cov = 'none';
                                        elseif ($unassigned === 0)       $cov = 'full';
                                        elseif ($unassigned === $total_classes) $cov = 'none';
                                        else                             $cov = 'partial';

                                        $cov_label = [
                                            'full'    => 'Fully covered',
                                            'partial' => ($total_classes - $unassigned) . '/' . $total_classes . ' covered',
                                            'none'    => 'No teacher yet',
                                        ][$cov];

                                        $cov_icon = [
                                            'full'    => 'fa-circle-check',
                                            'partial' => 'fa-circle-half-stroke',
                                            'none'    => 'fa-circle-exclamation',
                                        ][$cov];
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="s-name">
                                                <div class="s-avatar"><?= htmlspecialchars($s_init); ?></div>
                                                <div class="s-name-info">
                                                    <div class="n"><?= htmlspecialchars($s['subject_name']); ?></div>
                                                    <?php if (!empty($s['subject_code'])): ?>
                                                        <div class="s" style="font-family:'Courier New',monospace;"><?= htmlspecialchars($s['subject_code']); ?></div>
                                                    <?php else: ?>
                                                        <div class="s">Created <?= date('M j, Y', strtotime($s['created_at'])); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Levels (from classes) -->
                                        <td>
                                            <?php if (!empty($lvl_keys)): ?>
                                                <div class="level-pills">
                                                    <?php foreach ($lvl_keys as $lk):
                                                        $lv_col = $level_colors[$lk] ?? 'gray';
                                                    ?>
                                                        <span class="pill <?= $lv_col; ?>"><?= htmlspecialchars($level_map[$lk] ?? $lk); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="range-cell muted">No classes</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Class count -->
                                        <td>
                                            <span class="range-cell"><?= $total_classes; ?></span>
                                        </td>

                                        <!-- Credits -->
                                        <td>
                                            <?php if ($det['credits_min'] === null): ?>
                                                <span class="range-cell muted">—</span>
                                            <?php elseif ($det['credits_min'] === $det['credits_max']): ?>
                                                <span class="range-cell"><?= rtrim(rtrim(number_format($det['credits_min'], 1), '0'), '.') ?></span>
                                            <?php else: ?>
                                                <span class="range-cell"><?= rtrim(rtrim(number_format($det['credits_min'], 1), '0'), '.') ?><span class="sep">–</span><?= rtrim(rtrim(number_format($det['credits_max'], 1), '0'), '.') ?></span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Week hours -->
                                        <td>
                                            <?php if ($det['hours_min'] === null): ?>
                                                <span class="range-cell muted">—</span>
                                            <?php elseif ($det['hours_min'] === $det['hours_max']): ?>
                                                <span class="range-cell"><?= rtrim(rtrim(number_format($det['hours_min'], 1), '0'), '.') ?></span>
                                            <?php else: ?>
                                                <span class="range-cell"><?= rtrim(rtrim(number_format($det['hours_min'], 1), '0'), '.') ?><span class="sep">–</span><?= rtrim(rtrim(number_format($det['hours_max'], 1), '0'), '.') ?></span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Max marks -->
                                        <td>
                                            <?php if ($det['marks_min'] === null): ?>
                                                <span class="range-cell muted">—</span>
                                            <?php elseif ($det['marks_min'] === $det['marks_max']): ?>
                                                <span class="range-cell"><?= (int)$det['marks_min']; ?></span>
                                            <?php else: ?>
                                                <span class="range-cell"><?= (int)$det['marks_min']; ?><span class="sep">–</span><?= (int)$det['marks_max']; ?></span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Teachers -->
                                        <td>
                                            <?php if ($teacher_count === 0 && $unassigned === 0): ?>
                                                <span class="range-cell muted">—</span>
                                            <?php elseif ($teacher_count === 0): ?>
                                                <div class="teacher-summary">
                                                    <div class="ts-icon unassigned"><i class="fas fa-user-slash" style="font-size:11px;"></i></div>
                                                    <div class="ts-text">
                                                        <div class="t muted">Unassigned</div>
                                                        <div class="s"><?= $unassigned; ?> class<?= $unassigned === 1 ? '' : 'es'; ?></div>
                                                    </div>
                                                </div>
                                            <?php elseif ($teacher_count === 1 && $unassigned === 0): ?>
                                                <?php
                                                    $t = array_values($det['teachers'])[0];
                                                    $ti = "";
                                                    foreach (explode(" ", trim($t['name'])) as $p) {
                                                        if ($p !== "") { $ti .= strtoupper($p[0]); if (strlen($ti) >= 2) break; }
                                                    }
                                                    if ($ti === "") $ti = "T";
                                                ?>
                                                <div class="teacher-summary">
                                                    <div class="ts-icon"><?= htmlspecialchars($ti); ?></div>
                                                    <div class="ts-text">
                                                        <div class="t"><?= htmlspecialchars($t['name']); ?></div>
                                                        <div class="s"><?= (int)$t['classes']; ?> class<?= ((int)$t['classes']) === 1 ? '' : 'es'; ?></div>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <?php
                                                    /* Multiple teachers */
                                                    $names = array_map(function($t){ return $t['name']; }, array_values($det['teachers']));
                                                    $summary_text = $teacher_count . ' teacher' . ($teacher_count === 1 ? '' : 's');
                                                ?>
                                                <div class="teacher-summary">
                                                    <div class="ts-icon multi"><i class="fas fa-users" style="font-size:11px;"></i></div>
                                                    <div class="ts-text">
                                                        <div class="t"><?= $summary_text; ?></div>
                                                        <div class="s" title="<?= htmlspecialchars(implode(', ', $names)); ?>">
                                                            <?= htmlspecialchars(implode(', ', array_slice($names, 0, 2))); ?>
                                                            <?= count($names) > 2 ? ' +' . (count($names) - 2) : ''; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Coverage -->
                                        <td>
                                            <span class="coverage-badge <?= $cov; ?>">
                                                <i class="fas <?= $cov_icon; ?>"></i> <?= htmlspecialchars($cov_label); ?>
                                            </span>
                                        </td>

                                        <!-- Status -->
                                        <td>
                                            <?php if ($s['status'] === 'active'): ?>
                                                <span class="pill green">Active</span>
                                            <?php else: ?>
                                                <span class="pill red">Inactive</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Actions -->
                                        <td class="actions-cell">
                                            <button type="button" class="actions-btn" data-id="<?= $sid; ?>" title="More options">
                                                <i class="fas fa-ellipsis-vertical"></i>
                                            </button>

                                            <div class="actions-menu" data-menu-for="<?= $sid; ?>">
                                                <a href="school-edit-subject.php?id=<?= $sid; ?>">
                                                    <i class="fas fa-pen"></i> Edit
                                                </a>
                                                <a href="school-view-subject.php?id=<?= $sid; ?>">
                                                    <i class="fas fa-eye"></i> View details
                                                </a>

                                                <div class="divider"></div>

                                                <?php if ($s['status'] === 'active'): ?>
                                                    <a href="school-toggle-subject.php?id=<?= $sid; ?>&action=deactivate"
                                                       class="warn-link"
                                                       onclick="return confirm('Deactivate this subject?');">
                                                        <i class="fas fa-ban"></i> Deactivate
                                                    </a>
                                                <?php else: ?>
                                                    <a href="school-toggle-subject.php?id=<?= $sid; ?>&action=activate"
                                                       class="activate-link"
                                                       onclick="return confirm('Activate this subject?');">
                                                        <i class="fas fa-check-circle"></i> Activate
                                                    </a>
                                                <?php endif; ?>

                                                <div class="divider"></div>

                                                <a href="school-delete-subject.php?id=<?= $sid; ?>"
                                                   class="danger"
                                                   onclick="return confirm('Delete this subject? This cannot be undone.');">
                                                    <i class="fas fa-trash"></i> Delete
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <div class="info">
                                Showing <?= $offset + 1; ?>–<?= min($offset + $per_page, $total); ?> of <?= $total; ?> subject(s)
                            </div>
                            <div class="pages">
                                <?php
                                    $qs = $_GET;
                                    $build = function ($p) use ($qs) {
                                        $qs['page'] = $p;
                                        return 'school-subjects.php?' . http_build_query($qs);
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
                        <i class="fas fa-book"></i>
                        <h3>No subjects yet</h3>
                        <p>
                            <?= $has_filters
                                ? 'No subjects matched your filters. Try adjusting them or reset to see all.'
                                : 'Create your first subject to start organising the curriculum.'; ?>
                        </p>
                        <?php if ($has_filters): ?>
                            <a href="school-subjects.php" class="btn primary">
                                <i class="fas fa-rotate"></i> Clear filters
                            </a>
                        <?php else: ?>
                            <a href="school-add-subject.php" class="btn primary">
                                <i class="fas fa-plus"></i> Create New Subject
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
</div>

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