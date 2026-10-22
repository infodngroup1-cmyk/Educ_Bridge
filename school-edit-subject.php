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
$short_school = mb_strimwidth($school_name, 0, 24, '…');

/*
|--------------------------------------------------------------------------
| GET SUBJECT
|--------------------------------------------------------------------------
*/
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) { header("Location: school-subjects.php"); exit; }

$stmt = mysqli_prepare($conn, "SELECT * FROM subjects WHERE id = ? AND school_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "ii", $id, $school_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($res) === 0) {
    header("Location: school-subjects.php?error=" . urlencode("Subject not found."));
    exit;
}

$subject = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

$original = $subject;

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
| FETCH TEACHERS (grouped by level)
|--------------------------------------------------------------------------
*/
$teachers_all = [];
$teachers_by_level = [];

$t_stmt = mysqli_prepare($conn, "
    SELECT id, name, teaching_level
    FROM teachers
    WHERE school_id = ? AND status = 'active'
    ORDER BY name ASC
");
if ($t_stmt) {
    mysqli_stmt_bind_param($t_stmt, "i", $school_id);
    mysqli_stmt_execute($t_stmt);
    $t_res = mysqli_stmt_get_result($t_stmt);
    while ($t = mysqli_fetch_assoc($t_res)) {
        $teachers_all[] = $t;
        $levels = array_filter(array_map('trim', explode(',', (string)$t['teaching_level'])));
        foreach ($levels as $lv) {
            if (!isset($teachers_by_level[$lv])) $teachers_by_level[$lv] = [];
            $teachers_by_level[$lv][] = [
                'id'   => (int)$t['id'],
                'name' => $t['name'],
            ];
        }
    }
    mysqli_stmt_close($t_stmt);
}

/*
|--------------------------------------------------------------------------
| FETCH CLASSES
|--------------------------------------------------------------------------
*/
$classes_all = [];
$c_stmt = mysqli_prepare($conn, "
    SELECT id, class_name, level, room
    FROM classes
    WHERE school_id = ? AND status = 'active'
    ORDER BY FIELD(level,'Nursery','Primary','O_Level','A_Level'), class_name ASC
");
if ($c_stmt) {
    mysqli_stmt_bind_param($c_stmt, "i", $school_id);
    mysqli_stmt_execute($c_stmt);
    $c_res = mysqli_stmt_get_result($c_stmt);
    while ($c = mysqli_fetch_assoc($c_res)) {
        $classes_all[] = $c;
    }
    mysqli_stmt_close($c_stmt);
}

/*
|--------------------------------------------------------------------------
| FETCH EXISTING ASSIGNMENTS (classes, teachers, numbers)
|--------------------------------------------------------------------------
*/
$existing_classes   = [];   // [class_id => true]
$existing_teachers  = [];   // [class_id => teacher_id]
$existing_credits   = [];   // [class_id => credits]
$existing_hours     = [];   // [class_id => week_hours]
$existing_marks     = [];   // [class_id => max_marks]

$sc_stmt = mysqli_prepare($conn, "
    SELECT sc.class_id, sc.credits, sc.week_hours, sc.max_marks,
           sct.teacher_id
    FROM subject_classes sc
    LEFT JOIN subject_class_teachers sct
           ON sct.subject_id = sc.subject_id
          AND sct.class_id = sc.class_id
    WHERE sc.school_id = ? AND sc.subject_id = ?
");
if ($sc_stmt) {
    mysqli_stmt_bind_param($sc_stmt, "ii", $school_id, $id);
    mysqli_stmt_execute($sc_stmt);
    $sc_res = mysqli_stmt_get_result($sc_stmt);
    while ($row = mysqli_fetch_assoc($sc_res)) {
        $cid = (int)$row['class_id'];
        $existing_classes[$cid]  = true;
        $existing_teachers[$cid] = (int)($row['teacher_id'] ?? 0);
        $existing_credits[$cid]  = $row['credits'];
        $existing_hours[$cid]    = $row['week_hours'];
        $existing_marks[$cid]    = $row['max_marks'];
    }
    mysqli_stmt_close($sc_stmt);
}

/*
|--------------------------------------------------------------------------
| FORM STATE
|--------------------------------------------------------------------------
*/
$f = [
    'subject_name' => $subject['subject_name'],
    'subject_code' => (string)($subject['subject_code'] ?? ''),
    'description'  => (string)($subject['description'] ?? ''),
    'status'       => $subject['status'],
];

$selected_classes = $existing_classes;
$class_teachers   = $existing_teachers;
$class_credits    = $existing_credits;
$class_hours      = $existing_hours;
$class_marks      = $existing_marks;

$message      = "";
$message_type = "";

/*
|--------------------------------------------------------------------------
| HANDLE SUBMIT
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $f['subject_name'] = trim($_POST['subject_name'] ?? '');
    $f['subject_code'] = trim($_POST['subject_code'] ?? '');
    $f['description']  = trim($_POST['description']  ?? '');
    $f['status']       = trim($_POST['status']       ?? 'active');

    /* Reset and rebuild maps from POST */
    $selected_classes = [];
    $class_teachers   = [];
    $class_credits    = [];
    $class_hours      = [];
    $class_marks      = [];

    if (isset($_POST['class_ids']) && is_array($_POST['class_ids'])) {
        foreach ($_POST['class_ids'] as $cid) {
            $cid = (int)$cid;
            if ($cid > 0) $selected_classes[$cid] = true;
        }
    }
    if (isset($_POST['class_teacher']) && is_array($_POST['class_teacher'])) {
        foreach ($_POST['class_teacher'] as $cid => $tid) {
            $cid = (int)$cid; $tid = (int)$tid;
            if ($cid > 0) $class_teachers[$cid] = $tid > 0 ? $tid : 0;
        }
    }
    if (isset($_POST['class_credits']) && is_array($_POST['class_credits'])) {
        foreach ($_POST['class_credits'] as $cid => $v) {
            $cid = (int)$cid;
            if ($cid > 0) $class_credits[$cid] = trim((string)$v);
        }
    }
    if (isset($_POST['class_week_hours']) && is_array($_POST['class_week_hours'])) {
        foreach ($_POST['class_week_hours'] as $cid => $v) {
            $cid = (int)$cid;
            if ($cid > 0) $class_hours[$cid] = trim((string)$v);
        }
    }
    if (isset($_POST['class_max_marks']) && is_array($_POST['class_max_marks'])) {
        foreach ($_POST['class_max_marks'] as $cid => $v) {
            $cid = (int)$cid;
            if ($cid > 0) $class_marks[$cid] = trim((string)$v);
        }
    }

    $errors = [];

    if ($f['subject_name'] === '' || mb_strlen($f['subject_name']) < 2) {
        $errors[] = "Subject name is required (at least 2 characters).";
    }
    if (!in_array($f['status'], ['active','inactive'], true)) {
        $errors[] = "Please select a valid status.";
    }

    /* Per-class number validation */
    foreach (array_keys($selected_classes) as $cid) {
        if (isset($class_credits[$cid]) && $class_credits[$cid] !== '') {
            if (!is_numeric($class_credits[$cid]) || (float)$class_credits[$cid] < 0 || (float)$class_credits[$cid] > 100) {
                $errors[] = "Credits must be between 0 and 100.";
                break;
            }
        }
        if (isset($class_hours[$cid]) && $class_hours[$cid] !== '') {
            if (!is_numeric($class_hours[$cid]) || (float)$class_hours[$cid] < 0 || (float)$class_hours[$cid] > 168) {
                $errors[] = "Week hours must be between 0 and 168.";
                break;
            }
        }
        if (isset($class_marks[$cid]) && $class_marks[$cid] !== '') {
            if (!ctype_digit((string)$class_marks[$cid]) || (int)$class_marks[$cid] < 1 || (int)$class_marks[$cid] > 1000) {
                $errors[] = "Max marks must be between 1 and 1000.";
                break;
            }
        }
    }

    /* Validate selected classes belong to this school */
    if (!empty($selected_classes)) {
        $ids = array_keys($selected_classes);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types_c = "i" . str_repeat('i', count($ids));
        $params_c = array_merge([$school_id], $ids);

        $cchk = mysqli_prepare($conn, "SELECT id FROM classes WHERE school_id = ? AND id IN ($placeholders)");
        if ($cchk) {
            mysqli_stmt_bind_param($cchk, $types_c, ...$params_c);
            mysqli_stmt_execute($cchk);
            $cchk_res = mysqli_stmt_get_result($cchk);
            $valid = [];
            while ($row = mysqli_fetch_assoc($cchk_res)) $valid[] = (int)$row['id'];
            mysqli_stmt_close($cchk);

            $invalid = array_diff($ids, $valid);
            if (!empty($invalid)) {
                $errors[] = "One or more selected classes do not belong to your school.";
            }
        }
    }

    /* Validate per-class teachers (level match) */
    if (empty($errors) && !empty($class_teachers) && !empty($selected_classes)) {

        $ids = array_keys($selected_classes);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types_c = "i" . str_repeat('i', count($ids));
        $params_c = array_merge([$school_id], $ids);

        $class_levels = [];
        $cchk = mysqli_prepare($conn, "SELECT id, level FROM classes WHERE school_id = ? AND id IN ($placeholders)");
        if ($cchk) {
            mysqli_stmt_bind_param($cchk, $types_c, ...$params_c);
            mysqli_stmt_execute($cchk);
            $cchk_res = mysqli_stmt_get_result($cchk);
            while ($row = mysqli_fetch_assoc($cchk_res)) {
                $class_levels[(int)$row['id']] = $row['level'];
            }
            mysqli_stmt_close($cchk);
        }

        $teacher_ids = array_values(array_filter($class_teachers, fn($v) => $v > 0));
        if (!empty($teacher_ids)) {
            $teacher_ids = array_values(array_unique($teacher_ids));
            $placeholders_t = implode(',', array_fill(0, count($teacher_ids), '?'));
            $types_t = "i" . str_repeat('i', count($teacher_ids));
            $params_t = array_merge([$school_id], $teacher_ids);

            $teacher_levels = [];
            $tchk = mysqli_prepare($conn, "SELECT id, teaching_level FROM teachers WHERE school_id = ? AND id IN ($placeholders_t)");
            if ($tchk) {
                mysqli_stmt_bind_param($tchk, $types_t, ...$params_t);
                mysqli_stmt_execute($tchk);
                $tchk_res = mysqli_stmt_get_result($tchk);
                while ($row = mysqli_fetch_assoc($tchk_res)) {
                    $lvs = array_filter(array_map('trim', explode(',', (string)$row['teaching_level'])));
                    $teacher_levels[(int)$row['id']] = $lvs;
                }
                mysqli_stmt_close($tchk);
            }

            foreach ($class_teachers as $cid => $tid) {
                if ($tid <= 0) continue;
                $class_lv = $class_levels[$cid] ?? null;
                $t_lvs    = $teacher_levels[$tid] ?? [];
                if ($class_lv && !in_array($class_lv, $t_lvs, true)) {
                    $errors[] = "A selected teacher does not teach the level of one of the chosen classes.";
                    break;
                }
            }
        }
    }

    /* Duplicate name check */
    if (empty($errors)) {
        $chk2 = mysqli_prepare($conn, "SELECT id FROM subjects WHERE school_id = ? AND subject_name = ? AND id <> ? LIMIT 1");
        mysqli_stmt_bind_param($chk2, "isi", $school_id, $f['subject_name'], $id);
        mysqli_stmt_execute($chk2);
        $chk2_res = mysqli_stmt_get_result($chk2);
        if (mysqli_num_rows($chk2_res) > 0) {
            $errors[] = "Another subject with this name already exists in your school.";
        }
        mysqli_stmt_close($chk2);
    }

    if (!empty($errors)) {
        $message = implode(" ", $errors);
        $message_type = "error";
    } else {

        $subject_code = ($f['subject_code'] === '') ? null : $f['subject_code'];

        mysqli_begin_transaction($conn);

        try {

            /* 1. Update subject row (name, code, description, status) */
            $upd = mysqli_prepare($conn, "
                UPDATE subjects
                SET subject_name = ?, subject_code = ?, description = ?, status = ?
                WHERE id = ? AND school_id = ?
            ");
            if (!$upd) throw new Exception(mysqli_error($conn));

            mysqli_stmt_bind_param($upd, "ssssii",
                $f['subject_name'], $subject_code, $f['description'], $f['status'], $id, $school_id
            );
            if (!mysqli_stmt_execute($upd)) {
                $err = mysqli_error($conn);
                mysqli_stmt_close($upd);
                throw new Exception($err);
            }
            mysqli_stmt_close($upd);

            /* 2. Delete all existing class assignments (both tables) */
            $del1 = mysqli_prepare($conn, "DELETE FROM subject_classes WHERE school_id = ? AND subject_id = ?");
            if ($del1) {
                mysqli_stmt_bind_param($del1, "ii", $school_id, $id);
                mysqli_stmt_execute($del1);
                mysqli_stmt_close($del1);
            }
            $del2 = mysqli_prepare($conn, "DELETE FROM subject_class_teachers WHERE school_id = ? AND subject_id = ?");
            if ($del2) {
                mysqli_stmt_bind_param($del2, "ii", $school_id, $id);
                mysqli_stmt_execute($del2);
                mysqli_stmt_close($del2);
            }

            /* 3. Re-insert new assignments */
            $gap_classes = [];

            if (!empty($selected_classes)) {
                $sc_stmt = mysqli_prepare($conn, "
                    INSERT INTO subject_classes
                        (school_id, subject_id, class_id, credits, week_hours, max_marks)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $sct_stmt = mysqli_prepare($conn, "
                    INSERT INTO subject_class_teachers (school_id, subject_id, class_id, teacher_id)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE teacher_id = VALUES(teacher_id), assigned_at = CURRENT_TIMESTAMP
                ");

                foreach (array_keys($selected_classes) as $cid) {
                    $credits_v = isset($class_credits[$cid]) && $class_credits[$cid] !== '' ? (float)$class_credits[$cid] : null;
                    $week_v    = isset($class_hours[$cid])   && $class_hours[$cid]   !== '' ? (float)$class_hours[$cid]   : null;
                    $marks_v   = isset($class_marks[$cid])   && $class_marks[$cid]   !== '' ? (int)$class_marks[$cid]     : null;

                    if ($sc_stmt) {
                        mysqli_stmt_bind_param($sc_stmt, "iiiddi", $school_id, $id, $cid, $credits_v, $week_v, $marks_v);
                        mysqli_stmt_execute($sc_stmt);
                    }

                    $tid = (int)($class_teachers[$cid] ?? 0);
                    if ($sct_stmt) {
                        $teacher_val = $tid > 0 ? $tid : null;
                        mysqli_stmt_bind_param($sct_stmt, "iiii", $school_id, $id, $cid, $teacher_val);
                        mysqli_stmt_execute($sct_stmt);
                    }

                    if ($tid <= 0) $gap_classes[] = $cid;
                }

                if ($sc_stmt)  mysqli_stmt_close($sc_stmt);
                if ($sct_stmt) mysqli_stmt_close($sct_stmt);
            }

            mysqli_commit($conn);

            $qs = "updated=1";
            if (!empty($gap_classes)) $qs .= "&gaps=" . count($gap_classes);
            header("Location: school-subjects.php?" . $qs);
            exit;

        } catch (Exception $e) {
            mysqli_rollback($conn);
            $message = "Could not update subject: " . $e->getMessage();
            $message_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Subject | Educ Bridge</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8; --primary-light: #dbeafe;
            --success: #10b981; --success-light: rgba(16,185,129,0.1);
            --danger: #ef4444; --danger-light: rgba(239,68,68,0.1);
            --warning: #f59e0b; --warning-light: rgba(245,158,11,0.12);
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
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.08);
            --shadow-sidebar: 0 0 30px rgba(0,0,0,0.05);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        [data-theme="dark"] {
            --primary: #3b82f6; --primary-dark: #2563eb; --primary-light: #1e3a5f;
            --success: #34d399; --success-light: rgba(16,185,129,0.15);
            --danger: #f87171; --danger-light: rgba(239,68,68,0.15);
            --warning: #fbbf24; --warning-light: rgba(245,158,11,0.18);
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

        .back-link { display:inline-flex; align-items:center; gap:8px; color:var(--primary); text-decoration:none; font-size:14px; font-weight:600; margin-bottom:18px; transition:var(--transition); }
        .back-link:hover { color:var(--primary-dark); gap:11px; }

        .alert { padding:13px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; display:flex; align-items:flex-start; gap:10px; line-height:1.5; }
        .alert-success { background:var(--success-light); color:#059669; border:1px solid rgba(16,185,129,0.25); }
        [data-theme="dark"] .alert-success { color:#34d399; }
        .alert-error { background:var(--danger-light); color:#dc2626; border:1px solid rgba(239,68,68,0.25); }
        [data-theme="dark"] .alert-error { color:#f87171; }

        .card { max-width:980px; margin:0 auto; background:var(--bg-card); border:1px solid var(--border-color); border-radius:20px; box-shadow:var(--shadow-lg); overflow:hidden; }
        .card-head { padding:26px 30px; border-bottom:1px solid var(--border-color); background:linear-gradient(135deg, rgba(37,99,235,0.06), rgba(124,58,237,0.04)); }
        .card-head h2 { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; display:flex; align-items:center; gap:10px; margin-bottom:6px; }
        .card-head h2 i { color:var(--primary); }
        .card-head p { font-size:14px; color:var(--text-muted); }
        .card-body { padding:28px 30px; }

        .section-title { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.7px; color:var(--text-muted); margin:4px 0 14px; padding-bottom:8px; border-bottom:1px dashed var(--border-color); display:flex; align-items:center; gap:8px; }
        .section-title i { color:var(--primary); font-size:12px; }
        .section-title .count-badge {
            margin-left:auto;
            background: var(--primary-light);
            color: var(--primary);
            font-size: 10.5px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 50px;
        }

        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
        .form-group { margin-bottom:18px; }
        .form-group label { display:block; font-size:13.5px; font-weight:600; color:var(--text-primary); margin-bottom:8px; }
        .form-group label .required { color:#ef4444; }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 13px 15px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-alt);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            font-size: 14.5px;
            outline: none;
            transition: var(--transition);
        }
        .form-group textarea { resize:vertical; min-height:90px; }
        .form-group select { cursor:pointer; }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
            background: var(--bg-card);
        }
        .form-help { font-size:12.5px; color:var(--text-muted); margin-top:6px; line-height:1.5; }

        .radio-pills { display:flex; flex-wrap:wrap; gap:10px; }
        .radio-pill { position:relative; display:inline-flex; align-items:center; gap:8px; padding:12px 18px; border:1.5px solid var(--border-color); border-radius:10px; cursor:pointer; transition:var(--transition); background:var(--bg-alt); font-size:13.5px; font-weight:600; color:var(--text-primary); }
        .radio-pill input { position:absolute; opacity:0; pointer-events:none; }
        .radio-pill .dot { width:16px; height:16px; border-radius:50%; border:2px solid var(--border-color); position:relative; transition:var(--transition); flex-shrink:0; }
        .radio-pill .dot::after { content:''; position:absolute; inset:3px; border-radius:50%; background:var(--primary); opacity:0; transform:scale(0.4); transition:var(--transition); }
        .radio-pill:hover { border-color:var(--primary); }
        .radio-pill.selected { border-color:var(--primary); background:var(--primary-light); }
        .radio-pill.selected .dot { border-color:var(--primary); }
        .radio-pill.selected .dot::after { opacity:1; transform:scale(1); }

        /* Class picker toolbar */
        .class-picker-toolbar {
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 12px; flex-wrap: wrap;
        }
        .class-picker-search {
            position: relative; flex: 1 1 200px; min-width: 180px;
        }
        .class-picker-search i {
            position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
            color: var(--text-muted); font-size: 13px; pointer-events: none;
        }
        .class-picker-search input {
            width: 100%; padding: 10px 14px 10px 36px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-alt);
            color: var(--text-primary);
            font-family: inherit;
            font-size: 13.5px;
            outline: none;
            transition: var(--transition);
        }
        .class-picker-search input:focus {
            border-color: var(--primary);
            background: var(--bg-card);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .class-picker-tools {
            display: flex; gap: 6px; flex-wrap: wrap;
        }
        .class-picker-tools button {
            padding: 9px 14px;
            border-radius: 8px;
            border: 1.5px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-secondary);
            font-family: inherit;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex; align-items: center; gap: 6px;
        }
        .class-picker-tools button:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--bg-alt);
        }

        .class-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            padding: 6px 4px 6px 6px;
        }
        .level-group-title {
            font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:0.6px;
            color:var(--text-muted);padding:8px 4px 6px;
        }
        .level-group-title i { color:var(--primary); margin-right:6px; }

        /* ===== Class + teacher + numbers card ===== */
        .class-card {
            border: 1.5px solid var(--border-color);
            border-radius: 14px;
            background: var(--bg-card);
            overflow: hidden;
            transition: var(--transition);
        }
        .class-card.selected {
            border-color: var(--primary);
            background: linear-gradient(180deg, rgba(37,99,235,0.04), transparent 60%);
        }
        [data-theme="dark"] .class-card.selected {
            background: linear-gradient(180deg, rgba(59,130,246,0.10), transparent 60%);
        }
        .class-card.unassigned {
            border-color: rgba(245,158,11,0.5);
        }

        .class-card-head {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
        }
        .class-card-head .cc-check {
            position: relative;
            display: inline-flex; align-items: center; gap: 12px;
            cursor: pointer; user-select: none; flex: 1;
        }
        .class-card-head .cc-check input { position: absolute; opacity: 0; pointer-events: none; }
        .class-card-head .co-box {
            width: 20px; height: 20px;
            border-radius: 6px;
            border: 2px solid var(--border-color);
            background: var(--bg-card);
            display: flex; align-items: center; justify-content: center;
            transition: var(--transition);
            flex-shrink: 0;
        }
        .class-card-head .co-box i {
            font-size: 10px; color: #fff;
            opacity: 0; transform: scale(0.4);
            transition: var(--transition);
        }
        .class-card.selected .co-box {
            background: var(--primary);
            border-color: var(--primary);
        }
        .class-card.selected .co-box i {
            opacity: 1; transform: scale(1);
        }
        .class-card-head .co-info { flex: 1; min-width: 0; }
        .class-card-head .co-name {
            font-size: 14.5px; font-weight: 700; color: var(--text-primary);
            margin-bottom: 3px;
        }
        .class-card-head .co-meta {
            font-size: 11.5px; color: var(--text-muted);
            display: flex; gap: 8px; flex-wrap: wrap;
        }
        .class-card-head .co-meta .tag {
            display: inline-block;
            padding: 1.5px 8px;
            border-radius: 50px;
            font-size: 10.5px; font-weight: 700;
            background: var(--bg-alt);
            color: var(--text-secondary);
        }
        .class-card-head .co-meta .tag.blue   { background: rgba(37,99,235,0.12); color: #2563eb; }
        .class-card-head .co-meta .tag.green  { background: rgba(16,185,129,0.12); color: #059669; }
        .class-card-head .co-meta .tag.orange { background: rgba(245,158,11,0.15); color: #b45309; }
        .class-card-head .co-meta .tag.pink   { background: rgba(236,72,153,0.12); color: #db2777; }

        .class-card-body {
            display: none;
            padding: 0 16px 16px 16px;
            border-top: 1px dashed var(--border-color);
            margin-top: 4px;
        }
        .class-card.selected .class-card-body {
            display: block;
        }

        .cc-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            padding-top: 14px;
        }
        .cc-grid .field label {
            display: block;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .cc-grid .field input,
        .cc-grid .field select {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-alt);
            color: var(--text-primary);
            font-family: inherit;
            font-size: 13.5px;
            outline: none;
            transition: var(--transition);
        }
        .cc-grid .field input:focus,
        .cc-grid .field select:focus {
            border-color: var(--primary);
            background: var(--bg-card);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .cc-grid .field.teacher {
            grid-column: 1 / -1;
        }
        .teacher-line {
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
        }
        .teacher-line .field-select {
            flex: 1 1 220px; min-width: 180px;
        }
        .teacher-line .no-teacher-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 9px 12px;
            border-radius: 9px;
            border: 1.5px dashed rgba(245,158,11,0.5);
            background: rgba(245,158,11,0.10);
            color: #b45309;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
            transition: var(--transition);
            cursor: pointer;
            font-family: inherit;
        }
        [data-theme="dark"] .teacher-line .no-teacher-btn { color: #fbbf24; background: rgba(245,158,11,0.15); }
        .teacher-line .no-teacher-btn:hover {
            background: #f59e0b;
            color: #fff;
            border-color: #f59e0b;
        }

        .no-classes-warn {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 14px 16px;
            background: var(--warning-light);
            border: 1px dashed rgba(245,158,11,0.4);
            border-radius: 12px;
            color: #b45309;
            font-size: 13.5px;
            line-height: 1.5;
        }
        [data-theme="dark"] .no-classes-warn { color: #fbbf24; }
        .no-classes-warn i { font-size: 16px; flex-shrink: 0; margin-top: 1px; }
        .no-classes-warn a { color: inherit; font-weight: 700; text-decoration: underline; }

        .gap-summary {
            padding: 12px 16px;
            background: var(--bg-alt);
            border: 1px dashed var(--border-color);
            border-radius: 10px;
            font-size: 12.5px;
            color: var(--text-muted);
            margin-top: 12px;
            display: none;
        }
        .gap-summary.show { display: block; }
        .gap-summary strong { color: var(--text-primary); }

        .form-actions { display:flex; gap:12px; justify-content:flex-end; padding-top:20px; border-top:1px solid var(--border-color); margin-top:12px; flex-wrap:wrap; }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:13px 24px; border-radius:10px; font-size:14.5px; font-weight:600; text-decoration:none; cursor:pointer; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-primary); transition:var(--transition); font-family:'Inter',sans-serif; }
        .btn:hover { border-color:var(--primary); color:var(--primary); transform:translateY(-1px); }
        .btn.primary { background:var(--primary); color:#fff; border-color:var(--primary); box-shadow:0 6px 18px rgba(37,99,235,0.32); }
        .btn.primary:hover { background:var(--primary-dark); color:#fff; }
        .btn:disabled { opacity:0.7; cursor:not-allowed; transform:none; }

        .overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:90; backdrop-filter:blur(2px); }
        .overlay.show { display:block; }

        /* ============== MODAL ============== */
        .modal-overlay {
            position: fixed; inset: 0;
            background: rgba(15,23,42,0.65);
            backdrop-filter: blur(4px);
            z-index: 200;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow-y: auto;
        }
        .modal-overlay.show { display: flex; }
        .modal {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            box-shadow: var(--shadow-lg);
            max-width: 640px;
            width: 100%;
            max-height: 92vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            animation: modalIn 0.25s ease;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.96) translateY(10px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-head {
            padding: 22px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex; align-items: flex-start; gap: 14px;
            background: linear-gradient(135deg, rgba(245,158,11,0.08), rgba(239,68,68,0.04));
        }
        .modal-head .mh-icon {
            width: 46px; height: 46px; border-radius: 12px;
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 20px; flex-shrink: 0;
        }
        .modal-head .mh-text { flex: 1; min-width: 0; }
        .modal-head .mh-text h3 {
            font-family: 'Poppins', sans-serif;
            font-size: 17px; font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
        }
        .modal-head .mh-text p {
            font-size: 13.5px; color: var(--text-muted); line-height: 1.55;
        }
        .modal-head .mh-close {
            width: 36px; height: 36px; border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-secondary);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 14px;
            transition: var(--transition);
            flex-shrink: 0;
        }
        .modal-head .mh-close:hover {
            background: var(--danger-light);
            color: var(--danger);
            border-color: rgba(239,68,68,0.3);
        }

        .modal-body {
            padding: 22px 24px;
            overflow-y: auto;
            flex: 1;
        }
        .modal-body .report-line {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 14px;
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            margin-bottom: 8px;
        }
        .modal-body .report-line .rl-icon {
            width: 34px; height: 34px; border-radius: 9px;
            background: var(--primary-light);
            color: var(--primary);
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; flex-shrink: 0;
        }
        .modal-body .report-line .rl-text { flex: 1; min-width: 0; }
        .modal-body .report-line .rl-text .t {
            font-size: 13.5px; font-weight: 700; color: var(--text-primary);
        }
        .modal-body .report-line .rl-text .s {
            font-size: 12px; color: var(--text-muted); margin-top: 2px;
        }
        .modal-body .report-line .rl-count {
            font-size: 11.5px; font-weight: 700;
            padding: 3px 10px; border-radius: 50px;
            background: var(--warning-light);
            color: #b45309;
            white-space: nowrap;
        }
        [data-theme="dark"] .modal-body .report-line .rl-count { color: #fbbf24; }

        .modal-body .report-note {
            padding: 12px 14px;
            background: var(--bg-alt);
            border-left: 3px solid var(--primary);
            border-radius: 8px;
            font-size: 12.5px;
            color: var(--text-secondary);
            line-height: 1.6;
            margin-top: 14px;
        }

        .modal-foot {
            padding: 16px 24px;
            border-top: 1px solid var(--border-color);
            background: var(--bg-alt);
            display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end;
        }
        .modal-foot .btn { padding: 11px 18px; font-size: 13.5px; }
        .modal-foot .btn.copy { border-color: var(--border-color); }

        @media (max-width: 900px) {
            .sidebar { transform:translateX(-100%); }
            .sidebar.open { transform:translateX(0); box-shadow:0 0 40px rgba(0,0,0,0.2); }
            .main { margin-left:0; }
            .hamburger { display:flex; }
        }
        @media (max-width: 700px) {
            .form-row { grid-template-columns:1fr; }
        }
        @media (max-width: 600px) {
            .content { padding:20px 16px; }
            .topbar { padding:12px 16px; }
            .page-title { font-size:17px; }
            .card-body { padding:22px 20px; }
            .card-head { padding:22px 20px; }
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
            <a href="school-subjects.php" class="nav-item active"><i class="fas fa-book nav-icon"></i> Subjects</a>
            <div class="nav-section-title">Communication</div>
            <a href="school-announcements.php" class="nav-item"><i class="fas fa-bullhorn nav-icon"></i> Announcements</a>
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
                    <div class="page-title">Edit Subject</div>
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

            <a href="school-subjects.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Subjects</a>

            <?php if ($message !== ""): ?>
                <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                    <i class="fas <?= $message_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-head">
                    <h2><i class="fas fa-pen"></i> Edit Subject</h2>
                    <p>Update the details for <strong><?= htmlspecialchars($subject['subject_name']); ?></strong> and manage classes &amp; teachers.</p>
                </div>
                <div class="card-body">

                    <form method="POST" action="school-edit-subject.php?id=<?= (int)$id; ?>" id="subjectForm" autocomplete="off">

                        <div class="section-title"><i class="fas fa-book"></i> Subject Information</div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="subject_name">Subject Name <span class="required">*</span></label>
                                <input type="text" id="subject_name" name="subject_name" maxlength="150"
                                       placeholder="e.g. Mathematics"
                                       value="<?= htmlspecialchars($f['subject_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="subject_code">Subject Code (optional)</label>
                                <input type="text" id="subject_code" name="subject_code" maxlength="40"
                                       placeholder="e.g. MAT101"
                                       value="<?= htmlspecialchars($f['subject_code']); ?>">
                            </div>
                        </div>

                        <div class="section-title" style="margin-top:14px;">
                            <i class="fas fa-layer-group"></i>
                            Classes &amp; Teachers
                            <span class="count-badge" id="classCountBadge">0 selected</span>
                        </div>

                        <?php if (empty($classes_all)): ?>
                            <div class="no-classes-warn">
                                <i class="fas fa-exclamation-triangle"></i>
                                <div>
                                    You haven't created any active classes yet. You can still edit the subject now and assign classes later, or
                                    <a href="school-add-class.php" target="_blank">create a class first</a>.
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="class-picker-toolbar">
                                <div class="class-picker-search">
                                    <i class="fas fa-search"></i>
                                    <input type="text" id="classSearch" placeholder="Search class name or room...">
                                </div>
                                <div class="class-picker-tools">
                                    <button type="button" id="clearAll"><i class="fas fa-times"></i> Clear selection</button>
                                </div>
                            </div>

                            <div class="class-grid" id="classGrid">
                                <?php
                                    $by_level = [];
                                    foreach ($classes_all as $c) {
                                        $by_level[$c['level']][] = $c;
                                    }
                                    $render_order = !empty($valid_level_enums) ? $valid_level_enums : array_keys($by_level);
                                    foreach ($render_order as $lv_enum):
                                        if (empty($by_level[$lv_enum])) continue;
                                ?>
                                    <div class="level-group-title">
                                        <i class="fas fa-graduation-cap"></i>
                                        <?= htmlspecialchars($level_map[$lv_enum] ?? $lv_enum); ?>
                                    </div>
                                    <?php foreach ($by_level[$lv_enum] as $c):
                                        $cid = (int)$c['id'];
                                        $checked = !empty($selected_classes[$cid]);
                                        $lv_col = $level_colors[$lv_enum] ?? 'gray';
                                        $avail_teachers = $teachers_by_level[$lv_enum] ?? [];
                                        $chosen_tid     = (int)($class_teachers[$cid] ?? 0);
                                        $credits_v      = ($class_credits[$cid] ?? null) === null ? '' : (string)$class_credits[$cid];
                                        $hours_v        = ($class_hours[$cid]   ?? null) === null ? '' : (string)$class_hours[$cid];
                                        $marks_v        = ($class_marks[$cid]   ?? null) === null ? '' : (string)$class_marks[$cid];
                                    ?>
                                        <div class="class-card <?= $checked ? 'selected' : '' ?> <?= ($checked && $chosen_tid === 0) ? 'unassigned' : '' ?>"
                                             data-level="<?= htmlspecialchars($lv_enum); ?>"
                                             data-cid="<?= $cid; ?>"
                                             data-classname="<?= htmlspecialchars($c['class_name']); ?>">
                                            <div class="class-card-head">
                                                <label class="cc-check">
                                                    <input type="checkbox" name="class_ids[]" value="<?= $cid; ?>" <?= $checked ? 'checked' : '' ?>>
                                                    <span class="co-box"><i class="fas fa-check"></i></span>
                                                    <div class="co-info">
                                                        <div class="co-name"><?= htmlspecialchars($c['class_name']); ?></div>
                                                        <div class="co-meta">
                                                            <span class="tag <?= $lv_col; ?>"><?= htmlspecialchars($level_map[$lv_enum] ?? $lv_enum); ?></span>
                                                            <?php if (!empty($c['room'])): ?>
                                                                <span>· <?= htmlspecialchars($c['room']); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </label>
                                            </div>

                                            <div class="class-card-body">
                                                <div class="cc-grid">
                                                    <div class="field">
                                                        <label>Credits</label>
                                                        <input type="number" step="0.1" min="0" max="100"
                                                               name="class_credits[<?= $cid; ?>]"
                                                               value="<?= htmlspecialchars($credits_v); ?>"
                                                               placeholder="e.g. 3.5">
                                                    </div>
                                                    <div class="field">
                                                        <label>Week Hours</label>
                                                        <input type="number" step="0.5" min="0" max="168"
                                                               name="class_week_hours[<?= $cid; ?>]"
                                                               value="<?= htmlspecialchars($hours_v); ?>"
                                                               placeholder="e.g. 6">
                                                    </div>
                                                    <div class="field">
                                                        <label>Max Marks</label>
                                                        <input type="number" min="1" max="1000"
                                                               name="class_max_marks[<?= $cid; ?>]"
                                                               value="<?= htmlspecialchars($marks_v); ?>"
                                                               placeholder="e.g. 100">
                                                    </div>
                                                    <div class="field teacher">
                                                        <label>Teacher for this class</label>
                                                        <div class="teacher-line">
                                                            <select name="class_teacher[<?= $cid; ?>]" class="class-teacher-select field-select <?= $chosen_tid === 0 ? 'unassigned' : '' ?>">
                                                                <option value="0">— No teacher yet —</option>
                                                                <?php foreach ($avail_teachers as $t): ?>
                                                                    <option value="<?= (int)$t['id']; ?>" <?= $chosen_tid === (int)$t['id'] ? 'selected' : '' ?>>
                                                                        <?= htmlspecialchars($t['name']); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>

                                                            <?php if (empty($avail_teachers)): ?>
                                                                <button type="button"
                                                                        class="no-teacher-btn open-report"
                                                                        data-cid="<?= $cid; ?>"
                                                                        data-cname="<?= htmlspecialchars($c['class_name']); ?>"
                                                                        data-level="<?= htmlspecialchars($lv_enum); ?>"
                                                                        data-lvlabel="<?= htmlspecialchars($level_map[$lv_enum] ?? $lv_enum); ?>">
                                                                    <i class="fas fa-plus"></i> No teacher available
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </div>

                            <div class="gap-summary" id="gapSummary"></div>
                        <?php endif; ?>

                        <div class="section-title" style="margin-top:20px;"><i class="fas fa-align-left"></i> Description (optional)</div>

                        <div class="form-group">
                            <textarea id="description" name="description" maxlength="1000"
                                      placeholder="Short description of the subject..."><?= htmlspecialchars($f['description']); ?></textarea>
                        </div>

                        <div class="section-title" style="margin-top:14px;"><i class="fas fa-toggle-on"></i> Status</div>

                        <div class="form-group">
                            <div class="radio-pills" data-group="status">
                                <label class="radio-pill <?= $f['status'] === 'active'   ? 'selected' : ''; ?>">
                                    <input type="radio" name="status" value="active"   <?= $f['status'] === 'active'   ? 'checked' : ''; ?> required>
                                    <span class="dot"></span> Active
                                </label>
                                <label class="radio-pill <?= $f['status'] === 'inactive' ? 'selected' : ''; ?>">
                                    <input type="radio" name="status" value="inactive" <?= $f['status'] === 'inactive' ? 'checked' : ''; ?> required>
                                    <span class="dot"></span> Inactive
                                </label>
                            </div>
                        </div>

                        <div class="form-actions">
                            <a href="school-subjects.php" class="btn"><i class="fas fa-times"></i> Cancel</a>
                            <button type="submit" class="btn primary" id="saveBtn">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>

                    </form>

                </div>
            </div>

        </main>
    </div>
</div>

<!-- REPORT MODAL -->
<div class="modal-overlay" id="reportModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal-head">
            <div class="mh-icon"><i class="fas fa-user-plus"></i></div>
            <div class="mh-text">
                <h3>Teachers Needed</h3>
                <p>Some classes don't have a teacher yet. Share this report with the school leader so they can hire teachers for these positions.</p>
            </div>
            <button type="button" class="mh-close" id="reportClose" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" id="reportBody"></div>
        <div class="modal-foot">
            <button type="button" class="btn copy" id="reportCopyBtn">
                <i class="fas fa-copy"></i> Copy report
            </button>
            <a href="school-add-teacher.php" target="_blank" class="btn primary">
                <i class="fas fa-plus"></i> Add a Teacher now
            </a>
        </div>
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
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');
        function openSidebar() { sidebar.classList.add('open'); overlay.classList.add('show'); }
        function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('show'); }
        hamburger.addEventListener('click', function () {
            if (sidebar.classList.contains('open')) closeSidebar(); else openSidebar();
        });
        overlay.addEventListener('click', closeSidebar);
    })();

    // RADIO PILLS
    document.querySelectorAll('.radio-pills').forEach(function (group) {
        group.querySelectorAll('.radio-pill').forEach(function (pill) {
            pill.addEventListener('click', function () {
                group.querySelectorAll('.radio-pill').forEach(p => p.classList.remove('selected'));
                pill.classList.add('selected');
                const radio = pill.querySelector('input');
                if (radio) radio.checked = true;
            });
        });
    });

    // CLASS CARD TOGGLE
    document.querySelectorAll('#classGrid .class-card').forEach(function (card) {
        const cb = card.querySelector('input[type="checkbox"]');
        if (!cb) return;
        const label = card.querySelector('.cc-check');
        label.addEventListener('click', function (e) {
            setTimeout(function () {
                card.classList.toggle('selected', cb.checked);
                if (cb.checked) {
                    const sel = card.querySelector('.class-teacher-select');
                    if (sel && sel.value === '0') card.classList.add('unassigned');
                    else card.classList.remove('unassigned');
                } else {
                    card.classList.remove('unassigned');
                }
                updateClassCount();
                updateGapSummary();
            }, 0);
        });
    });

    // TEACHER CHANGE
    document.querySelectorAll('.class-teacher-select').forEach(function (sel) {
        sel.addEventListener('change', function () {
            const card = sel.closest('.class-card');
            sel.classList.toggle('unassigned', sel.value === '0');
            if (card) {
                const cb = card.querySelector('input[type="checkbox"]');
                const isSel = cb && cb.checked;
                const isUnassigned = isSel && sel.value === '0';
                card.classList.toggle('unassigned', isUnassigned);
            }
            updateGapSummary();
        });
    });

    // SEARCH
    (function () {
        const search = document.getElementById('classSearch');
        if (!search) return;
        search.addEventListener('input', function () {
            const q = this.value.trim().toLowerCase();
            document.querySelectorAll('#classGrid .class-card').forEach(function (card) {
                const name = (card.querySelector('.co-name')?.textContent || '').toLowerCase();
                const meta = (card.querySelector('.co-meta')?.textContent || '').toLowerCase();
                const matches = !q || name.indexOf(q) !== -1 || meta.indexOf(q) !== -1;
                card.style.display = matches ? '' : 'none';
            });
        });
    })();

    // CLEAR
    (function () {
        const btnClear = document.getElementById('clearAll');
        if (!btnClear) return;
        btnClear.addEventListener('click', function () {
            document.querySelectorAll('#classGrid .class-card').forEach(function (card) {
                const cb = card.querySelector('input[type="checkbox"]');
                if (cb) cb.checked = false;
                card.classList.remove('selected', 'unassigned');
            });
            updateClassCount();
            updateGapSummary();
        });
    })();

    // COUNT
    function updateClassCount() {
        const badge = document.getElementById('classCountBadge');
        if (!badge) return;
        const n = document.querySelectorAll('#classGrid input[type="checkbox"]:checked').length;
        badge.textContent = n + ' selected';
    }
    updateClassCount();

    // GAP SUMMARY
    function updateGapSummary() {
        const el = document.getElementById('gapSummary');
        if (!el) return;

        let selected = 0, gaps = 0;
        document.querySelectorAll('#classGrid .class-card').forEach(function (card) {
            const cb = card.querySelector('input[type="checkbox"]');
            if (!cb || !cb.checked) return;
            selected++;
            const sel = card.querySelector('.class-teacher-select');
            if (sel && sel.value === '0') gaps++;
        });

        if (selected === 0) { el.classList.remove('show'); return; }

        if (gaps === 0) {
            el.classList.add('show');
            el.innerHTML = '<i class="fas fa-circle-check" style="color:#059669;margin-right:6px;"></i><strong>Ready to save.</strong> Every selected class has a teacher assigned.';
        } else {
            el.classList.add('show');
            el.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:#b45309;margin-right:6px;"></i><strong>' + gaps + '</strong> of <strong>' + selected + '</strong> selected class(es) still have no teacher. You can save now and assign later.';
        }
    }
    updateGapSummary();

    // PREVENT DOUBLE SUBMIT
    document.getElementById('subjectForm').addEventListener('submit', function () {
        const btn = document.getElementById('saveBtn');
        if (btn.disabled) return;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    });

    // ================= REPORT MODAL =================
    (function () {
        const modal    = document.getElementById('reportModal');
        const body     = document.getElementById('reportBody');
        const closeBtn = document.getElementById('reportClose');
        const copyBtn  = document.getElementById('reportCopyBtn');

        function open()  { modal.classList.add('show'); document.body.style.overflow = 'hidden'; }
        function close() { modal.classList.remove('show'); document.body.style.overflow = ''; }

        closeBtn.addEventListener('click', close);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) close();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('show')) close();
        });

        function buildReport() {
            const groups = {};
            document.querySelectorAll('#classGrid .class-card').forEach(function (card) {
                const btn = card.querySelector('.no-teacher-btn');
                if (!btn) return;
                const lv      = card.dataset.level || '';
                const lvLabel = btn.dataset.lvlabel || lv;
                const cname   = card.dataset.classname || btn.dataset.cname || '';

                if (!groups[lv]) groups[lv] = { label: lvLabel, classes: [] };
                groups[lv].classes.push(cname);
            });
            return groups;
        }

        function renderReport() {
            const groups = buildReport();
            const keys   = Object.keys(groups);

            if (keys.length === 0) {
                body.innerHTML = '<div class="report-note"><i class="fas fa-circle-check" style="color:#059669;margin-right:6px;"></i>Great news — every class level currently has at least one teacher available.</div>';
                return;
            }

            let html = '';
            let total = 0;
            keys.forEach(function (lv) {
                const g = groups[lv];
                total += g.classes.length;
                html += '<div class="report-line">' +
                            '<div class="rl-icon"><i class="fas fa-graduation-cap"></i></div>' +
                            '<div class="rl-text">' +
                                '<div class="t">' + escapeHtml(g.label) + '</div>' +
                                '<div class="s">' + escapeHtml(g.classes.join(', ')) + '</div>' +
                            '</div>' +
                            '<div class="rl-count">' + g.classes.length + ' class' + (g.classes.length === 1 ? '' : 'es') + '</div>' +
                        '</div>';
            });

            html += '<div class="report-note">' +
                        '<strong>' + total + '</strong> class' + (total === 1 ? '' : 'es') + ' cannot be assigned a teacher yet because there are no teachers for the listed level(s). ' +
                        'You can add a teacher from the <a href="school-add-teacher.php" target="_blank" style="color:var(--primary);font-weight:700;text-decoration:none;">Teachers page</a>, then come back here to assign them.' +
                    '</div>';

            body.innerHTML = html;
        }

        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
            });
        }

        document.querySelectorAll('.no-teacher-btn.open-report').forEach(function (btn) {
            btn.addEventListener('click', function () {
                renderReport();
                open();
            });
        });

        copyBtn.addEventListener('click', function () {
            const groups = buildReport();
            const keys = Object.keys(groups);
            let text = "TEACHERS NEEDED — " + <?= json_encode($school_name) ?> + "\n";
            text += "=============================================\n\n";

            if (keys.length === 0) {
                text += "All class levels currently have at least one teacher available.\n";
            } else {
                let total = 0;
                keys.forEach(function (lv) {
                    const g = groups[lv];
                    total += g.classes.length;
                    text += "• " + g.label + " (" + g.classes.length + " class" + (g.classes.length === 1 ? '' : 'es') + ")\n";
                    g.classes.forEach(function (c) {
                        text += "    - " + c + "\n";
                    });
                    text += "\n";
                });
                text += "TOTAL: " + total + " class" + (total === 1 ? '' : 'es') + " waiting for a teacher.\n\n";
                text += "Please add teacher(s) for the level(s) above, then assign them to the classes.\n";
            }
            text += "\nGenerated by Educ Bridge · " + new Date().toLocaleString();

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function () {
                    copyBtn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                    setTimeout(function () { copyBtn.innerHTML = '<i class="fas fa-copy"></i> Copy report'; }, 1500);
                });
            } else {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(ta);
                copyBtn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                setTimeout(function () { copyBtn.innerHTML = '<i class="fas fa-copy"></i> Copy report'; }, 1500);
            }
        });
    })();
</script>

</body>
</html>