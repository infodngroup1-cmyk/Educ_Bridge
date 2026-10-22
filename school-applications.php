<?php
require_once 'db.php';
require_once '_school-auth.php';
require_once 'mailer.php';

/*
|--------------------------------------------------------------------------
| LEVEL NORMALIZER
|--------------------------------------------------------------------------
*/
function normalize_level_enum($raw) {
    $k = strtolower(trim((string) $raw));
    $k = str_replace(['-', '_', ' ', "'", '’'], '', $k);
    switch ($k) {
        case 'nursery': return 'Nursery';
        case 'primary': return 'Primary';
        case 'olevel':  return 'O_Level';
        case 'alevel':  return 'A_Level';
    }
    return null;
}

/*
|--------------------------------------------------------------------------
| ACTOR CONTEXT
|--------------------------------------------------------------------------
*/
$role       = $_SESSION['role']       ?? 'admin';
$teacher_id = isset($_SESSION['teacher_id']) ? (int) $_SESSION['teacher_id'] : 0;

if ($role !== 'teacher' || $teacher_id <= 0) {
    $role       = 'admin';
    $teacher_id = 0;
}

/*
|--------------------------------------------------------------------------
| SELF-DIAGNOSTIC
|--------------------------------------------------------------------------
*/
if (isset($_GET['whoami'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "role         = {$role}\n";
    echo "school_id    = {$school_id}\n";
    echo "school_name  = {$school_name}\n";
    if ($role === 'teacher') echo "teacher_id   = {$teacher_id}\n";

    echo "\n--- classes (visible to this actor) ---\n";
    if ($role === 'teacher') {
        $r = mysqli_query($conn, "
            SELECT id, level, class_name, teacher_id, class_teacher_id, status
            FROM classes
            WHERE school_id = {$school_id}
              AND status = 'active'
              AND (teacher_id = {$teacher_id} OR class_teacher_id = {$teacher_id})
            ORDER BY id
        ");
    } else {
        $r = mysqli_query($conn, "
            SELECT id, level, class_name, teacher_id, class_teacher_id, status
            FROM classes
            WHERE school_id = {$school_id}
            ORDER BY id
        ");
    }
    if ($r) {
        while ($x = mysqli_fetch_assoc($r)) {
            $x['level_normalized'] = normalize_level_enum($x['level']);
            print_r($x);
        }
    }
    exit;
}

$message = ""; $message_type = "";

/*
|--------------------------------------------------------------------------
| 1. LEVELS
|--------------------------------------------------------------------------
*/
$KEY_TO_ENUM = [
    'nursery' => 'Nursery',
    'primary' => 'Primary',
    'olevel'  => 'O_Level',
    'o_level' => 'O_Level',
    'o-level' => 'O_Level',
    'alevel'  => 'A_Level',
    'a_level' => 'A_Level',
    'a-level' => 'A_Level',
];

$DEFAULT_LABELS = [
    'Nursery' => 'Nursery',
    'Primary' => 'Primary',
    'O_Level' => "O' Level",
    'A_Level' => "A' Level",
];

$LEVEL_ORDER = ['Nursery','Primary','O_Level','A_Level'];

$active_levels = [];
$level_labels  = [];

if ($role === 'teacher') {
    $lvl_stmt = mysqli_prepare($conn, "
        SELECT al.level_key, al.level_name
        FROM academic_levels al
        WHERE al.school_id = ? AND al.is_active = 1
        ORDER BY al.id ASC
    ");
} else {
    $lvl_stmt = mysqli_prepare($conn, "
        SELECT level_key, level_name
        FROM academic_levels
        WHERE school_id = ? AND is_active = 1
        ORDER BY id ASC
    ");
}
if ($lvl_stmt) {
    mysqli_stmt_bind_param($lvl_stmt, "i", $school_id);
    mysqli_stmt_execute($lvl_stmt);
    $lres = mysqli_stmt_get_result($lvl_stmt);
    while ($row = mysqli_fetch_assoc($lres)) {
        $raw  = strtolower(trim((string) $row['level_key']));
        $enum = $KEY_TO_ENUM[$raw] ?? null;
        if ($enum === null) continue;

        $label = trim((string) $row['level_name']);
        if ($label === '' || $label === $enum) $label = $DEFAULT_LABELS[$enum];
        if ($enum === 'O_Level') $label = "O' Level";
        if ($enum === 'A_Level') $label = "A' Level";

        $active_levels[$enum] = ['key' => $raw, 'label' => $label];
        $level_labels[$enum]  = $label;
    }
    mysqli_stmt_close($lvl_stmt);
}

/*
|--------------------------------------------------------------------------
| 2. CLASSES
|--------------------------------------------------------------------------
*/
$classes_all      = [];
$classes_by_level = [];

if ($role === 'teacher') {
    $cstmt = mysqli_prepare($conn, "
        SELECT id, class_name, level
        FROM classes
        WHERE school_id = ?
          AND status = 'active'
          AND (teacher_id = ? OR class_teacher_id = ?)
        ORDER BY id ASC
    ");
    if ($cstmt) {
        mysqli_stmt_bind_param($cstmt, "iii", $school_id, $teacher_id, $teacher_id);
        mysqli_stmt_execute($cstmt);
        $cres = mysqli_stmt_get_result($cstmt);
    } else {
        $cres = false;
    }
} else {
    $cstmt = mysqli_prepare($conn, "
        SELECT id, class_name, level
        FROM classes
        WHERE school_id = ? AND status = 'active'
        ORDER BY id ASC
    ");
    if ($cstmt) {
        mysqli_stmt_bind_param($cstmt, "i", $school_id);
        mysqli_stmt_execute($cstmt);
        $cres = mysqli_stmt_get_result($cstmt);
    } else {
        $cres = false;
    }
}

if ($cres) {
    while ($c = mysqli_fetch_assoc($cres)) {
        $enum = normalize_level_enum($c['level']);
        if ($enum === null) continue;
        if (!isset($active_levels[$enum])) continue;

        $classes_all[] = $c;
        $classes_by_level[$enum][] = [
            'id'   => (int) $c['id'],
            'name' => $c['class_name'],
        ];
    }
    mysqli_stmt_close($cstmt);
}

foreach ($LEVEL_ORDER as $enum) {
    if (!isset($active_levels[$enum])) continue;
    if (!isset($classes_by_level[$enum])) $classes_by_level[$enum] = [];
}

$ordered = [];
foreach ($LEVEL_ORDER as $enum) {
    if (isset($classes_by_level[$enum])) $ordered[$enum] = $classes_by_level[$enum];
}
$classes_by_level = $ordered;

/* Class id -> class info */
$class_lookup = [];
foreach ($classes_all as $c) $class_lookup[(int) $c['id']] = $c;

/*
|--------------------------------------------------------------------------
| 3. SAVE SETTINGS
|--------------------------------------------------------------------------
*/
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS application_settings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    school_id       INT NOT NULL UNIQUE,
    academic_year   VARCHAR(20) NOT NULL DEFAULT '',
    levels          VARCHAR(120) NOT NULL DEFAULT '',
    class_ids       TEXT         NOT NULL DEFAULT '',
    mode            ENUM('closed','open','scheduled') NOT NULL DEFAULT 'closed',
    opens_at        DATETIME DEFAULT NULL,
    closes_at       DATETIME DEFAULT NULL,
    note            VARCHAR(500) DEFAULT NULL,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_appset_school (school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$cfg = [
    'academic_year' => '',
    'levels'        => '',
    'class_ids'     => '',
    'mode'          => 'closed',
    'opens_at'      => null,
    'closes_at'     => null,
    'note'          => '',
];
$st = mysqli_prepare($conn, "SELECT * FROM application_settings WHERE school_id = ? LIMIT 1");
mysqli_stmt_bind_param($st, "i", $school_id);
mysqli_stmt_execute($st);
$sres = mysqli_stmt_get_result($st);
if ($srow = mysqli_fetch_assoc($sres)) $cfg = array_merge($cfg, $srow);
mysqli_stmt_close($st);

$sel_levels    = array_filter(array_map('trim', explode(',', (string) $cfg['levels'])));
$sel_class_ids = array_filter(array_map('intval', explode(',', (string) $cfg['class_ids'])));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    $year        = trim($_POST['academic_year'] ?? '');
    $levels_in   = isset($_POST['levels'])  && is_array($_POST['levels'])  ? $_POST['levels']  : [];
    $classes_in  = isset($_POST['classes']) && is_array($_POST['classes']) ? $_POST['classes'] : [];
    $mode        = $_POST['mode'] ?? 'closed';
    $opens_raw   = trim($_POST['opens_at']  ?? '');
    $closes_raw  = trim($_POST['closes_at'] ?? '');
    $note        = trim($_POST['note'] ?? '');

    $errors = [];
    if ($year === '' || !preg_match('/^\d{4}(\s*-\s*\d{4})?$/', $year)) $errors[] = "Academic year must be YYYY or YYYY-YYYY.";

    $levels_clean = array_values(array_intersect(array_map('trim', $levels_in), array_keys($active_levels)));
    if (empty($levels_clean)) $errors[] = "Select at least one level.";

    $class_ids_clean = array_values(array_unique(array_filter(array_map('intval', $classes_in))));
    if (empty($class_ids_clean)) $errors[] = "Select at least one class.";

    if (!in_array($mode, ['closed','open','scheduled'], true)) $errors[] = "Invalid mode.";

    $opens_at = null; $closes_at = null;
    if ($mode === 'scheduled') {
        if ($opens_raw === '' || $closes_raw === '') {
            $errors[] = "For scheduled mode, both dates are required.";
        } else {
            $o = strtotime($opens_raw);
            $c = strtotime($closes_raw);
            if (!$o || !$c) $errors[] = "Invalid dates.";
            elseif ($c <= $o) $errors[] = "Closes at must be after opens at.";
            else {
                $opens_at  = date('Y-m-d H:i:s', $o);
                $closes_at = date('Y-m-d H:i:s', $c);
            }
        }
    }

    if (empty($errors) && !empty($class_ids_clean)) {
        $ph = implode(',', array_fill(0, count($class_ids_clean), '?'));
        $tc = "i" . str_repeat('i', count($class_ids_clean));
        $params = array_merge([$school_id], $class_ids_clean);

        if ($role === 'teacher') {
            $sql = "SELECT id, level FROM classes
                    WHERE school_id = ? AND id IN ($ph)
                      AND (teacher_id = ? OR class_teacher_id = ?)";
            $tc   .= "ii";
            $params[] = $teacher_id;
            $params[] = $teacher_id;
        } else {
            $sql = "SELECT id, level FROM classes WHERE school_id = ? AND id IN ($ph)";
        }

        $v = mysqli_prepare($conn, $sql);
        if ($v) {
            mysqli_stmt_bind_param($v, $tc, ...$params);
            mysqli_stmt_execute($v);
            $vr = mysqli_stmt_get_result($v);
            $found = [];
            while ($x = mysqli_fetch_assoc($vr)) {
                $found[(int) $x['id']] = normalize_level_enum($x['level']);
            }
            mysqli_stmt_close($v);

            if (count($found) !== count($class_ids_clean)) {
                $errors[] = $role === 'teacher'
                    ? "Some classes don't belong to you."
                    : "Some classes don't belong to your school.";
            } else {
                foreach ($found as $cid => $lvlEnum) {
                    if ($lvlEnum === null || !in_array($lvlEnum, $levels_clean, true)) {
                        $errors[] = "Class #{$cid} is at a level you didn't select.";
                        break;
                    }
                }
            }
        }
    }

    if (!empty($errors)) {
        $message = implode(" ", $errors);
        $message_type = "error";
    } else {
        $upd = mysqli_prepare($conn, "
            INSERT INTO application_settings
                (school_id, academic_year, levels, class_ids, mode, opens_at, closes_at, note)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                academic_year = VALUES(academic_year),
                levels        = VALUES(levels),
                class_ids     = VALUES(class_ids),
                mode          = VALUES(mode),
                opens_at      = VALUES(opens_at),
                closes_at     = VALUES(closes_at),
                note          = VALUES(note)
        ");
        mysqli_stmt_bind_param($upd, "isssssss",
            $school_id, $year, implode(',', $levels_clean), implode(',', $class_ids_clean),
            $mode, $opens_at, $closes_at, $note);
        if (mysqli_stmt_execute($upd)) {
            mysqli_stmt_close($upd);
            header("Location: school-applications.php?saved=1");
            exit;
        } else {
            $message = "Could not save: " . mysqli_error($conn);
            $message_type = "error";
            mysqli_stmt_close($upd);
        }
    }
}
if (($_GET['saved'] ?? '') === '1' && $message === '') {
    $message = "Application settings saved.";
    $message_type = "success";
}

/*
|--------------------------------------------------------------------------
| 4. APPLICANTS
|--------------------------------------------------------------------------
*/
$APP_TABLE = 'applications';

$applicants = [];
$app_counts = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];

$tbl_check = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $APP_TABLE) . "'");
$table_exists = $tbl_check && mysqli_num_rows($tbl_check) > 0;

if ($table_exists) {
    $sql = "SELECT id, reference_no, student_name, dob, sex, level, class_id,
                   recent_school, recent_marks, response_email, phone, status,
                   decided_at, decision_note, created_at
            FROM `{$APP_TABLE}`
            WHERE school_id = ?";

    $types  = "i";
    $params = [$school_id];

    if ($role === 'teacher') {
        $teacher_class_ids = [];
        foreach ($classes_all as $c) $teacher_class_ids[] = (int) $c['id'];

        if (empty($teacher_class_ids)) {
            $sql .= " AND 1 = 0";
        } else {
            $ph = implode(',', array_fill(0, count($teacher_class_ids), '?'));
            $sql .= " AND class_id IN ($ph)";
            $types .= str_repeat('i', count($teacher_class_ids));
            foreach ($teacher_class_ids as $cid) $params[] = $cid;
        }
    }

    $sql .= " ORDER BY created_at DESC, id DESC";

    $a = mysqli_prepare($conn, $sql);
    if ($a) {
        mysqli_stmt_bind_param($a, $types, ...$params);
        mysqli_stmt_execute($a);
        $ar = mysqli_stmt_get_result($a);
        while ($row = mysqli_fetch_assoc($ar)) {
            $applicants[] = $row;
            $app_counts['total']++;
            $st = strtolower((string) $row['status']);
            if (isset($app_counts[$st])) $app_counts[$st]++;
        }
        mysqli_stmt_close($a);
    }
}

/*
|--------------------------------------------------------------------------
| 4b. APPLICANT ACTIONS — admit / reject (WITH EMAIL NOTIFICATION)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'decide_application') {

    $app_id   = (int)($_POST['app_id'] ?? 0);
    $decision = strtolower(trim((string)($_POST['decision'] ?? '')));
    $note     = trim((string)($_POST['note'] ?? ''));

    $allowed = ['approved', 'rejected'];

    if ($app_id <= 0) {
        $message = "Invalid application.";
        $message_type = "error";
    } elseif (!in_array($decision, $allowed, true)) {
        $message = "Invalid decision.";
        $message_type = "error";
    } else {

        /* Ownership check */
        if ($role === 'teacher') {
            $check = mysqli_prepare($conn, "
                SELECT a.id
                FROM applications a
                JOIN classes c ON c.id = a.class_id
                WHERE a.id = ? AND a.school_id = ?
                  AND c.school_id = a.school_id
                  AND (c.teacher_id = ? OR c.class_teacher_id = ?)
                LIMIT 1
            ");
            mysqli_stmt_bind_param($check, "iiii", $app_id, $school_id, $teacher_id, $teacher_id);
        } else {
            $check = mysqli_prepare($conn, "
                SELECT id FROM applications
                WHERE id = ? AND school_id = ?
                LIMIT 1
            ");
            mysqli_stmt_bind_param($check, "ii", $app_id, $school_id);
        }

        mysqli_stmt_execute($check);
        $cres = mysqli_stmt_get_result($check);
        $owned = $cres && mysqli_num_rows($cres) > 0;
        mysqli_stmt_close($check);

        if (!$owned) {
            $message = "Application not found or you don't have permission to decide on it.";
            $message_type = "error";
        } else {

            /* Full row for the email */
            $sel = mysqli_prepare($conn, "
                SELECT a.id, a.reference_no, a.student_name, a.response_email,
                       a.class_id, a.level,
                       s.name        AS school_name,
                       s.school_code AS school_code,
                       c.class_name  AS class_name
                FROM applications a
                JOIN schools s ON s.id = a.school_id
                LEFT JOIN classes c ON c.id = a.class_id
                WHERE a.id = ? AND a.school_id = ?
                LIMIT 1
            ");
            mysqli_stmt_bind_param($sel, "ii", $app_id, $school_id);
            mysqli_stmt_execute($sel);
            $sel_res = mysqli_stmt_get_result($sel);
            $full = mysqli_fetch_assoc($sel_res);
            mysqli_stmt_close($sel);

            if (!$full) {
                $message = "Application not found.";
                $message_type = "error";
            } else {

                $upd = mysqli_prepare($conn, "
                    UPDATE applications
                    SET status = ?, decided_at = NOW(), decision_note = ?
                    WHERE id = ? AND school_id = ?
                ");
                mysqli_stmt_bind_param($upd, "ssii", $decision, $note, $app_id, $school_id);

                if (mysqli_stmt_execute($upd)) {
                    mysqli_stmt_close($upd);

                    /* ---- Send decision email ---- */
                    $check_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                               . '://' . $_SERVER['HTTP_HOST']
                               . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')
                               . '/online-school-application.php?school=' . urlencode($full['school_code'])
                               . '&ref=' . urlencode($full['reference_no']);

                    $level_label = $level_labels[normalize_level_enum($full['level'])]
                                 ?? $full['level'];

                    [$mail_ok, $mail_err] = send_decision_email([
                        'to_email'     => $full['response_email'],
                        'to_name'      => $full['student_name'],
                        'reference'    => $full['reference_no'],
                        'school_name'  => $full['school_name'],
                        'school_code'  => $full['school_code'],
                        'student_name' => $full['student_name'],
                        'level_label'  => $level_label,
                        'class_name'   => $full['class_name'] ?? '',
                        'decision'     => $decision,
                        'note'         => $note,
                        'check_url'    => $check_url,
                    ]);

                    if (!$mail_ok) {
                        error_log('[applications] decision mail failed: ' . $mail_err);
                        header("Location: school-applications.php?decided=" . urlencode($decision) . "&mail=fail");
                        exit;
                    }

                    header("Location: school-applications.php?decided=" . urlencode($decision) . "&mail=ok");
                    exit;
                } else {
                    $message = "Could not save decision: " . mysqli_error($conn);
                    $message_type = "error";
                    mysqli_stmt_close($upd);
                }
            }
        }
    }
}

if (($_GET['decided'] ?? '') !== '' && $message === '') {
    $d = strtolower((string)$_GET['decided']);
    $mail = strtolower((string)($_GET['mail'] ?? ''));

    $label = ($d === 'approved') ? "admitted" : "rejected";

    if ($d === 'approved' || $d === 'rejected') {
        $message = "Application {$label} successfully.";
        if ($mail === 'ok')   $message .= " A decision email was sent to the applicant.";
        if ($mail === 'fail') $message .= " The applicant's email could not be sent — please contact them directly.";
        $message_type = "success";
    }
}

function settings_status($cfg) {
    if ($cfg['mode'] === 'closed') return ['Closed','red'];
    if ($cfg['mode'] === 'open')   return ['Open','green'];
    if ($cfg['mode'] === 'scheduled') {
        $now = time();
        $o = !empty($cfg['opens_at'])  ? strtotime($cfg['opens_at'])  : null;
        $c = !empty($cfg['closes_at']) ? strtotime($cfg['closes_at']) : null;
        if ($o && $c) {
            if ($now < $o) return ['Scheduled (future)','orange'];
            if ($now > $c) return ['Closed (past)','red'];
            return ['Open (auto)','green'];
        }
    }
    return ['Unknown','gray'];
}
$status = settings_status($cfg);

function dt_local($v) {
    if (empty($v)) return '';
    $ts = strtotime($v);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}

function app_status_pill($status) {
    $s = strtolower((string) $status);
    switch ($s) {
        case 'approved': return ['Approved','green'];
        case 'rejected': return ['Rejected','red'];
        case 'pending':  return ['Pending','orange'];
        case 'review':   return ['In Review','orange'];
        default:         return [ucfirst($s ?: 'Unknown'), 'gray'];
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Applications | Educ Bridge</title>
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
            --bg-body: #f8fafc; --bg-card: #ffffff; --bg-alt: #f1f5f9;
            --text-primary: #0f172a; --text-secondary: #475569; --text-muted: #64748b;
            --border-color: #e2e8f0;
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
            --bg-body: #0b1120; --bg-card: #1e293b; --bg-alt: #1a2332;
            --text-primary: #f1f5f9; --text-secondary: #cbd5e1; --text-muted: #94a3b8;
            --border-color: #334155;
            --shadow-md: 0 4px 12px rgba(0,0,0,0.4);
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.5);
            --shadow-sidebar: 0 0 30px rgba(0,0,0,0.4);
        }
        *,*::before,*::after { margin:0; padding:0; box-sizing:border-box; }
        html, body { min-height: 100vh; }
        body { font-family:'Inter',sans-serif; background:var(--bg-body); color:var(--text-primary); }
        .layout { display:flex; min-height:100vh; }

        .sidebar { width:260px; background:var(--sidebar-bg); display:flex; flex-direction:column; position:fixed; top:0; left:0; height:100vh; z-index:100; border-right:1px solid var(--sidebar-border); box-shadow:var(--shadow-sidebar); }
        .sidebar-header { padding:22px; border-bottom:1px solid var(--sidebar-border); display:flex; align-items:center; gap:12px; }
        .sidebar-header img { width:42px; height:42px; object-fit:contain; border-radius:50%; border:2px solid var(--sidebar-border); padding:2px; background:#fff; }
        .sidebar-brand { font-family:'Poppins',sans-serif; font-size:18px; font-weight:700; line-height:1.2; }
        .sidebar-brand span { color:var(--primary); }
        .sidebar-brand small { display:block; font-size:11px; color:var(--text-muted); margin-top:2px; letter-spacing:0.4px; text-transform:uppercase; }
        .sidebar-nav { flex:1; padding:18px 12px; overflow-y:auto; }
        .nav-section-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; color:var(--sidebar-title); padding:12px 14px 8px; }
        .nav-item { display:flex; align-items:center; gap:12px; padding:11px 14px; color:var(--sidebar-text); text-decoration:none; font-size:14px; font-weight:500; border-radius:10px; margin-bottom:4px; transition:var(--transition); position:relative; }
        .nav-item i.nav-icon { width:20px; text-align:center; font-size:15px; }
        .nav-item:hover { background:var(--sidebar-hover); color:var(--sidebar-text-active); }
        .nav-item.active { background:var(--sidebar-active); color:var(--sidebar-text-active); }
        .nav-item.active i.nav-icon { color:var(--primary); }
        .nav-item.active::before { content:''; position:absolute; left:0; top:8px; bottom:8px; width:3px; background:var(--primary); border-radius:0 4px 4px 0; }
        .sidebar-profile { border-top:1px solid var(--sidebar-border); padding:16px 18px; display:flex; align-items:center; gap:12px; }
        .sidebar-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-size:15px; font-weight:700; }
        .sidebar-profile-info { flex:1; min-width:0; }
        .sidebar-profile-info .p-name { font-size:13.5px; font-weight:600; }
        .sidebar-profile-info .p-role { font-size:11.5px; color:var(--text-muted); }
        .sidebar-logout { width:34px; height:34px; border-radius:8px; background:var(--danger-light); color:var(--danger); display:flex; align-items:center; justify-content:center; text-decoration:none; font-size:13px; }

        .main { flex:1; margin-left:260px; min-height:100vh; display:flex; flex-direction:column; }
        .topbar { background:var(--bg-card); border-bottom:1px solid var(--border-color); padding:14px 28px; display:flex; align-items:center; justify-content:space-between; gap:16px; position:sticky; top:0; z-index:50; }
        .page-title { font-family:'Poppins',sans-serif; font-size:20px; font-weight:700; }
        .page-subtitle { font-size:13px; color:var(--text-muted); margin-top:2px; }
        .topbar-btn { width:42px; height:42px; border-radius:50%; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; }
        .topbar-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:700; }

        .content { padding:28px; flex:1; }

        .alert { padding:13px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; display:flex; align-items:flex-start; gap:10px; }
        .alert-success { background: var(--success-light); color:#059669; border:1px solid rgba(16,185,129,0.25); }
        [data-theme="dark"] .alert-success { color:#34d399; }
        .alert-error { background: var(--danger-light); color:#dc2626; border:1px solid rgba(239,68,68,0.25); }
        [data-theme="dark"] .alert-error { color:#f87171; }
        .alert-info { background: rgba(37,99,235,0.08); color:#1d4ed8; border:1px solid rgba(37,99,235,0.25); }
        [data-theme="dark"] .alert-info { color:#93c5fd; }

        .page-head { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:22px; flex-wrap:wrap; }
        .page-head h1 { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; display:flex; align-items:center; gap:10px; }
        .page-head h1 i { color:var(--primary); }
        .page-head .sub { font-size:13.5px; color:var(--text-muted); margin-top:4px; }
        .page-head .sub strong { color: var(--text-primary); }

        .card { max-width:1080px; margin:0 auto 28px; background:var(--bg-card); border:1px solid var(--border-color); border-radius:20px; box-shadow:var(--shadow-md); overflow:hidden; }
        .card-head { padding:20px 24px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; background:linear-gradient(135deg, rgba(37,99,235,0.05), rgba(124,58,237,0.03)); }
        .card-head h2 { font-family:'Poppins',sans-serif; font-size:16px; font-weight:700; display:flex; align-items:center; gap:10px; }
        .card-head h2 i { color:var(--primary); }
        .card-head h2 .sid { font-size:11px; color:var(--text-muted); font-weight:600; margin-left:6px; }
        .card-body { padding:22px 24px; }

        .status-pill { display:inline-flex; align-items:center; gap:8px; padding:6px 14px; border-radius:50px; font-size:12.5px; font-weight:700; }
        .status-pill .dot { width:8px; height:8px; border-radius:50%; background:var(--text-muted); }
        .status-pill.green { background: rgba(16,185,129,0.14); color:#059669; }
        .status-pill.green .dot { background: var(--success); }
        [data-theme="dark"] .status-pill.green { color:#34d399; }
        .status-pill.red { background: rgba(239,68,68,0.12); color:#dc2626; }
        .status-pill.red .dot { background: var(--danger); }
        [data-theme="dark"] .status-pill.red { color:#f87171; }
        .status-pill.orange { background: rgba(245,158,11,0.14); color:#b45309; }
        .status-pill.orange .dot { background: var(--warning); }
        [data-theme="dark"] .status-pill.orange { color:#fbbf24; }
        .status-pill.gray { background: rgba(148,163,184,0.15); color:#475569; }
        .status-pill.gray .dot { background: #94a3b8; }

        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        .form-group { margin-bottom:16px; }
        .form-group label { display:block; font-size:13px; font-weight:600; color:var(--text-primary); margin-bottom:7px; }
        .form-group label .required { color:var(--danger); }
        .form-group input, .form-group select, .form-group textarea {
            width:100%; padding:12px 14px;
            border:1.5px solid var(--border-color); border-radius:10px;
            background:var(--bg-alt); color:var(--text-primary);
            font-family:inherit; font-size:14px; outline:none;
            transition:var(--transition);
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color:var(--primary); background:var(--bg-card); box-shadow:0 0 0 3px rgba(37,99,235,0.1);
        }
        .form-help { font-size:12px; color:var(--text-muted); margin-top:6px; }

        .section-title { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.6px; color:var(--text-muted); padding-bottom:8px; border-bottom:1px dashed var(--border-color); margin-bottom:14px; display:flex; align-items:center; gap:8px; }
        .section-title i { color: var(--primary); }

        .check-pills { display:flex; flex-wrap:wrap; gap:10px; }
        .check-pill {
            position:relative; display:inline-flex; align-items:center; gap:8px;
            padding: 11px 16px; border:1.5px solid var(--border-color); border-radius:10px;
            cursor:pointer; background:var(--bg-alt);
            font-size:13.5px; font-weight:600; color:var(--text-primary);
            user-select:none; transition:var(--transition);
        }
        .check-pill input { position:absolute; opacity:0; pointer-events:none; }
        .check-pill .box { width:18px; height:18px; border-radius:6px; border:2px solid var(--border-color); background:var(--bg-card); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .check-pill .box i { font-size:10px; color:#fff; opacity:0; transform:scale(0.4); }
        .check-pill:hover { border-color:var(--primary); }
        .check-pill.selected { border-color:var(--primary); background:var(--primary-light); }
        .check-pill.selected .box { background:var(--primary); border-color:var(--primary); }
        .check-pill.selected .box i { opacity:1; transform:scale(1); }
        .check-pill .cnt { font-size:11px; color:var(--text-muted); font-weight:600; }

        .class-options { margin-top:10px; padding:14px; border:1.5px dashed var(--border-color); border-radius:12px; background:var(--bg-alt); min-height:70px; }
        .class-options-hint { color:var(--text-muted); font-size:13px; text-align:center; padding:16px 0; }
        .class-level-header { display:flex; align-items:center; gap:10px; margin:10px 0 8px; padding-bottom:6px; border-bottom:1px dashed var(--border-color); }
        .class-level-header .clh-name { font-family:'Poppins',sans-serif; font-size:13px; font-weight:700; display:flex; align-items:center; gap:6px; }
        .class-level-header .clh-name i { color: var(--primary); }
        .class-level-header .clh-count { font-size:11px; font-weight:700; background:var(--bg-card); color:var(--text-muted); padding:2px 9px; border-radius:50px; }
        .class-level-header .clh-actions { margin-left:auto; display:flex; gap:6px; }
        .class-level-header .clh-actions button { font-size:11.5px; font-weight:700; color:var(--primary); background:var(--bg-card); border:1px solid var(--border-color); padding:3px 10px; border-radius:6px; cursor:pointer; font-family:inherit; }
        .class-list { display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:8px; margin-bottom:8px; }
        .class-opt { position:relative; display:flex; align-items:center; gap:10px; padding:9px 12px; background:var(--bg-card); border:1.5px solid var(--border-color); border-radius:10px; cursor:pointer; user-select:none; transition:var(--transition); }
        .class-opt:hover { border-color:var(--primary); }
        .class-opt.selected { border-color:var(--primary); background:var(--primary-light); }
        [data-theme="dark"] .class-opt.selected { background:rgba(59,130,246,0.15); }
        .class-opt input { position:absolute; opacity:0; pointer-events:none; }
        .class-opt .co-box { width:18px; height:18px; border-radius:6px; border:2px solid var(--border-color); background:var(--bg-card); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .class-opt .co-box i { font-size:10px; color:#fff; opacity:0; transform:scale(0.4); }
        .class-opt.selected .co-box { background:var(--primary); border-color:var(--primary); }
        .class-opt.selected .co-box i { opacity:1; transform:scale(1); }
        .class-opt .co-name { font-size:13px; font-weight:700; }

        .mode-pills { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px; }
        .mode-pill { position:relative; display:flex; align-items:flex-start; gap:12px; padding:14px 16px; border:1.5px solid var(--border-color); border-radius:12px; background:var(--bg-alt); cursor:pointer; transition:var(--transition); }
        .mode-pill input { position:absolute; opacity:0; pointer-events:none; }
        .mode-pill .dot { width:18px; height:18px; border-radius:50%; border:2px solid var(--border-color); position:relative; flex-shrink:0; margin-top:2px; }
        .mode-pill .dot::after { content:''; position:absolute; inset:3px; border-radius:50%; background:var(--primary); opacity:0; transform:scale(0.4); transition:var(--transition); }
        .mode-pill:hover { border-color:var(--primary); }
        .mode-pill.selected { border-color:var(--primary); background:var(--primary-light); }
        .mode-pill.selected .dot { border-color:var(--primary); }
        .mode-pill.selected .dot::after { opacity:1; transform:scale(1); }
        .mode-pill .mp-title { font-size:13.5px; font-weight:700; margin-bottom:3px; display:flex; align-items:center; gap:7px; }
        .mode-pill .mp-title i { color:var(--text-muted); }
        .mode-pill.selected .mp-title i { color:var(--primary); }
        .mode-pill .mp-desc { font-size:12px; color:var(--text-muted); line-height:1.5; }

        .form-actions { display:flex; gap:12px; justify-content:flex-end; padding-top:18px; border-top:1px solid var(--border-color); margin-top:8px; flex-wrap:wrap; }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:11px 20px; border-radius:10px; font-size:14px; font-weight:600; cursor:pointer; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-primary); text-decoration:none; transition:var(--transition); font-family:inherit; }
        .btn:hover { border-color:var(--primary); color:var(--primary); }
        .btn.primary { background:var(--primary); color:#fff; border-color:var(--primary); box-shadow:0 6px 18px rgba(37,99,235,0.32); }
        .btn.primary:hover { background:var(--primary-dark); color:#fff; }
        .btn:disabled { opacity: 0.7; cursor: not-allowed; }

        .role-badge { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:50px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; }
        .role-badge.admin { background: rgba(37,99,235,0.12); color:#1d4ed8; }
        .role-badge.teacher { background: rgba(139,92,246,0.14); color:#7c3aed; }
        [data-theme="dark"] .role-badge.admin { color:#93c5fd; }
        [data-theme="dark"] .role-badge.teacher { color:#c4b5fd; }

        /* ---------- Applicants table ---------- */
        .stats-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:12px; margin-bottom:18px; }
        .stat-tile { padding:14px 16px; border-radius:12px; border:1px solid var(--border-color); background:var(--bg-alt); }
        .stat-tile .st-label { font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.6px; }
        .stat-tile .st-value { font-family:'Poppins',sans-serif; font-size:24px; font-weight:700; margin-top:4px; }
        .stat-tile.total    .st-value { color: var(--primary); }
        .stat-tile.pending  .st-value { color: var(--warning); }
        .stat-tile.approved .st-value { color: var(--success); }
        .stat-tile.rejected .st-value { color: var(--danger); }

        .table-wrap { overflow:visible; border:1px solid var(--border-color); border-radius:12px; }
        table.apps { width:100%; border-collapse:collapse; font-size:13.5px; }
        table.apps thead th {
            text-align:left; padding:12px 14px;
            background: var(--bg-alt);
            font-size:11.5px; font-weight:700; color:var(--text-muted);
            text-transform:uppercase; letter-spacing:0.5px;
            border-bottom:1px solid var(--border-color);
            white-space:nowrap;
        }
        table.apps tbody td {
            padding:12px 14px;
            border-bottom:1px solid var(--border-color);
            color: var(--text-primary);
            vertical-align: middle;
        }
        table.apps tbody tr:last-child td { border-bottom:none; }
        table.apps tbody tr:hover { background: var(--sidebar-hover); }
        .student-cell { display:flex; align-items:center; gap:10px; }
        .student-avatar {
            width:34px; height:34px; border-radius:50%;
            background: linear-gradient(135deg,#3b82f6,#8b5cf6);
            color:#fff; display:flex; align-items:center; justify-content:center;
            font-size:12px; font-weight:700; flex-shrink:0;
        }
        .student-name { font-weight:600; }
        .student-sub  { font-size:11.5px; color:var(--text-muted); }
        .chip-level {
            display:inline-flex; align-items:center; gap:6px;
            padding:3px 10px; border-radius:50px;
            font-size:11px; font-weight:700;
            background: var(--primary-light); color: var(--primary);
        }
        [data-theme="dark"] .chip-level { background: rgba(59,130,246,0.15); color:#93c5fd; }
        .chip-status { display:inline-flex; align-items:center; gap:6px; padding:4px 11px; border-radius:50px; font-size:11.5px; font-weight:700; }
        .chip-status .dot { width:6px; height:6px; border-radius:50%; }
        .chip-status.green { background: rgba(16,185,129,0.14); color:#059669; }
        .chip-status.green .dot { background: var(--success); }
        [data-theme="dark"] .chip-status.green { color:#34d399; }
        .chip-status.red { background: rgba(239,68,68,0.12); color:#dc2626; }
        .chip-status.red .dot { background: var(--danger); }
        [data-theme="dark"] .chip-status.red { color:#f87171; }
        .chip-status.orange { background: rgba(245,158,11,0.14); color:#b45309; }
        .chip-status.orange .dot { background: var(--warning); }
        [data-theme="dark"] .chip-status.orange { color:#fbbf24; }
        .chip-status.gray { background: rgba(148,163,184,0.15); color:#475569; }
        .chip-status.gray .dot { background: #94a3b8; }
        .empty-row td { text-align:center; padding:40px 20px !important; color:var(--text-muted); }
        .empty-row i { font-size:28px; display:block; margin-bottom:10px; color:var(--text-muted); opacity:0.6; }

        /* ---------- Row action menu ---------- */
        .row-actions { position: relative; display: inline-block; }
        .row-actions .dots {
            width: 34px; height: 34px; border-radius: 8px;
            border: 1.5px solid var(--border-color); background: var(--bg-card);
            color: var(--text-secondary);
            display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer; transition: var(--transition); font-size: 14px;
        }
        .row-actions .dots:hover { border-color: var(--primary); color: var(--primary); }
        .row-actions.open .dots { border-color: var(--primary); color: var(--primary); background: var(--primary-light); }

        .row-actions .menu {
            position: absolute; top: calc(100% + 6px); right: 0;
            min-width: 210px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.12);
            padding: 6px;
            z-index: 60;
            display: none;
        }
        [data-theme="dark"] .row-actions .menu { box-shadow: 0 12px 30px rgba(0,0,0,0.6); }
        .row-actions.open .menu { display: block; }
        .row-actions .menu button {
            width: 100%; text-align: left;
            display: flex; align-items: center; gap: 10px;
            padding: 9px 12px; border-radius: 8px;
            border: none; background: transparent;
            color: var(--text-primary);
            font-family: inherit; font-size: 13.5px; font-weight: 600;
            cursor: pointer; text-decoration: none;
            transition: var(--transition);
        }
        .row-actions .menu button:hover { background: var(--bg-alt); }
        .row-actions .menu button:disabled { opacity: 0.55; cursor: not-allowed; }
        .row-actions .menu button.danger { color: var(--danger); }
        .row-actions .menu button.danger:hover:not(:disabled) { background: var(--danger-light); }
        .row-actions .menu button.success { color: var(--success); }
        .row-actions .menu button.success:hover:not(:disabled) { background: var(--success-light); }
        .row-actions .menu button i { width: 16px; text-align: center; font-size: 13px; }
        .row-actions .menu .sep { height: 1px; background: var(--border-color); margin: 4px 6px; }
        .row-actions .menu .menu-label {
            font-size: 10.5px; font-weight: 800;
            letter-spacing: 0.8px; text-transform: uppercase;
            color: var(--text-muted);
            padding: 8px 12px 4px;
        }

        /* ---------- Modals ---------- */
        .decide-modal {
            position: fixed; inset: 0;
            background: rgba(15,23,42,0.55);
            display: none; align-items: center; justify-content: center;
            padding: 20px; z-index: 200;
        }
        .decide-modal.open { display: flex; }
        .decide-modal .dm-card {
            width: 100%; max-width: 520px;
            background: var(--bg-card);
            border-radius: 18px; border: 1px solid var(--border-color);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            animation: dmIn 0.25s ease;
        }
        @keyframes dmIn { from { transform: translateY(10px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .decide-modal .dm-head {
            padding: 20px 22px 16px;
            border-bottom: 1px solid var(--border-color);
            display: flex; align-items: center; gap: 12px;
        }
        .decide-modal .dm-head .dm-ico {
            width: 44px; height: 44px; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 18px; flex-shrink: 0;
        }
        .decide-modal .dm-head .dm-ico.green { background: var(--success-light); color: var(--success); }
        .decide-modal .dm-head .dm-ico.red { background: var(--danger-light); color: var(--danger); }
        .decide-modal .dm-head .dm-ico.blue { background: rgba(37,99,235,0.10); color: var(--primary); }
        .decide-modal .dm-head h3 { font-family:'Poppins',sans-serif; font-size: 16px; font-weight: 700; }
        .decide-modal .dm-head p { font-size: 12.5px; color: var(--text-muted); margin-top: 2px; }
        .decide-modal .dm-body { padding: 20px 22px; }
        .decide-modal .dm-body label { display:block; font-size: 12.5px; font-weight: 700; color: var(--text-secondary); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        .decide-modal .dm-body textarea {
            width: 100%; padding: 12px 14px; min-height: 90px;
            border: 1.5px solid var(--border-color); border-radius: 10px;
            background: var(--bg-alt); color: var(--text-primary);
            font-family: inherit; font-size: 14px; outline: none;
            transition: var(--transition); resize: vertical;
        }
        .decide-modal .dm-body textarea:focus { border-color: var(--primary); background: var(--bg-card); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        .decide-modal .dm-body .dm-student {
            padding: 12px 14px; background: var(--bg-alt);
            border: 1px solid var(--border-color); border-radius: 10px;
            margin-bottom: 16px;
            font-size: 13px; color: var(--text-secondary); line-height: 1.6;
        }
        .decide-modal .dm-body .dm-student strong { color: var(--text-primary); }
        .decide-modal .dm-body .dm-grid {
            display:grid; grid-template-columns: 130px 1fr; gap: 8px 12px;
            font-size: 13.5px;
        }
        .decide-modal .dm-body .dm-grid .k { color: var(--text-muted); font-weight: 700; }
        .decide-modal .dm-body .dm-grid .v { color: var(--text-primary); font-weight: 600; word-break: break-word; }
        .decide-modal .dm-foot {
            padding: 16px 22px 20px;
            display: flex; gap: 10px; justify-content: flex-end;
            border-top: 1px solid var(--border-color);
        }
        .decide-modal .dm-foot button {
            padding: 11px 20px; border-radius: 10px;
            font-family: inherit; font-size: 14px; font-weight: 700;
            cursor: pointer; border: 1.5px solid var(--border-color);
            background: var(--bg-card); color: var(--text-primary);
            transition: var(--transition);
        }
        .decide-modal .dm-foot button:hover { border-color: var(--primary); color: var(--primary); }
        .decide-modal .dm-foot button.primary { background: var(--primary); color: #fff; border-color: var(--primary); box-shadow: 0 6px 18px rgba(37,99,235,0.32); }
        .decide-modal .dm-foot button.primary:hover { background: var(--primary-dark); color: #fff; }
        .decide-modal .dm-foot button.danger { background: var(--danger); color: #fff; border-color: var(--danger); box-shadow: 0 6px 18px rgba(239,68,68,0.32); }
        .decide-modal .dm-foot button.danger:hover { background: #dc2626; color: #fff; }
        .decide-modal .dm-foot button.success { background: var(--success); color: #fff; border-color: var(--success); box-shadow: 0 6px 18px rgba(16,185,129,0.32); }
        .decide-modal .dm-foot button.success:hover { background: #059669; color: #fff; }

        @media (max-width: 900px) { .sidebar { transform:translateX(-100%); } .main { margin-left:0; } }
        @media (max-width: 700px) { .form-row { grid-template-columns:1fr; } }
    </style>
</head>
<body>

<div class="layout">

    <aside class="sidebar">
        <div class="sidebar-header">
            <img src="logo.png" alt="Educ Bridge">
            <div class="sidebar-brand">Educ<span>Bridge</span><small><?= htmlspecialchars($short_school); ?></small></div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section-title">Overview</div>
            <a href="school-dashboard.php" class="nav-item"><i class="fas fa-th-large nav-icon"></i> Dashboard</a>
            <div class="nav-section-title">Academics</div>
            <a href="school-students.php" class="nav-item"><i class="fas fa-user-graduate nav-icon"></i> Students</a>
            <a href="school-teachers.php" class="nav-item"><i class="fas fa-chalkboard-teacher nav-icon"></i> Teachers</a>
            <a href="school-classes.php" class="nav-item"><i class="fas fa-layer-group nav-icon"></i> Classes</a>
            <a href="school-subjects.php" class="nav-item"><i class="fas fa-book nav-icon"></i> Subjects</a>
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
            <a href="school-logout.php" class="sidebar-logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div style="display:flex;align-items:center;gap:14px;">
                <div>
                    <div class="page-title">Applications</div>
                    <div class="page-subtitle"><?= htmlspecialchars($school_name); ?></div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:10px;">
                <span class="role-badge <?= $role === 'teacher' ? 'teacher' : 'admin'; ?>">
                    <i class="fas <?= $role === 'teacher' ? 'fa-chalkboard-teacher' : 'fa-user-shield'; ?>"></i>
                    <?= $role === 'teacher' ? 'Teacher' : 'Admin'; ?>
                </span>
                <button class="topbar-btn" id="themeToggle"><i class="fas fa-moon"></i></button>
                <div class="topbar-avatar"><?= htmlspecialchars($initials); ?></div>
            </div>
        </header>

        <main class="content">

            <?php if ($message !== ""): ?>
                <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                    <i class="fas <?= $message_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <?php if (empty($active_levels)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle" style="margin-top:2px;"></i>
                    <span>
                        <?php if ($role === 'teacher'): ?>
                            You have no active classes assigned to you yet, so no levels are available.
                            Please ask your school admin to assign you to classes.
                        <?php else: ?>
                            You haven't activated any <strong>academic levels</strong> for
                            <strong><?= htmlspecialchars($school_name); ?></strong> yet.
                            <a href="school-academic-levels.php" style="color:inherit;text-decoration:underline;font-weight:700;">Activate them first</a>
                            so students can apply to those levels.
                        <?php endif; ?>
                    </span>
                </div>
            <?php elseif (empty($classes_all)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle" style="margin-top:2px;"></i>
                    <span>
                        <?php if ($role === 'teacher'): ?>
                            You have no active classes assigned to you yet.
                        <?php else: ?>
                            You have no active classes yet.
                            <a href="school-add-class.php" style="color:inherit;text-decoration:underline;font-weight:700;">Add classes first</a>
                            so students can apply to them.
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>

            <div class="page-head">
                <div>
                    <h1><i class="fas fa-door-open"></i> Application Settings</h1>
                    <div class="sub">
                        For <strong><?= htmlspecialchars($school_name); ?></strong>
                        &middot; <strong><?= count($active_levels); ?></strong> active level<?= count($active_levels) === 1 ? '' : 's'; ?>
                        &middot; <strong><?= count($classes_all); ?></strong> active class<?= count($classes_all) === 1 ? '' : 'es'; ?>
                        <?php if ($role === 'teacher'): ?>
                            &middot; <strong>yours only</strong>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ================= INTAKE CONFIGURATION ================= -->
            <div class="card">
                <div class="card-head">
                    <h2>
                        <i class="fas fa-sliders"></i> Intake Configuration
                        <span class="sid">
                            · school_id <?= (int)$school_id; ?>
                            <?php if ($role === 'teacher'): ?>
                                · teacher_id <?= (int)$teacher_id; ?>
                            <?php endif; ?>
                        </span>
                    </h2>
                    <div class="status-pill <?= htmlspecialchars($status[1]); ?>">
                        <span class="dot"></span> <?= htmlspecialchars($status[0]); ?>
                    </div>
                </div>
                <div class="card-body">

                    <form method="POST" action="school-applications.php" id="cfgForm" autocomplete="off">
                        <input type="hidden" name="action" value="save_settings">

                        <div class="form-row">
                            <div class="form-group">
                                <label for="academic_year">Academic Year <span class="required">*</span></label>
                                <input type="text" id="academic_year" name="academic_year"
                                       maxlength="20" required
                                       value="<?= htmlspecialchars($cfg['academic_year']); ?>"
                                       placeholder="e.g. 2025-2026">
                            </div>
                            <div class="form-group">
                                <label for="note">Note (optional)</label>
                                <input type="text" id="note" name="note" maxlength="500"
                                       value="<?= htmlspecialchars($cfg['note']); ?>"
                                       placeholder="Shown to applicants on the public form">
                            </div>
                        </div>

                        <div class="section-title" style="margin-top:6px;">
                            <i class="fas fa-layer-group"></i>
                            Levels Open for Applications
                            <span class="required" style="color:#ef4444;">*</span>
                        </div>

                        <div class="form-group">
                            <div class="check-pills" id="levelPills">
                                <?php foreach ($LEVEL_ORDER as $enum):
                                    if (!isset($active_levels[$enum])) continue;
                                    $sel = in_array($enum, $sel_levels, true);
                                    $cnt = count($classes_by_level[$enum] ?? []);
                                    $lbl = $level_labels[$enum] ?? $enum;
                                ?>
                                    <label class="check-pill <?= $sel ? 'selected' : ''; ?>" data-level="<?= $enum; ?>">
                                        <input type="checkbox" name="levels[]" value="<?= $enum; ?>" <?= $sel ? 'checked' : ''; ?>>
                                        <span class="box"><i class="fas fa-check"></i></span>
                                        <?= htmlspecialchars($lbl); ?>
                                        <span class="cnt">(<?= $cnt; ?>)</span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-help">
                                Levels come from your school's <strong>academic levels</strong>.
                                The number in brackets is how many active classes you have at each level<?= $role === 'teacher' ? ' (only yours)' : ''; ?>.
                            </div>
                        </div>

                        <div class="section-title" style="margin-top:6px;">
                            <i class="fas fa-list-check"></i>
                            Classes Open for Applications
                            <span class="required" style="color:#ef4444;">*</span>
                        </div>

                        <div class="form-group">
                            <div class="class-options" id="classOptions">
                                <div class="class-options-hint">Tick one or more levels above to see the classes.</div>
                            </div>
                            <div class="form-help">These are your real classes at the ticked level(s).</div>
                        </div>

                        <div class="section-title" style="margin-top:20px;">
                            <i class="fas fa-hourglass-half"></i>
                            Application Period
                            <span class="required" style="color:#ef4444;">*</span>
                        </div>

                        <div class="mode-pills" id="modePills">
                            <label class="mode-pill <?= $cfg['mode'] === 'closed' ? 'selected' : ''; ?>">
                                <input type="radio" name="mode" value="closed" <?= $cfg['mode'] === 'closed' ? 'checked' : ''; ?>>
                                <span class="dot"></span>
                                <div>
                                    <div class="mp-title"><i class="fas fa-lock"></i> Closed</div>
                                    <div class="mp-desc">No applications accepted.</div>
                                </div>
                            </label>
                            <label class="mode-pill <?= $cfg['mode'] === 'open' ? 'selected' : ''; ?>">
                                <input type="radio" name="mode" value="open" <?= $cfg['mode'] === 'open' ? 'checked' : ''; ?>>
                                <span class="dot"></span>
                                <div>
                                    <div class="mp-title"><i class="fas fa-globe"></i> Always Open</div>
                                    <div class="mp-desc">Applications accepted at any time.</div>
                                </div>
                            </label>
                            <label class="mode-pill <?= $cfg['mode'] === 'scheduled' ? 'selected' : ''; ?>">
                                <input type="radio" name="mode" value="scheduled" <?= $cfg['mode'] === 'scheduled' ? 'checked' : ''; ?>>
                                <span class="dot"></span>
                                <div>
                                    <div class="mp-title"><i class="fas fa-calendar-check"></i> Scheduled</div>
                                    <div class="mp-desc">Opens and closes automatically.</div>
                                </div>
                            </label>
                        </div>

                        <div class="form-row" id="schedWrap" style="<?= $cfg['mode'] === 'scheduled' ? '' : 'display:none;'; ?>margin-top:14px;">
                            <div class="form-group">
                                <label for="opens_at">Opens At</label>
                                <input type="datetime-local" id="opens_at" name="opens_at"
                                       value="<?= htmlspecialchars(dt_local($cfg['opens_at'])); ?>">
                            </div>
                            <div class="form-group">
                                <label for="closes_at">Closes At</label>
                                <input type="datetime-local" id="closes_at" name="closes_at"
                                       value="<?= htmlspecialchars(dt_local($cfg['closes_at'])); ?>">
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn primary" id="saveBtn">
                                <i class="fas fa-save"></i> Save Settings
                            </button>
                        </div>
                    </form>

                </div>
            </div>

            <!-- ================= APPLICANTS TABLE ================= -->
            <div class="card">
                <div class="card-head">
                    <h2><i class="fas fa-users"></i> Applied Students </h2>
                    <div class="status-pill <?= $app_counts['total'] > 0 ? 'green' : 'gray'; ?>">
                        <span class="dot"></span> <?= (int)$app_counts['total']; ?> total
                    </div>
                </div>
                <div class="card-body">

                    <?php if (!$table_exists): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-triangle" style="margin-top:2px;"></i>
                            <span>
                                Applications table <code><?= htmlspecialchars($APP_TABLE); ?></code> was not found in the database.
                            </span>
                        </div>
                    <?php else: ?>

                        <div class="stats-grid">
                            <div class="stat-tile total">
                                <div class="st-label">Total</div>
                                <div class="st-value"><?= (int)$app_counts['total']; ?></div>
                            </div>
                            <div class="stat-tile pending">
                                <div class="st-label">Pending</div>
                                <div class="st-value"><?= (int)$app_counts['pending']; ?></div>
                            </div>
                            <div class="stat-tile approved">
                                <div class="st-label">Approved</div>
                                <div class="st-value"><?= (int)$app_counts['approved']; ?></div>
                            </div>
                            <div class="stat-tile rejected">
                                <div class="st-label">Rejected</div>
                                <div class="st-value"><?= (int)$app_counts['rejected']; ?></div>
                            </div>
                        </div>

                        <div class="table-wrap">
                            <table class="apps">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Bio</th>
                                        <th>Response Email</th>
                                        <th>Phone</th>
                                        <th>Class</th>
                                        <th>Level</th>
                                        <th>Status</th>
                                        <th style="text-align:right;width:70px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($applicants)): ?>
                                        <tr class="empty-row">
                                            <td colspan="8">
                                                <i class="fas fa-inbox"></i>
                                                No applications yet.
                                            </td>
                                        </tr>
                                    <?php else: foreach ($applicants as $a):
                                        $cls_id   = (int) ($a['class_id'] ?? 0);
                                        $cls_name = $class_lookup[$cls_id]['class_name'] ?? '—';
                                        $cls_lvl  = isset($class_lookup[$cls_id])
                                                    ? normalize_level_enum($class_lookup[$cls_id]['level'])
                                                    : null;
                                        $cls_lvl_label = $cls_lvl && isset($level_labels[$cls_lvl])
                                                    ? $level_labels[$cls_lvl]
                                                    : ($cls_lvl ?: '—');

                                        [$sp_label, $sp_color] = app_status_pill($a['status'] ?? 'pending');

                                        $name  = trim((string)($a['student_name']   ?? ''));
                                        $email = trim((string)($a['response_email'] ?? ''));
                                        $phone = trim((string)($a['phone']          ?? ''));
                                        $ref   = trim((string)($a['reference_no']   ?? ''));

                                        $status_lc = strtolower((string)($a['status'] ?? 'pending'));

                                        $parts = preg_split('/\s+/', $name !== '' ? $name : 'Student');
                                        $initials_a = strtoupper(substr($parts[0] ?? 'S', 0, 1) . substr($parts[1] ?? '', 0, 1));
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="student-cell">
                                                    <div class="student-avatar"><?= htmlspecialchars($initials_a); ?></div>
                                                    <div>
                                                        <div class="student-name"><?= htmlspecialchars($name ?: '—'); ?></div>
                                                        <div class="student-sub">
                                                            <?= $ref !== '' ? 'Ref: ' . htmlspecialchars($ref) : '#' . (int)$a['id']; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="student-sub" style="font-size:12.5px;">
                                                    <?php if (!empty($a['sex'])): ?>
                                                        <div><i class="fas fa-user" style="width:12px;"></i> <?= htmlspecialchars($a['sex']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($a['dob'])): ?>
                                                        <div><i class="fas fa-cake-candles" style="width:12px;"></i> <?= htmlspecialchars(date('d M Y', strtotime($a['dob']))); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($email !== ''): ?>
                                                    <i class="fas fa-envelope" style="color:var(--text-muted);font-size:11px;"></i>
                                                    <?= htmlspecialchars($email); ?>
                                                <?php else: ?>
                                                    <span style="color:var(--text-muted);">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($phone !== ''): ?>
                                                    <i class="fas fa-phone" style="color:var(--text-muted);font-size:11px;"></i>
                                                    <?= htmlspecialchars($phone); ?>
                                                <?php else: ?>
                                                    <span style="color:var(--text-muted);">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($cls_name); ?></td>
                                            <td>
                                                <?php if ($cls_lvl): ?>
                                                    <span class="chip-level"><i class="fas fa-graduation-cap"></i> <?= htmlspecialchars($cls_lvl_label); ?></span>
                                                <?php else: ?>
                                                    <span style="color:var(--text-muted);">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="chip-status <?= htmlspecialchars($sp_color); ?>">
                                                    <span class="dot"></span> <?= htmlspecialchars($sp_label); ?>
                                                </span>
                                            </td>
                                            <td style="text-align:right;">
                                                <div class="row-actions">
                                                    <button type="button" class="dots" aria-label="Actions">
                                                        <i class="fas fa-ellipsis-vertical"></i>
                                                    </button>

                                                    <div class="menu">
                                                        <div class="menu-label">Student</div>

                                                        <button type="button"
                                                                class="act-view"
                                                                data-id="<?= (int)$a['id']; ?>"
                                                                data-name="<?= htmlspecialchars($name, ENT_QUOTES); ?>"
                                                                data-ref="<?= htmlspecialchars($ref, ENT_QUOTES); ?>"
                                                                data-email="<?= htmlspecialchars($email, ENT_QUOTES); ?>"
                                                                data-phone="<?= htmlspecialchars($phone, ENT_QUOTES); ?>"
                                                                data-level="<?= htmlspecialchars($cls_lvl_label, ENT_QUOTES); ?>"
                                                                data-class="<?= htmlspecialchars($cls_name, ENT_QUOTES); ?>"
                                                                data-dob="<?= htmlspecialchars(!empty($a['dob']) ? date('d M Y', strtotime($a['dob'])) : '', ENT_QUOTES); ?>"
                                                                data-sex="<?= htmlspecialchars($a['sex'] ?? '', ENT_QUOTES); ?>"
                                                                data-recent-school="<?= htmlspecialchars($a['recent_school'] ?? '', ENT_QUOTES); ?>"
                                                                data-recent-marks="<?= htmlspecialchars($a['recent_marks'] ?? '', ENT_QUOTES); ?>"
                                                                data-status="<?= htmlspecialchars($sp_label, ENT_QUOTES); ?>"
                                                                data-status-color="<?= htmlspecialchars($sp_color, ENT_QUOTES); ?>"
                                                                data-created="<?= htmlspecialchars(!empty($a['created_at']) ? date('d M Y, H:i', strtotime($a['created_at'])) : '', ENT_QUOTES); ?>"
                                                                data-decided="<?= htmlspecialchars(!empty($a['decided_at']) ? date('d M Y, H:i', strtotime($a['decided_at'])) : '', ENT_QUOTES); ?>"
                                                                data-note="<?= htmlspecialchars($a['decision_note'] ?? '', ENT_QUOTES); ?>">
                                                            <i class="fas fa-eye"></i> View
                                                        </button>

                                                        <div class="sep"></div>

                                                        <button type="button"
                                                                class="act-admit success"
                                                                data-id="<?= (int)$a['id']; ?>"
                                                                data-name="<?= htmlspecialchars($name, ENT_QUOTES); ?>"
                                                                <?= $status_lc === 'approved' ? 'disabled' : ''; ?>>
                                                            <i class="fas fa-circle-check"></i>
                                                            <?= $status_lc === 'approved' ? 'Already admitted' : 'Admit'; ?>
                                                        </button>

                                                        <button type="button"
                                                                class="act-reject danger"
                                                                data-id="<?= (int)$a['id']; ?>"
                                                                data-name="<?= htmlspecialchars($name, ENT_QUOTES); ?>"
                                                                <?= $status_lc === 'rejected' ? 'disabled' : ''; ?>>
                                                            <i class="fas fa-circle-xmark"></i>
                                                            <?= $status_lc === 'rejected' ? 'Already rejected' : 'Reject'; ?>
                                                        </button>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>

                    <?php endif; ?>
                </div>
            </div>

        </main>
    </div>
</div>

<!-- ============ VIEW MODAL ============ -->
<div class="decide-modal" id="viewModal">
    <div class="dm-card">
        <div class="dm-head">
            <div class="dm-ico blue"><i class="fas fa-user-graduate"></i></div>
            <div>
                <h3 id="vmName">Student</h3>
                <p id="vmRef">Reference —</p>
            </div>
        </div>
        <div class="dm-body">
            <div class="dm-grid" id="vmBody"></div>
            <div style="text-align:right;margin-top:14px;">
                <span class="chip-status gray" id="vmStatus"><span class="dot"></span> —</span>
            </div>
        </div>
        <div class="dm-foot">
            <button type="button" data-close-modal>Close</button>
        </div>
    </div>
</div>

<!-- ============ DECIDE MODAL ============ -->
<div class="decide-modal" id="decideModal">
    <form class="dm-card" method="POST" action="school-applications.php" id="decideForm">
        <input type="hidden" name="action" value="decide_application">
        <input type="hidden" name="app_id" id="dAppId">
        <input type="hidden" name="decision" id="dDecision">

        <div class="dm-head">
            <div class="dm-ico green" id="dIcon"><i class="fas fa-circle-check"></i></div>
            <div>
                <h3 id="dTitle">Admit application</h3>
                <p id="dSub">Confirm your decision.</p>
            </div>
        </div>

        <div class="dm-body">
            <div class="dm-student" id="dStudent"></div>
            <label for="dNote">Note (optional — sent to the applicant)</label>
            <textarea id="dNote" name="note" maxlength="1000" placeholder="e.g. Welcome! Please report to the school office on ..."></textarea>
        </div>

        <div class="dm-foot">
            <button type="button" data-close-modal>Cancel</button>
            <button type="submit" id="dConfirm" class="success">Confirm</button>
        </div>
    </form>
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
            apply(html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
        });
    })();

    /* CLASS PICKER */
    const CLASSES_BY_LEVEL = <?= json_encode($classes_by_level); ?>;
    const LEVEL_LABELS     = <?= json_encode($level_labels); ?>;
    const LEVEL_ORDER      = ['Nursery','Primary','O_Level','A_Level'];
    const PRE_SELECTED     = <?= json_encode(array_values(array_map('intval', $sel_class_ids))); ?>;

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function rebuildClasses() {
        const selected = Array.from(document.querySelectorAll('#levelPills input:checked')).map(c => c.value);
        const wrap = document.getElementById('classOptions');

        if (selected.length === 0) {
            wrap.innerHTML = '<div class="class-options-hint">Tick one or more levels above to see the classes.</div>';
            return;
        }

        let html = '';
        LEVEL_ORDER.forEach(function (lvl) {
            if (!selected.includes(lvl)) return;

            const items = CLASSES_BY_LEVEL[lvl] || [];
            const label = LEVEL_LABELS[lvl] || lvl;

            html += '<div class="class-level-header" data-level="' + lvl + '">';
            html +=   '<div class="clh-name"><i class="fas fa-graduation-cap"></i> ' + escapeHtml(label) + '</div>';
            html +=   '<span class="clh-count">' + items.length + ' class' + (items.length === 1 ? '' : 'es') + '</span>';
            html +=   '<div class="clh-actions">';
            html +=     '<button type="button" data-act="all">All</button>';
            html +=     '<button type="button" data-act="none">Clear</button>';
            html +=   '</div>';
            html += '</div>';

            if (items.length === 0) {
                html += '<div class="class-options-hint" style="padding:10px 0;">';
                html +=   'No active classes at this level yet.';
                html += '</div>';
            } else {
                html += '<div class="class-list">';
                items.forEach(function (c) {
                    const checked = PRE_SELECTED.indexOf(c.id) !== -1;
                    html += '<label class="class-opt ' + (checked ? 'selected' : '') + '">';
                    html +=   '<input type="checkbox" name="classes[]" value="' + c.id + '"' + (checked ? ' checked' : '') + '>';
                    html +=   '<span class="co-box"><i class="fas fa-check"></i></span>';
                    html +=   '<span class="co-name">' + escapeHtml(c.name) + '</span>';
                    html += '</label>';
                });
                html += '</div>';
            }
        });

        wrap.innerHTML = html;

        wrap.querySelectorAll('.class-opt').forEach(function (opt) {
            opt.addEventListener('click', function () {
                setTimeout(function () {
                    const cb = opt.querySelector('input');
                    opt.classList.toggle('selected', cb.checked);
                }, 0);
            });
        });

        wrap.querySelectorAll('.class-level-header').forEach(function (hdr) {
            hdr.querySelectorAll('button').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const on = btn.dataset.act === 'all';
                    let node = hdr.nextElementSibling;
                    while (node && !node.classList.contains('class-level-header')) {
                        if (node.classList.contains('class-list')) {
                            node.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
                                cb.checked = on;
                                const lbl = cb.closest('.class-opt');
                                if (lbl) lbl.classList.toggle('selected', on);
                            });
                            break;
                        }
                        node = node.nextElementSibling;
                    }
                });
            });
        });
    }

    document.querySelectorAll('#levelPills .check-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            const cb = pill.querySelector('input');
            setTimeout(function () {
                pill.classList.toggle('selected', cb.checked);
                rebuildClasses();
            }, 0);
        });
    });

    document.querySelectorAll('#modePills .mode-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            document.querySelectorAll('#modePills .mode-pill').forEach(p => p.classList.remove('selected'));
            pill.classList.add('selected');
            const r = pill.querySelector('input');
            if (r) r.checked = true;
            document.getElementById('schedWrap').style.display = r.value === 'scheduled' ? '' : 'none';
        });
    });

    document.getElementById('cfgForm').addEventListener('submit', function () {
        const btn = document.getElementById('saveBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    });

    rebuildClasses();

    /* ============ ROW ACTIONS MENU ============ */
    document.addEventListener('click', function (ev) {
        const dots = ev.target.closest('.row-actions .dots');
        const menuItem = ev.target.closest('.row-actions .menu > *');

        if (dots) {
            const parent = dots.closest('.row-actions');
            const wasOpen = parent.classList.contains('open');
            document.querySelectorAll('.row-actions.open').forEach(el => el.classList.remove('open'));
            if (!wasOpen) parent.classList.add('open');
            ev.stopPropagation();
            return;
        }
        if (menuItem) {
            setTimeout(() => document.querySelectorAll('.row-actions.open').forEach(el => el.classList.remove('open')), 0);
            return;
        }
        document.querySelectorAll('.row-actions.open').forEach(el => el.classList.remove('open'));
    });

    /* ============ MODALS ============ */
    const viewModal = document.getElementById('viewModal');
    const decideModal = document.getElementById('decideModal');

    function openModal(m) { m.classList.add('open'); }
    function closeModal(m) { m.classList.remove('open'); }

    document.querySelectorAll('[data-close-modal]').forEach(btn => {
        btn.addEventListener('click', () => btn.closest('.decide-modal').classList.remove('open'));
    });
    [viewModal, decideModal].forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') document.querySelectorAll('.decide-modal.open').forEach(m => m.classList.remove('open'));
    });

    document.querySelectorAll('.act-view').forEach(btn => {
        btn.addEventListener('click', function () {
            const d = this.dataset;
            document.getElementById('vmName').textContent = d.name || '—';
            document.getElementById('vmRef').textContent  = d.ref ? ('Reference ' + d.ref) : ('ID #' + d.id);

            const rows = [
                ['Sex', d.sex],
                ['Date of birth', d.dob],
                ['Level', d.level],
                ['Class', d.class],
                ['Response Email', d.email],
                ['Phone', d.phone],
                ['Recent school', d.recentSchool],
                ['Recent marks', d.recentMarks],
                ['Applied on', d.created],
                ['Decided on', d.decided],
                ['Decision note', d.note],
            ].filter(r => r[1] !== undefined && r[1] !== '');

            const html = rows.map(r =>
                '<div class="k">' + escapeHtml(r[0]) + '</div>' +
                '<div class="v">' + escapeHtml(r[1]) + '</div>'
            ).join('');
            document.getElementById('vmBody').innerHTML = html || '<div class="k">—</div><div class="v">No details</div>';

            const chip = document.getElementById('vmStatus');
            chip.className = 'chip-status ' + (d.statusColor || 'gray');
            chip.innerHTML = '<span class="dot"></span> ' + escapeHtml(d.status || '—');

            openModal(viewModal);
        });
    });

    function openDecideModal(mode, id, name) {
        const isAdmit = mode === 'admit';
        document.getElementById('dAppId').value    = id;
        document.getElementById('dDecision').value = isAdmit ? 'approved' : 'rejected';
        document.getElementById('dTitle').textContent = isAdmit ? 'Admit application' : 'Reject application';
        document.getElementById('dSub').textContent   = isAdmit
            ? 'The student will be marked as admitted and an email will be sent.'
            : 'The student will be marked as rejected and an email will be sent.';

        const icon = document.getElementById('dIcon');
        icon.className = 'dm-ico ' + (isAdmit ? 'green' : 'red');
        icon.innerHTML = isAdmit ? '<i class="fas fa-circle-check"></i>' : '<i class="fas fa-circle-xmark"></i>';

        document.getElementById('dStudent').innerHTML =
            'You are about to <strong>' + (isAdmit ? 'admit' : 'reject') + '</strong> the application of <strong>' + escapeHtml(name) + '</strong>. The decision email will be sent to the address on file.';

        const confirmBtn = document.getElementById('dConfirm');
        confirmBtn.className = isAdmit ? 'success' : 'danger';
        confirmBtn.textContent = isAdmit ? 'Confirm admit' : 'Confirm reject';

        document.getElementById('dNote').value = '';
        openModal(decideModal);
    }

    document.querySelectorAll('.act-admit').forEach(btn => {
        btn.addEventListener('click', function () {
            if (this.disabled) return;
            openDecideModal('admit', this.dataset.id, this.dataset.name);
        });
    });
    document.querySelectorAll('.act-reject').forEach(btn => {
        btn.addEventListener('click', function () {
            if (this.disabled) return;
            openDecideModal('reject', this.dataset.id, this.dataset.name);
        });
    });
</script>

</body>
</html>