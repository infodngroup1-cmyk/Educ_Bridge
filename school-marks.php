<?php
require_once 'db.php';
require_once '_school-auth.php';

/*
|--------------------------------------------------------------------------
| AUTO-CREATE marks_config TABLE
|--------------------------------------------------------------------------
*/
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS marks_config (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    school_id       INT NOT NULL,
    academic_year   VARCHAR(20)  NOT NULL,
    term            ENUM('term_1','term_2','term_3') NOT NULL,
    levels          VARCHAR(120) NOT NULL DEFAULT '',
    class_ids       TEXT         NOT NULL DEFAULT '',
    all_classes     TINYINT(1)   NOT NULL DEFAULT 0,
    all_terms       TINYINT(1)   NOT NULL DEFAULT 0,
    mode            ENUM('closed','open','scheduled') NOT NULL DEFAULT 'closed',
    opens_at        DATETIME DEFAULT NULL,
    closes_at       DATETIME DEFAULT NULL,
    note            VARCHAR(500) DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_marks_year_term (school_id, academic_year, term),
    KEY idx_marks_school (school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* Safe migrations */
@mysqli_query($conn, "ALTER TABLE marks_config ADD COLUMN all_classes TINYINT(1) NOT NULL DEFAULT 0 AFTER class_ids");
@mysqli_query($conn, "ALTER TABLE marks_config ADD COLUMN all_terms   TINYINT(1) NOT NULL DEFAULT 0 AFTER all_classes");

/*
|--------------------------------------------------------------------------
| FETCH CLASSES
|--------------------------------------------------------------------------
*/
$classes_all = [];
$cstmt = mysqli_prepare($conn, "
    SELECT id, class_name, level, academic_year, capacity, status
    FROM classes
    WHERE school_id = ?
    ORDER BY level ASC, class_name ASC
");
if ($cstmt) {
    mysqli_stmt_bind_param($cstmt, "i", $school_id);
    mysqli_stmt_execute($cstmt);
    $cres = mysqli_stmt_get_result($cstmt);
    while ($c = mysqli_fetch_assoc($cres)) $classes_all[] = $c;
    mysqli_stmt_close($cstmt);
}

$distinct_years = [];
foreach ($classes_all as $c) {
    if (!empty($c['academic_year']) && !in_array($c['academic_year'], $distinct_years, true)) {
        $distinct_years[] = $c['academic_year'];
    }
}
sort($distinct_years);

$classes_by_level = [];
foreach ($classes_all as $c) {
    $classes_by_level[$c['level']][] = $c;
}

$level_map = ['Nursery'=>'Nursery','Primary'=>'Primary','O_Level'=>"O' Level",'A_Level'=>"A' Level"];
$level_colors = ['Nursery'=>'pink','Primary'=>'blue','O_Level'=>'green','A_Level'=>'orange'];
$term_labels = ['term_1'=>'Term 1','term_2'=>'Term 2','term_3'=>'Term 3'];
$ALL_TERMS = ['term_1','term_2','term_3'];

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function class_group_name($name) {
    $n = trim((string)$name);
    if ($n === '') return '';
    $parts = preg_split('/\s+/', $n);
    $first = $parts[0] ?? '';
    if (strcasecmp($first, 'Primary') === 0 && isset($parts[1]) && ctype_digit($parts[1])) {
        return $first . ' ' . $parts[1];
    }
    if (strcasecmp($first, 'Nursery') === 0) return 'Nursery';
    if (preg_match('/[0-9]/', $first)) return $first;
    return $first;
}

function marks_config_status($row) {
    if ($row['mode'] === 'closed') return ['Closed','red'];
    if ($row['mode'] === 'open')   return ['Open','green'];
    if ($row['mode'] === 'scheduled') {
        $now = time();
        $o = $row['opens_at']  ? strtotime($row['opens_at'])  : null;
        $c = $row['closes_at'] ? strtotime($row['closes_at']) : null;
        if ($o && $c) {
            if ($now < $o) return ['Scheduled (future)','orange'];
            if ($now > $c) return ['Closed (past)','red'];
            return ['Open (auto)','green'];
        }
    }
    return ['Unknown','gray'];
}

function dt_local($v) {
    if (empty($v)) return '';
    $ts = strtotime($v);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}

/*
|--------------------------------------------------------------------------
| HANDLE SAVE — creates OR updates; latest wins on duplicates
|--------------------------------------------------------------------------
*/
$message = ""; $message_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_marks_config') {

    $edit_id = (int)($_POST['edit_id'] ?? 0);
    $year    = trim($_POST['academic_year'] ?? '');
    $mode    = trim($_POST['mode'] ?? 'closed');
    $note    = trim($_POST['note'] ?? '');
    $opens   = trim($_POST['opens_at'] ?? '');
    $closes  = trim($_POST['closes_at'] ?? '');

    $terms_in   = isset($_POST['terms'])     && is_array($_POST['terms'])     ? $_POST['terms']     : [];
    $levels_in  = isset($_POST['levels'])    && is_array($_POST['levels'])    ? $_POST['levels']    : [];
    $classes_in = isset($_POST['class_ids']) && is_array($_POST['class_ids']) ? $_POST['class_ids'] : [];
    $all_classes = isset($_POST['all_classes']) && $_POST['all_classes'] == '1' ? 1 : 0;
    $all_terms   = isset($_POST['all_terms'])   && $_POST['all_terms']   == '1' ? 1 : 0;

    $errors = [];

    if ($year === '' || !preg_match('/^\d{4}(\s*-\s*\d{4})?$/', $year)) {
        $errors[] = "Please enter a valid academic year (YYYY or YYYY-YYYY).";
    }
    $valid_terms = ['term_1','term_2','term_3'];
    $terms_clean = array_values(array_intersect(array_map('trim', $terms_in), $valid_terms));
    if ($all_terms === 1) {
        $terms_clean = $ALL_TERMS;
    }
    if (empty($terms_clean)) {
        $errors[] = "Select at least one term.";
    }
    if (!in_array($mode, ['closed','open','scheduled'], true)) {
        $errors[] = "Please select a valid mode.";
    }

    $valid_levels = ['Nursery','Primary','O_Level','A_Level'];
    $levels_clean = array_values(array_intersect(array_map('trim', $levels_in), $valid_levels));
    if (empty($levels_clean)) {
        $errors[] = "Select at least one level.";
    }

    $class_ids_int = array_values(array_unique(array_filter(array_map('intval', $classes_in))));

    if ($all_classes === 1) {
        $auto = [];
        foreach ($classes_all as $c) {
            if (in_array($c['level'], $levels_clean, true)) $auto[] = (int)$c['id'];
        }
        if (empty($auto)) {
            $errors[] = "There are no classes for the selected levels.";
        } else {
            $class_ids_int = $auto;
        }
    } elseif (empty($class_ids_int)) {
        $errors[] = "Select at least one class (or tick 'All classes').";
    }

    if (empty($errors)) {
        $ph = implode(',', array_fill(0, count($class_ids_int), '?'));
        $tc = "i" . str_repeat('i', count($class_ids_int));
        $params = array_merge([$school_id], $class_ids_int);
        $vst = mysqli_prepare($conn, "SELECT id, level FROM classes WHERE school_id = ? AND id IN ($ph)");
        if ($vst) {
            mysqli_stmt_bind_param($vst, $tc, ...$params);
            mysqli_stmt_execute($vst);
            $vres = mysqli_stmt_get_result($vst);
            $found = [];
            while ($v = mysqli_fetch_assoc($vres)) $found[$v['id']] = $v['level'];
            mysqli_stmt_close($vst);

            if (count($found) !== count($class_ids_int)) {
                $errors[] = "One or more selected classes don't belong to your school.";
            } else {
                foreach ($found as $cid => $lvl) {
                    if (!in_array($lvl, $levels_clean, true)) {
                        $errors[] = "Class #{$cid} is at a level you did not select.";
                        break;
                    }
                }
            }
        }
    }

    $opens_at = null; $closes_at = null;
    if ($mode === 'scheduled') {
        if ($opens === '' || $closes === '') {
            $errors[] = "Both opens at and closes at are required for scheduled mode.";
        } else {
            $ots = strtotime($opens);
            $cts = strtotime($closes);
            if (!$ots || !$cts) $errors[] = "Invalid scheduled dates.";
            elseif ($cts <= $ots) $errors[] = "Closes at must be after opens at.";
            else {
                $opens_at  = date('Y-m-d H:i:s', $ots);
                $closes_at = date('Y-m-d H:i:s', $cts);
            }
        }
    }

    if (!empty($errors)) {
        $message = implode(" ", $errors);
        $message_type = "error";
    } else {
        $levels_str  = implode(',', $levels_clean);
        $classes_str = implode(',', $class_ids_int);

        if ($edit_id > 0) {
            /* ---- EDIT an existing row (identity = year + term) ---- */
            $own = mysqli_prepare($conn, "SELECT id, academic_year, term FROM marks_config WHERE id = ? AND school_id = ? LIMIT 1");
            mysqli_stmt_bind_param($own, "ii", $edit_id, $school_id);
            mysqli_stmt_execute($own);
            $own_res = mysqli_stmt_get_result($own);
            $orig = mysqli_fetch_assoc($own_res);
            mysqli_stmt_close($own);

            if (!$orig) {
                $message = "You can only edit configurations that belong to your school.";
                $message_type = "error";
            } else {
                $single_term = $orig['term']; // keep the original term for that row

                // Handle possible duplicate: if another row has (year, term) and is not this one
                $chk = mysqli_prepare($conn, "SELECT id FROM marks_config WHERE school_id = ? AND academic_year = ? AND term = ? AND id <> ? LIMIT 1");
                mysqli_stmt_bind_param($chk, "issi", $school_id, $year, $single_term, $edit_id);
                mysqli_stmt_execute($chk);
                $chk_res = mysqli_stmt_get_result($chk);
                if (mysqli_num_rows($chk_res) > 0) {
                    $dup = mysqli_fetch_assoc($chk_res);
                    $dup_id = (int)$dup['id'];
                    mysqli_stmt_close($chk);
                    /* Latest wins: delete the older duplicate and keep this row's data */
                    $del = mysqli_prepare($conn, "DELETE FROM marks_config WHERE id = ? AND school_id = ?");
                    mysqli_stmt_bind_param($del, "ii", $dup_id, $school_id);
                    mysqli_stmt_execute($del);
                    mysqli_stmt_close($del);
                } else {
                    mysqli_stmt_close($chk);
                }

                $upd = mysqli_prepare($conn, "
                    UPDATE marks_config
                    SET academic_year = ?, levels = ?, class_ids = ?, all_classes = ?, all_terms = ?,
                        mode = ?, opens_at = ?, closes_at = ?, note = ?
                    WHERE id = ? AND school_id = ?
                ");
                mysqli_stmt_bind_param(
                    $upd, "sssiissssii",
                    $year, $levels_str, $classes_str, $all_classes, $all_terms,
                    $mode, $opens_at, $closes_at, $note, $edit_id, $school_id
                );

                if (mysqli_stmt_execute($upd)) {
                    mysqli_stmt_close($upd);
                    header("Location: school-marks.php?updated=1");
                    exit;
                } else {
                    $message = "Could not update: " . mysqli_error($conn);
                    $message_type = "error";
                    mysqli_stmt_close($upd);
                }
            }
        } else {
            /* ---- INSERT / UPDATE per term (latest wins via ON DUPLICATE KEY) ---- */
            $upd = mysqli_prepare($conn, "
                INSERT INTO marks_config
                    (school_id, academic_year, term, levels, class_ids, all_classes, all_terms,
                     mode, opens_at, closes_at, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    levels      = VALUES(levels),
                    class_ids   = VALUES(class_ids),
                    all_classes = VALUES(all_classes),
                    all_terms   = VALUES(all_terms),
                    mode        = VALUES(mode),
                    opens_at    = VALUES(opens_at),
                    closes_at   = VALUES(closes_at),
                    note        = VALUES(note)
            ");

            $any_fail = false;
            foreach ($terms_clean as $t) {
                mysqli_stmt_bind_param(
                    $upd, "issssiissss",
                    $school_id, $year, $t, $levels_str, $classes_str, $all_classes, $all_terms,
                    $mode, $opens_at, $closes_at, $note
                );
                if (!mysqli_stmt_execute($upd)) {
                    $any_fail = true;
                    $message = "Could not save for term {$t}: " . mysqli_error($conn);
                    $message_type = "error";
                    break;
                }
            }
            mysqli_stmt_close($upd);

            if (!$any_fail) {
                header("Location: school-marks.php?saved=" . count($terms_clean));
                exit;
            }
        }
    }
}

if (isset($_GET['saved'])) {
    $n = (int)$_GET['saved'];
    $message = "Marks configuration saved for " . ($n > 0 ? $n : 1) . " term(s).";
    $message_type = "success";
}
if (isset($_GET['updated'])) { $message = "Marks configuration updated successfully."; $message_type = "success"; }
if (isset($_GET['deleted'])) { $message = "Marks configuration removed.";              $message_type = "success"; }

/* Delete */
if (($_GET['delete'] ?? '') !== '' && ctype_digit((string)$_GET['delete'])) {
    $did = (int)$_GET['delete'];
    $d = mysqli_prepare($conn, "DELETE FROM marks_config WHERE id = ? AND school_id = ?");
    mysqli_stmt_bind_param($d, "ii", $did, $school_id);
    mysqli_stmt_execute($d);
    mysqli_stmt_close($d);
    header("Location: school-marks.php?deleted=1");
    exit;
}

/*
|--------------------------------------------------------------------------
| FETCH EXISTING CONFIGS — group by academic year for display
|--------------------------------------------------------------------------
*/
$raw_configs = [];
$lst = mysqli_prepare($conn, "SELECT * FROM marks_config WHERE school_id = ? ORDER BY academic_year DESC, term ASC, id DESC");
if ($lst) {
    mysqli_stmt_bind_param($lst, "i", $school_id);
    mysqli_stmt_execute($lst);
    $lres = mysqli_stmt_get_result($lst);
    while ($r = mysqli_fetch_assoc($lres)) $raw_configs[] = $r;
    mysqli_stmt_close($lst);
}

/*
| Group by academic year (keeping each term as a separate entry but noting if all terms exist)
*/
$grouped = []; // year => ['terms' => [term=>row], 'rows' => [...], 'all_terms'=>bool]
foreach ($raw_configs as $r) {
    $y = $r['academic_year'];
    if (!isset($grouped[$y])) $grouped[$y] = ['rows' => [], 'terms' => []];
    $grouped[$y]['rows'][] = $r;
    $grouped[$y]['terms'][$r['term']] = $r;
}
ksort($grouped);
$grouped = array_reverse($grouped, true);

/* Summary counts */
$c_open = 0; $c_scheduled = 0; $c_closed = 0;
foreach ($raw_configs as $cf) {
    $st = marks_config_status($cf);
    if ($st[0] === 'Open' || $st[0] === 'Open (auto)') $c_open++;
    elseif (strpos($st[0], 'Scheduled') === 0) $c_scheduled++;
    else $c_closed++;
}
$configs = $raw_configs;

$class_by_id = [];
foreach ($classes_all as $c) $class_by_id[(int)$c['id']] = $c;

/*
|--------------------------------------------------------------------------
| EDIT MODE
|--------------------------------------------------------------------------
*/
$edit_cfg = null;
if (($_GET['edit'] ?? '') !== '' && ctype_digit((string)$_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $est = mysqli_prepare($conn, "SELECT * FROM marks_config WHERE id = ? AND school_id = ? LIMIT 1");
    if ($est) {
        mysqli_stmt_bind_param($est, "ii", $eid, $school_id);
        mysqli_stmt_execute($est);
        $eres = mysqli_stmt_get_result($est);
        if ($row = mysqli_fetch_assoc($eres)) $edit_cfg = $row;
        mysqli_stmt_close($est);
    }
}

$preset = [
    'id'            => $edit_cfg['id']             ?? 0,
    'academic_year' => $edit_cfg['academic_year']  ?? '',
    'terms'         => $edit_cfg ? [$edit_cfg['term']] : ['term_1'],
    'levels'        => $edit_cfg ? array_filter(array_map('trim', explode(',', (string)$edit_cfg['levels'])))     : [],
    'class_ids'     => $edit_cfg ? array_filter(array_map('intval', explode(',', (string)$edit_cfg['class_ids']))) : [],
    'all_classes'   => $edit_cfg ? (int)$edit_cfg['all_classes'] : 0,
    'all_terms'     => $edit_cfg ? (int)$edit_cfg['all_terms']   : 0,
    'mode'          => $edit_cfg['mode']           ?? 'closed',
    'opens_at'      => $edit_cfg['opens_at']       ?? '',
    'closes_at'     => $edit_cfg['closes_at']      ?? '',
    'note'          => $edit_cfg['note']           ?? '',
];

/* Helper to describe a row for the grouped view */
function describe_row($row, $class_by_id, $level_map, $term_labels, $ALL_TERMS) {
    $lvs    = array_filter(array_map('trim', explode(',', (string)$row['levels'])));
    $cids   = array_filter(array_map('intval', explode(',', (string)$row['class_ids'])));
    $allC   = (int)$row['all_classes'] === 1;
    $allT   = (int)$row['all_terms'] === 1;
    return [
        'levels' => $lvs,
        'class_count' => count($cids),
        'all_classes' => $allC,
        'all_terms' => $allT,
    ];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marks Setup | Educ Bridge</title>
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
            --purple: #7c3aed; --purple-light: rgba(124,58,237,0.12);
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
            --purple: #a78bfa; --purple-light: rgba(124,58,237,0.18);
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
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.4);
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.5);
            --shadow-sidebar: 0 0 30px rgba(0,0,0,0.4);
        }
        *,*::before,*::after { margin:0; padding:0; box-sizing:border-box; }
        html, body { min-height: 100vh; }
        body { font-family:'Inter',sans-serif; background:var(--bg-body); color:var(--text-primary); transition:background 0.4s ease, color 0.4s ease; overflow-x:hidden; }
        .layout { display:flex; min-height:100vh; }

        .sidebar { width:260px; background:var(--sidebar-bg); color:var(--sidebar-text); display:flex; flex-direction:column; position:fixed; top:0; left:0; height:100vh; z-index:100; transition:var(--transition); border-right:1px solid var(--sidebar-border); box-shadow:var(--shadow-sidebar); }
        .sidebar-header { padding:22px 22px 18px; border-bottom:1px solid var(--sidebar-border); display:flex; align-items:center; gap:12px; }
        .sidebar-header img { width:42px; height:42px; object-fit:contain; border-radius:50%; border:2px solid var(--sidebar-border); padding:2px; background:#fff; }
        .sidebar-brand { font-family:'Poppins',sans-serif; font-size:18px; font-weight:700; color:var(--text-primary); line-height:1.2; }
        .sidebar-brand span { color:var(--primary); }
        .sidebar-brand small { display:block; font-size:11px; font-weight:500; color:var(--text-muted); letter-spacing:0.4px; margin-top:2px; text-transform:uppercase; }
        .sidebar-nav { flex:1; padding:18px 12px; overflow-y:auto; }
        .nav-section-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; color:var(--sidebar-title); padding:12px 14px 8px; }
        .nav-item { display:flex; align-items:center; gap:12px; padding:11px 14px; color:var(--sidebar-text); text-decoration:none; font-size:14px; font-weight:500; border-radius:10px; margin-bottom:4px; transition:var(--transition); position:relative; }
        .nav-item i.nav-icon { width:20px; text-align:center; font-size:15px; }
        .nav-item:hover { background:var(--sidebar-hover); color:var(--sidebar-text-active); }
        .nav-item:hover i.nav-icon { color:var(--primary); }
        .nav-item.active { background:var(--sidebar-active); color:var(--sidebar-text-active); }
        .nav-item.active i.nav-icon { color:var(--primary); }
        .nav-item.active::before { content:''; position:absolute; left:0; top:8px; bottom:8px; width:3px; background:var(--primary); border-radius:0 4px 4px 0; }
        .sidebar-profile { border-top:1px solid var(--sidebar-border); padding:16px 18px; display:flex; align-items:center; gap:12px; }
        .sidebar-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Poppins',sans-serif; font-size:15px; font-weight:700; flex-shrink:0; }
        .sidebar-profile-info { flex:1; min-width:0; }
        .sidebar-profile-info .p-name { font-size:13.5px; font-weight:600; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sidebar-profile-info .p-role { font-size:11.5px; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sidebar-logout { width:34px; height:34px; border-radius:8px; background:var(--danger-light); color:var(--danger); display:flex; align-items:center; justify-content:center; text-decoration:none; font-size:13px; flex-shrink:0; transition:var(--transition); }
        .sidebar-logout:hover { background:var(--danger); color:#fff; }

        .main { flex:1; margin-left:260px; min-height:100vh; display:flex; flex-direction:column; transition:margin-left 0.3s ease; }
        .topbar { background:var(--bg-card); border-bottom:1px solid var(--border-color); padding:14px 28px; display:flex; align-items:center; justify-content:space-between; gap:16px; position:sticky; top:0; z-index:50; }
        .topbar-left { display:flex; align-items:center; gap:14px; }
        .hamburger { display:none; width:40px; height:40px; border-radius:10px; border:1.5px solid var(--border-color); background:var(--bg-body); color:var(--text-secondary); align-items:center; justify-content:center; cursor:pointer; font-size:15px; }
        .hamburger:hover { border-color:var(--primary); color:var(--primary); }
        .page-title { font-family:'Poppins',sans-serif; font-size:20px; font-weight:700; letter-spacing:-0.4px; }
        .page-subtitle { font-size:13px; color:var(--text-muted); margin-top:2px; }
        .topbar-actions { display:flex; align-items:center; gap:10px; }
        .topbar-btn { width:42px; height:42px; border-radius:50%; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; transition:var(--transition); text-decoration:none; }
        .topbar-btn:hover { border-color:var(--primary); color:var(--primary); transform:translateY(-1px); }
        .topbar-btn.theme-btn:hover { transform:rotate(20deg); }
        .topbar-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Poppins',sans-serif; font-size:14px; font-weight:700; box-shadow:0 4px 12px rgba(59,130,246,0.3); cursor:pointer; }

        .content { padding:28px; flex:1; }

        .back-link { display:inline-flex; align-items:center; gap:8px; color:var(--primary); text-decoration:none; font-size:14px; font-weight:600; margin-bottom:18px; transition:var(--transition); }
        .back-link:hover { color:var(--primary-dark); gap:11px; }

        .alert { padding:13px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; display:flex; align-items:flex-start; gap:10px; line-height:1.5; }
        .alert-success { background: var(--success-light); color:#059669; border:1px solid rgba(16,185,129,0.25); }
        [data-theme="dark"] .alert-success { color:#34d399; }
        .alert-error { background: var(--danger-light); color:#dc2626; border:1px solid rgba(239,68,68,0.25); }
        [data-theme="dark"] .alert-error { color:#f87171; }
        .alert-info { background: var(--primary-light); color: var(--primary); border:1px solid rgba(37,99,235,0.25); }

        .page-head { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:22px; flex-wrap:wrap; }
        .page-head h1 { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; display:flex; align-items:center; gap:10px; }
        .page-head h1 i { color:var(--primary); }
        .page-head .sub { font-size:13.5px; color:var(--text-muted); margin-top:4px; }

        .summaries { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:16px; margin-bottom:22px; }
        .sum-card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:16px; padding:18px 20px; display:flex; align-items:center; gap:14px; transition:var(--transition); }
        .sum-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); border-color:var(--primary); }
        .sum-icon { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; color:#fff; flex-shrink:0; }
        .sum-icon.blue   { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .sum-icon.green  { background: linear-gradient(135deg, #10b981, #34d399); }
        .sum-icon.orange { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .sum-icon.red    { background: linear-gradient(135deg, #ef4444, #f87171); }
        .sum-info .num { font-family:'Poppins',sans-serif; font-size:24px; font-weight:700; line-height:1.2; letter-spacing:-0.4px; }
        .sum-info .lbl { font-size:12.5px; color:var(--text-muted); margin-top:3px; font-weight:500; }

        .card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 18px; overflow: hidden; margin-bottom: 22px; }
        .card-head { padding: 22px 26px; border-bottom: 1px solid var(--border-color); background: linear-gradient(135deg, rgba(37,99,235,0.06), rgba(124,58,237,0.04)); display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
        .card-head h2 { font-family: 'Poppins', sans-serif; font-size: 17px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .card-head h2 i { color: var(--primary); }
        .card-head p { font-size: 13px; color: var(--text-muted); margin-top: 3px; }
        .card-body { padding: 24px 26px; }

        .section-title { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.7px; color:var(--text-muted); margin:4px 0 14px; padding-bottom:8px; border-bottom:1px dashed var(--border-color); display:flex; align-items:center; gap:8px; }
        .section-title i { color:var(--primary); font-size:12px; }
        .section-title .mini-action {
            margin-left: auto;
            font-size: 11px; font-weight: 700;
            color: var(--primary);
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            padding: 3px 10px;
            border-radius: 6px;
            cursor: pointer;
            font-family: inherit;
            transition: var(--transition);
        }
        .section-title .mini-action:hover { background: var(--primary-light); border-color: var(--primary); }

        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
        .form-group { margin-bottom:18px; }
        .form-group label { display:block; font-size:13.5px; font-weight:600; color:var(--text-primary); margin-bottom:8px; }
        .form-group label .required { color:#ef4444; }

        .form-group input, .form-group select, .form-group textarea {
            width:100%; padding:13px 15px;
            border:1.5px solid var(--border-color); border-radius:10px;
            background:var(--bg-alt); color:var(--text-primary);
            font-family:'Inter',sans-serif; font-size:14.5px; outline:none;
            transition:var(--transition);
        }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color:var(--primary); background:var(--bg-card); box-shadow:0 0 0 3px rgba(37,99,235,0.1);
        }
        .form-help { font-size:12.5px; color:var(--text-muted); margin-top:6px; }

        .check-pills { display:flex; flex-wrap:wrap; gap:10px; }
        .check-pill { position:relative; display:inline-flex; align-items:center; gap:8px; padding: 11px 18px; border:1.5px solid var(--border-color); border-radius:10px; cursor:pointer; background: var(--bg-alt); font-size: 13.5px; font-weight:600; color: var(--text-primary); transition: var(--transition); user-select: none; }
        .check-pill input { position:absolute; opacity:0; pointer-events:none; }
        .check-pill .box { width:18px; height:18px; border-radius:6px; border:2px solid var(--border-color); background: var(--bg-card); display:flex; align-items:center; justify-content:center; transition: var(--transition); flex-shrink: 0; }
        .check-pill .box i { font-size:10px; color:#fff; opacity:0; transform: scale(0.4); transition: var(--transition); }
        .check-pill:hover { border-color: var(--primary); }
        .check-pill.selected { border-color: var(--primary); background: var(--primary-light); }
        .check-pill.selected .box { background: var(--primary); border-color: var(--primary); }
        .check-pill.selected .box i { opacity:1; transform: scale(1); }

        .term-pills { display:grid; grid-template-columns: repeat(auto-fit, minmax(140px,1fr)); gap:12px; }
        .term-pill { position:relative; display:flex; align-items:center; gap:12px; padding:16px 18px; border:1.5px solid var(--border-color); border-radius:12px; cursor:pointer; background: var(--bg-alt); transition: var(--transition); user-select: none; }
        .term-pill input { position:absolute; opacity:0; pointer-events:none; }
        .term-pill .box { width:20px; height:20px; border-radius:6px; border:2px solid var(--border-color); background: var(--bg-card); display:flex; align-items:center; justify-content:center; transition: var(--transition); flex-shrink:0; }
        .term-pill .box i { font-size:11px; color:#fff; opacity:0; transform: scale(0.4); transition: var(--transition); }
        .term-pill:hover { border-color: var(--primary); }
        .term-pill.selected { border-color: var(--primary); background: var(--primary-light); }
        .term-pill.selected .box { background: var(--primary); border-color: var(--primary); }
        .term-pill.selected .box i { opacity:1; transform: scale(1); }
        .term-pill .tp-title { font-size:14.5px; font-weight:700; color: var(--text-primary); }
        .term-pill .tp-sub { font-size:11.5px; color: var(--text-muted); margin-top:2px; }

        .period-pills { display:grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr)); gap:12px; }
        .period-pill { position:relative; display:flex; align-items:flex-start; gap:12px; padding:16px 18px; border:1.5px solid var(--border-color); border-radius:12px; cursor:pointer; background: var(--bg-alt); transition: var(--transition); }
        .period-pill input { position:absolute; opacity:0; pointer-events:none; }
        .period-pill .dot { width:18px; height:18px; border-radius:50%; border:2px solid var(--border-color); position:relative; transition:var(--transition); flex-shrink:0; margin-top:2px; }
        .period-pill .dot::after { content:''; position:absolute; inset:3px; border-radius:50%; background: var(--primary); opacity:0; transform: scale(0.4); transition:var(--transition); }
        .period-pill:hover { border-color: var(--primary); }
        .period-pill.selected { border-color: var(--primary); background: var(--primary-light); }
        .period-pill.selected .dot { border-color: var(--primary); }
        .period-pill.selected .dot::after { opacity:1; transform: scale(1); }
        .period-pill .rp-title { font-size:14.5px; font-weight:700; color: var(--text-primary); margin-bottom:4px; display:flex; align-items:center; gap:8px; }
        .period-pill .rp-title i { color: var(--text-muted); font-size: 14px; }
        .period-pill.selected .rp-title i { color: var(--primary); }
        .period-pill .rp-desc { font-size:12.5px; color: var(--text-muted); line-height: 1.5; }

        /* Class groups */
        .class-groups-wrap {
            border: 1.5px solid var(--border-color);
            border-radius: 12px;
            background: var(--bg-alt);
            max-height: 480px;
            overflow-y: auto;
            padding: 14px;
            transition: opacity 0.3s ease;
        }
        .class-groups-empty {
            padding: 40px 20px;
            text-align: center;
            color: var(--text-muted);
            font-size: 13.5px;
            line-height: 1.6;
        }
        .class-groups-empty a { color: var(--primary); font-weight: 700; text-decoration: none; }

        .class-level-block { margin-bottom: 16px; }
        .class-level-block:last-child { margin-bottom: 0; }

        .class-level-header {
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 10px;
            padding: 8px 4px;
            border-bottom: 1px dashed var(--border-color);
        }
        .class-level-header .clh-name {
            font-family: 'Poppins', sans-serif;
            font-size: 13.5px; font-weight: 700;
            color: var(--text-primary);
            display: flex; align-items: center; gap: 8px;
        }
        .class-level-header .clh-name i { color: var(--primary); }
        .class-level-header .clh-count {
            font-size: 11px; font-weight: 700;
            background: var(--bg-card);
            color: var(--text-muted);
            padding: 2px 9px;
            border-radius: 50px;
        }
        .class-level-header .clh-actions { margin-left: auto; display: flex; gap: 6px; }
        .class-level-header .clh-actions button {
            font-size: 11.5px; font-weight: 700;
            color: var(--primary);
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            padding: 3px 10px;
            border-radius: 6px;
            cursor: pointer;
            font-family: inherit;
            transition: var(--transition);
        }
        .class-level-header .clh-actions button:hover { background: var(--primary-light); border-color: var(--primary); }

        .class-group-block { margin-bottom: 12px; padding-left: 4px; }
        .class-group-block:last-child { margin-bottom: 0; }

        .class-group-header {
            display: flex; align-items: center; gap: 10px;
            padding: 6px 10px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 8px;
        }
        .class-group-header .cgh-name {
            font-size: 12.5px; font-weight: 700;
            color: var(--text-primary);
            display: flex; align-items: center; gap: 8px;
        }
        .class-group-header .cgh-name i { color: var(--primary); font-size: 11px; }
        .class-group-header .cgh-count {
            font-size: 10.5px; font-weight: 700;
            background: var(--bg-alt);
            color: var(--text-muted);
            padding: 1px 8px;
            border-radius: 50px;
        }
        .class-group-header .cgh-select {
            margin-left: auto;
            font-size: 11px; font-weight: 700;
            color: var(--primary);
            cursor: pointer;
            padding: 3px 10px;
            border-radius: 6px;
            transition: var(--transition);
            border: none;
            background: transparent;
            font-family: inherit;
        }
        .class-group-header .cgh-select:hover { background: var(--primary-light); }

        .class-items { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 8px; margin-left: 8px; }

        .class-opt {
            position: relative;
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px;
            background: var(--bg-card);
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            cursor: pointer;
            transition: var(--transition);
            user-select: none;
        }
        .class-opt:hover { border-color: var(--primary); }
        .class-opt.selected { border-color: var(--primary); background: var(--primary-light); }
        [data-theme="dark"] .class-opt.selected { background: rgba(59,130,246,0.15); }
        .class-opt input { position: absolute; opacity: 0; pointer-events: none; }
        .class-opt .co-box {
            width: 18px; height: 18px;
            border-radius: 6px;
            border: 2px solid var(--border-color);
            background: var(--bg-card);
            display: flex; align-items: center; justify-content: center;
            transition: var(--transition);
            flex-shrink: 0;
        }
        .class-opt .co-box i { font-size: 10px; color: #fff; opacity: 0; transform: scale(0.4); transition: var(--transition); }
        .class-opt.selected .co-box { background: var(--primary); border-color: var(--primary); }
        .class-opt.selected .co-box i { opacity: 1; transform: scale(1); }
        .class-opt .co-info { flex: 1; min-width: 0; }
        .class-opt .co-name { font-size: 12.5px; font-weight: 700; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .class-opt .co-meta { font-size: 10.5px; color: var(--text-muted); margin-top: 1px; }

        /* All-classes row + All-terms row */
        .all-row {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 14px 16px;
            background: var(--bg-alt);
            border: 1.5px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            transition: var(--transition);
            margin-bottom: 12px;
            position: relative;
        }
        .all-row:hover { border-color: var(--primary); }
        .all-row.on { border-color: var(--primary); background: var(--primary-light); }
        .all-row .box {
            width: 20px; height: 20px;
            border-radius: 6px;
            border: 2px solid var(--border-color);
            background: var(--bg-card);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            margin-top: 1px;
            transition: var(--transition);
        }
        .all-row .box i { font-size: 11px; color: #fff; opacity: 0; transform: scale(0.4); transition: var(--transition); }
        .all-row.on .box { background: var(--primary); border-color: var(--primary); }
        .all-row.on .box i { opacity: 1; transform: scale(1); }
        .all-row input { position: absolute; opacity: 0; pointer-events: none; }
        .all-row .ac-body .ac-title { font-size: 14px; font-weight: 700; color: var(--text-primary); margin-bottom: 2px; }
        .all-row .ac-body .ac-desc { font-size: 12px; color: var(--text-muted); line-height: 1.5; }

        /* Config list */
        .year-block { margin-bottom: 22px; }
        .year-block:last-child { margin-bottom: 0; }
        .year-header {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 14px;
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            margin-bottom: 10px;
        }
        .year-header .yh-name {
            font-family: 'Poppins', sans-serif;
            font-size: 14px; font-weight: 700;
            color: var(--text-primary);
            display: flex; align-items: center; gap: 8px;
        }
        .year-header .yh-name i { color: var(--primary); }
        .year-header .yh-count {
            font-size: 11.5px; font-weight: 700;
            background: var(--bg-card);
            color: var(--text-muted);
            padding: 2px 10px;
            border-radius: 50px;
        }
        .year-header .yh-note {
            margin-left: auto;
            font-size: 11.5px;
            color: var(--text-muted);
        }

        .config-item {
            display: flex; align-items: center; gap: 14px;
            padding: 14px 18px;
            background: var(--bg-card);
            border: 1.5px solid var(--border-color);
            border-radius: 12px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }
        .config-item:last-child { margin-bottom: 0; }
        .config-item .ci-main { flex: 1; min-width: 0; }
        .config-item .ci-title { font-size: 14px; font-weight: 700; color: var(--text-primary); margin-bottom: 3px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .config-item .ci-sub { font-size: 12.5px; color: var(--text-muted); display: flex; flex-wrap: wrap; gap: 10px; }
        .config-item .ci-pills { display: flex; gap: 6px; flex-wrap: wrap; }

        .dots-btn {
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
            flex-shrink: 0;
        }
        .dots-btn:hover, .dots-btn.open {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }
        [data-theme="dark"] .dots-btn:hover,
        [data-theme="dark"] .dots-btn.open {
            background: rgba(59,130,246,0.18);
        }

        .config-menu {
            position: fixed;
            min-width: 180px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            padding: 6px;
            z-index: 10000;
            display: none;
        }
        .config-menu.open { display: block; }
        .config-menu a {
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
        .config-menu a i {
            width: 18px; text-align: center;
            font-size: 13px;
            color: var(--text-muted);
            transition: var(--transition);
        }
        .config-menu a:hover { background: var(--bg-alt); }
        .config-menu a:hover i { color: var(--primary); }
        .config-menu a.danger { color: var(--danger); }
        .config-menu a.danger i { color: var(--danger); }
        .config-menu a.danger:hover { background: var(--danger-light); }
        .config-menu .divider { height: 1px; background: var(--border-color); margin: 4px 6px; }

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

        .btn { display:inline-flex; align-items:center; gap:8px; padding:11px 20px; border-radius:10px; font-size:14px; font-weight:600; text-decoration:none; cursor:pointer; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-primary); transition:var(--transition); font-family:'Inter',sans-serif; }
        .btn:hover { border-color:var(--primary); color:var(--primary); transform:translateY(-1px); }
        .btn.primary { background:var(--primary); color:#fff; border-color:var(--primary); box-shadow:0 6px 18px rgba(37,99,235,0.32); }
        .btn.primary:hover { background:var(--primary-dark); color:#fff; }
        .btn:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }

        .overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:90; backdrop-filter: blur(2px); }
        .overlay.show { display:block; }

        @media (max-width: 900px) {
            .sidebar { transform:translateX(-100%); }
            .sidebar.open { transform:translateX(0); box-shadow:0 0 40px rgba(0,0,0,0.2); }
            .main { margin-left:0; }
            .hamburger { display:flex; }
        }
        @media (max-width: 700px) { .form-row { grid-template-columns:1fr; } }
        @media (max-width: 600px) {
            .content { padding:20px 16px; }
            .topbar { padding:12px 16px; }
            .page-title { font-size:17px; }
            .page-head h1 { font-size:18px; }
            .card-body { padding: 20px; }
            .card-head { padding: 18px 20px; }
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
            <a href="school-applications.php" class="nav-item"><i class="fas fa-user-plus nav-icon"></i> Applications</a>
            <a href="school-marks.php" class="nav-item active"><i class="fas fa-clipboard-list nav-icon"></i> Marks</a>
        </nav>
        <div class="sidebar-profile">
            <div class="sidebar-avatar"><?= htmlspecialchars($initials); ?></div>
            <div class="sidebar-profile-info">
                <div class="p-name"><?= htmlspecialchars($school_admin); ?></div>
                <div class="p-role"><?= htmlspecialchars($short_school); ?></div>
            </div>
            <a href="school-logout.php" class="sidebar-logout" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </aside>

    <div class="overlay" id="overlay"></div>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburger" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <div>
                    <div class="page-title">Marks Setup</div>
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

            <a href="school-settings.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Settings</a>

            <?php if ($message !== ""): ?>
                <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                    <i class="fas <?= $message_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($edit_cfg): ?>
                <div class="alert alert-info">
                    <i class="fas fa-pen-to-square" style="margin-top:2px;"></i>
                    <span>
                        Editing an existing configuration
                        (<strong><?= htmlspecialchars($term_labels[$edit_cfg['term']] ?? $edit_cfg['term']); ?> · <?= htmlspecialchars($edit_cfg['academic_year']); ?></strong>).
                        <a href="school-marks.php" style="color:inherit;font-weight:700;text-decoration:underline;">Cancel</a>
                    </span>
                </div>
            <?php endif; ?>

            <div class="page-head">
                <div>
                    <h1><i class="fas fa-clipboard-list"></i> Marks Entry Setup</h1>
                    <div class="sub">Control which levels, classes and terms can have marks entered, and when.</div>
                </div>
            </div>

            <div class="summaries">
                <div class="sum-card">
                    <div class="sum-icon blue"><i class="fas fa-sliders-h"></i></div>
                    <div class="sum-info"><div class="num"><?= count($configs); ?></div><div class="lbl">Configurations</div></div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon green"><i class="fas fa-lock-open"></i></div>
                    <div class="sum-info"><div class="num"><?= $c_open; ?></div><div class="lbl">Open Now</div></div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon orange"><i class="fas fa-clock"></i></div>
                    <div class="sum-info"><div class="num"><?= $c_scheduled; ?></div><div class="lbl">Scheduled</div></div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon red"><i class="fas fa-lock"></i></div>
                    <div class="sum-info"><div class="num"><?= $c_closed; ?></div><div class="lbl">Closed</div></div>
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <div>
                        <h2><i class="fas fa-cog"></i> <?= $edit_cfg ? 'Edit Configuration' : 'Configure Marks Entry'; ?></h2>
                        <p>Pick an academic year, one or more terms, levels, classes and when entries are accepted.</p>
                    </div>
                </div>
                <div class="card-body">

                    <form method="POST" action="school-marks.php" id="marksForm" autocomplete="off">
                        <input type="hidden" name="action" value="save_marks_config">
                        <input type="hidden" name="edit_id" value="<?= (int)$preset['id']; ?>">

                        <div class="form-row">
                            <div class="form-group">
                                <label for="academic_year">Academic Year <span class="required">*</span></label>
                                <input type="text" id="academic_year" name="academic_year" maxlength="20"
                                       list="yearSuggest"
                                       value="<?= htmlspecialchars($preset['academic_year']); ?>"
                                       placeholder="e.g. 2025-2026" required>
                                <datalist id="yearSuggest">
                                    <?php foreach ($distinct_years as $y): ?>
                                        <option value="<?= htmlspecialchars($y); ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="form-group">
                                <label for="note">Note (optional)</label>
                                <input type="text" id="note" name="note" maxlength="500"
                                       value="<?= htmlspecialchars($preset['note']); ?>"
                                       placeholder="e.g. Marks must be submitted before the last day of the term.">
                            </div>
                        </div>

                        <!-- TERMS -->
                        <div class="section-title">
                            <i class="fas fa-calendar-day"></i> Terms <span class="required" style="color:#ef4444;">*</span>
                            <button type="button" class="mini-action" onclick="selectAllTerms()">Select all</button>
                            <button type="button" class="mini-action" onclick="clearAllTerms()">Clear</button>
                        </div>

                        <label class="all-row <?= (int)$preset['all_terms'] === 1 ? 'on' : ''; ?>" id="allTermsRow">
                            <input type="checkbox" name="all_terms" value="1" <?= (int)$preset['all_terms'] === 1 ? 'checked' : ''; ?>>
                            <span class="box"><i class="fas fa-check"></i></span>
                            <div class="ac-body">
                                <div class="ac-title">All Terms (Term 1 + 2 + 3)</div>
                                <div class="ac-desc">When ticked, this configuration applies to all three terms. Individual term checkboxes below will be ignored.</div>
                            </div>
                        </label>

                        <div class="form-group">
                            <div class="term-pills" id="termPills">
                                <?php foreach (['term_1'=>['Term 1','First term'],'term_2'=>['Term 2','Second term'],'term_3'=>['Term 3','Third term']] as $val => $info):
                                    $checked = in_array($val, $preset['terms'], true) && (int)$preset['all_terms'] !== 1;
                                ?>
                                    <label class="term-pill <?= $checked ? 'selected' : ''; ?>">
                                        <input type="checkbox" name="terms[]" value="<?= $val; ?>" <?= $checked ? 'checked' : ''; ?>>
                                        <span class="box"><i class="fas fa-check"></i></span>
                                        <div>
                                            <div class="tp-title"><?= $info[0]; ?></div>
                                            <div class="tp-sub"><?= $info[1]; ?></div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- LEVELS -->
                        <div class="section-title" style="margin-top:14px;"><i class="fas fa-layer-group"></i> Levels <span class="required" style="color:#ef4444;">*</span></div>
                        <div class="form-group">
                            <div class="check-pills" id="levelPills">
                                <?php foreach (['Nursery'=>'Nursery','Primary'=>'Primary','O_Level'=>"O' Level",'A_Level'=>"A' Level"] as $val => $label):
                                    $checked = in_array($val, $preset['levels'], true);
                                ?>
                                    <label class="check-pill <?= $checked ? 'selected' : ''; ?>">
                                        <input type="checkbox" name="levels[]" value="<?= $val; ?>" <?= $checked ? 'checked' : ''; ?>>
                                        <span class="box"><i class="fas fa-check"></i></span>
                                        <?= htmlspecialchars($label); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- CLASSES -->
                        <div class="section-title" style="margin-top:14px;">
                            <i class="fas fa-list-check"></i> Classes <span class="required" style="color:#ef4444;">*</span>
                        </div>

                        <label class="all-row <?= (int)$preset['all_classes'] === 1 ? 'on' : ''; ?>" id="allClassesRow">
                            <input type="checkbox" name="all_classes" value="1" <?= (int)$preset['all_classes'] === 1 ? 'checked' : ''; ?>>
                            <span class="box"><i class="fas fa-check"></i></span>
                            <div class="ac-body">
                                <div class="ac-title">All classes in the selected levels</div>
                                <div class="ac-desc">When ticked, this configuration applies to every class your school has in the levels above. Individual class selection below is ignored.</div>
                            </div>
                        </label>

                        <div class="form-group">
                            <div class="class-groups-wrap" id="classGroupsWrap">
                                <div class="class-groups-empty">
                                    Tick one or more levels above to see the classes of your school.
                                </div>
                            </div>
                        </div>

                        <!-- PERIOD -->
                        <div class="section-title" style="margin-top:14px;"><i class="fas fa-hourglass-half"></i> When can teachers enter marks? <span class="required" style="color:#ef4444;">*</span></div>

                        <div class="form-group">
                            <div class="period-pills" id="periodPills">
                                <label class="period-pill <?= $preset['mode'] === 'closed' ? 'selected' : ''; ?>">
                                    <input type="radio" name="mode" value="closed" <?= $preset['mode'] === 'closed' ? 'checked' : ''; ?>>
                                    <span class="dot"></span>
                                    <div>
                                        <div class="rp-title"><i class="fas fa-lock"></i> Closed</div>
                                        <div class="rp-desc">Marks entry is completely closed.</div>
                                    </div>
                                </label>
                                <label class="period-pill <?= $preset['mode'] === 'open' ? 'selected' : ''; ?>">
                                    <input type="radio" name="mode" value="open" <?= $preset['mode'] === 'open' ? 'checked' : ''; ?>>
                                    <span class="dot"></span>
                                    <div>
                                        <div class="rp-title"><i class="fas fa-globe"></i> Always Open</div>
                                        <div class="rp-desc">Teachers can enter marks at any time.</div>
                                    </div>
                                </label>
                                <label class="period-pill <?= $preset['mode'] === 'scheduled' ? 'selected' : ''; ?>">
                                    <input type="radio" name="mode" value="scheduled" <?= $preset['mode'] === 'scheduled' ? 'checked' : ''; ?>>
                                    <span class="dot"></span>
                                    <div>
                                        <div class="rp-title"><i class="fas fa-calendar-check"></i> Scheduled</div>
                                        <div class="rp-desc">Opens and closes automatically between two datetimes.</div>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <div class="form-row" id="schedWrap" style="display:<?= $preset['mode'] === 'scheduled' ? '' : 'none'; ?>;">
                            <div class="form-group">
                                <label for="opens_at">Opens At (start)</label>
                                <input type="datetime-local" id="opens_at" name="opens_at" value="<?= htmlspecialchars(dt_local($preset['opens_at'])); ?>">
                            </div>
                            <div class="form-group">
                                <label for="closes_at">Closes At (end)</label>
                                <input type="datetime-local" id="closes_at" name="closes_at" value="<?= htmlspecialchars(dt_local($preset['closes_at'])); ?>">
                            </div>
                        </div>

                        <div style="display:flex;justify-content:flex-end;gap:10px;">
                            <a href="school-marks.php" class="btn"><i class="fas fa-times"></i> <?= $edit_cfg ? 'Cancel' : 'Reset'; ?></a>
                            <button type="submit" class="btn primary" id="saveBtn">
                                <i class="fas fa-save"></i> <?= $edit_cfg ? 'Update Configuration' : 'Save Marks Config'; ?>
                            </button>
                        </div>

                    </form>

                    <?php if (!empty($grouped)): ?>
                        <div class="section-title" style="margin-top:26px;">
                            <i class="fas fa-list"></i> Existing Marks Configurations
                            <span style="margin-left:auto;font-size:11px;color:var(--text-muted);font-weight:600;">Grouped by academic year</span>
                        </div>

                        <?php foreach ($grouped as $year => $bucket):
                            $rows = $bucket['rows'];
                            $terms_present = array_keys($bucket['terms']);
                            sort($terms_present);
                            $all_terms_saved = (count(array_intersect($terms_present, $ALL_TERMS)) === 3);
                        ?>
                            <div class="year-block">
                                <div class="year-header">
                                    <div class="yh-name"><i class="fas fa-calendar-alt"></i> <?= htmlspecialchars($year); ?></div>
                                    <span class="yh-count"><?= count($rows); ?> config<?= count($rows) === 1 ? '' : 's'; ?></span>
                                    <?php if ($all_terms_saved): ?>
                                        <span class="pill green" style="margin-left:6px;">All terms</span>
                                    <?php endif; ?>
                                    <span class="yh-note">Latest first</span>
                                </div>

                                <?php foreach ($rows as $cf):
                                    $st = marks_config_status($cf);
                                    $info = describe_row($cf, $class_by_id, $level_map, $term_labels, $ALL_TERMS);
                                    $term_lbl = $info['all_terms'] ? 'All Terms' : ($term_labels[$cf['term']] ?? $cf['term']);
                                ?>
                                    <div class="config-item">
                                        <div class="ci-main">
                                            <div class="ci-title">
                                                <span><?= htmlspecialchars($term_lbl); ?></span>
                                                <?php if ($info['all_terms']): ?>
                                                    <span class="pill teal">Term 1 + 2 + 3</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="ci-sub">
                                                <span><i class="fas fa-layer-group"></i> Levels: <?= htmlspecialchars(implode(', ', array_map(fn($l)=>$level_map[$l] ?? $l, $info['levels'])) ?: '—'); ?></span>
                                                <span>
                                                    <i class="fas fa-list-check"></i>
                                                    <?= $info['all_classes'] ? 'All classes' : ($info['class_count'] . ' class(es)'); ?>
                                                </span>
                                                <?php if ($cf['mode'] === 'scheduled' && $cf['opens_at']): ?>
                                                    <span><i class="fas fa-hourglass-start"></i> Opens <?= date('M j, Y H:i', strtotime($cf['opens_at'])); ?></span>
                                                <?php endif; ?>
                                                <?php if ($cf['mode'] === 'scheduled' && $cf['closes_at']): ?>
                                                    <span><i class="fas fa-hourglass-end"></i> Closes <?= date('M j, Y H:i', strtotime($cf['closes_at'])); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="ci-pills">
                                            <?php foreach ($info['levels'] as $l): ?>
                                                <span class="pill <?= $level_colors[$l] ?? 'gray'; ?>"><?= htmlspecialchars($level_map[$l] ?? $l); ?></span>
                                            <?php endforeach; ?>
                                            <?php if ($info['all_classes']): ?>
                                                <span class="pill teal">All classes</span>
                                            <?php endif; ?>
                                            <span class="pill <?= $st[1]; ?>"><?= htmlspecialchars($st[0]); ?></span>
                                        </div>
                                        <button type="button" class="dots-btn" data-id="<?= (int)$cf['id']; ?>" title="More">
                                            <i class="fas fa-ellipsis-vertical"></i>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                </div>
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

    // DATA
    const CLASSES_BY_LEVEL = <?= json_encode(array_map(function($arr){
        return array_map(function($c){
            return [
                'id' => (int)$c['id'],
                'name' => $c['class_name'],
                'year' => $c['academic_year'],
                'capacity' => $c['capacity'],
            ];
        }, $arr);
    }, $classes_by_level)); ?>;

    const PRESELECT_CLASS_IDS = <?= json_encode(array_map('intval', $preset['class_ids'])); ?>;
    const PRESELECT_ALL_CLASSES = <?= (int)$preset['all_classes']; ?>;
    const PRESELECT_ALL_TERMS   = <?= (int)$preset['all_terms']; ?>;

    const LEVEL_LABEL = { 'Nursery':'Nursery','Primary':'Primary','O_Level':"O' Level",'A_Level':"A' Level" };
    const LEVEL_ORDER = ['Nursery','Primary','O_Level','A_Level'];

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function classGroupName(name) {
        const n = String(name || '').trim();
        if (!n) return '';
        const parts = n.split(/\s+/);
        const first = parts[0] || '';
        if (/^primary$/i.test(first) && parts[1] && /^\d+$/.test(parts[1])) return first + ' ' + parts[1];
        if (/^nursery$/i.test(first)) return 'Nursery';
        if (/[0-9]/.test(first)) return first;
        return first;
    }

    // CLASS PICKER
    function rebuildClassGroups() {
        const selectedLevels = Array.from(document.querySelectorAll('#levelPills input:checked')).map(c => c.value);
        const wrap = document.getElementById('classGroupsWrap');

        if (selectedLevels.length === 0) {
            wrap.innerHTML = '<div class="class-groups-empty">Tick one or more levels above to see the classes of your school.</div>';
            return;
        }

        let html = '';
        let anyClasses = false;
        const preselected = new Set(PRESELECT_CLASS_IDS.map(String));

        LEVEL_ORDER.forEach(function (lvl) {
            if (!selectedLevels.includes(lvl)) return;
            const classes = CLASSES_BY_LEVEL[lvl] || [];
            if (classes.length === 0) return;
            anyClasses = true;

            const groups = {};
            classes.forEach(function (c) {
                const g = classGroupName(c.name);
                if (!groups[g]) groups[g] = [];
                groups[g].push(c);
            });

            html += '<div class="class-level-block" data-level="' + escapeHtml(lvl) + '">';
            html += '<div class="class-level-header">';
            html += '<div class="clh-name"><i class="fas fa-layer-group"></i> ' + escapeHtml(LEVEL_LABEL[lvl]) + '</div>';
            html += '<span class="clh-count">' + classes.length + '</span>';
            html += '<div class="clh-actions">';
            html += '<button type="button" onclick="selectAllLevel(this, true)">Select all</button>';
            html += '<button type="button" onclick="selectAllLevel(this, false)">Clear</button>';
            html += '</div></div>';

            Object.keys(groups).sort().forEach(function (gname) {
                const items = groups[gname];
                html += '<div class="class-group-block">';
                html += '<div class="class-group-header">';
                html += '<div class="cgh-name"><i class="fas fa-folder-tree"></i> ' + escapeHtml(gname) + '</div>';
                html += '<span class="cgh-count">' + items.length + '</span>';
                html += '<button type="button" class="cgh-select" onclick="selectGroup(this)">Select all</button>';
                html += '</div>';
                html += '<div class="class-items">';
                items.forEach(function (c) {
                    const cid = String(c.id);
                    const checked = preselected.has(cid);
                    html += '<label class="class-opt' + (checked ? ' selected' : '') + '">';
                    html += '<input type="checkbox" name="class_ids[]" value="' + cid + '"' + (checked ? ' checked' : '') + '>';
                    html += '<span class="co-box"><i class="fas fa-check"></i></span>';
                    html += '<div class="co-info">';
                    html += '<div class="co-name">' + escapeHtml(c.name) + '</div>';
                    html += '<div class="co-meta">' + escapeHtml(c.year || '') + (c.capacity ? ' · Cap ' + c.capacity : '') + '</div>';
                    html += '</div></label>';
                });
                html += '</div></div>';
            });
            html += '</div>';
        });

        if (!anyClasses) {
            wrap.innerHTML = '<div class="class-groups-empty">No classes found in your school for the selected levels. <a href="school-add-class.php" target="_blank">Create classes first</a>.</div>';
            return;
        }

        wrap.innerHTML = html;

        wrap.querySelectorAll('.class-opt').forEach(function (opt) {
            opt.addEventListener('click', function () {
                setTimeout(function () {
                    const cb = opt.querySelector('input');
                    opt.classList.toggle('selected', cb.checked);
                    updateGroupButton(opt.closest('.class-group-block'));
                }, 0);
            });
        });
    }

    function updateGroupButton(groupBlock) {
        if (!groupBlock) return;
        const btn = groupBlock.querySelector('.cgh-select');
        if (!btn) return;
        const checkboxes = groupBlock.querySelectorAll('input[type="checkbox"]');
        const allChecked = checkboxes.length > 0 && Array.from(checkboxes).every(cb => cb.checked);
        btn.textContent = allChecked ? 'Clear' : 'Select all';
    }

    document.querySelectorAll('#levelPills .check-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            const cb = pill.querySelector('input');
            setTimeout(function () {
                pill.classList.toggle('selected', cb.checked);
                rebuildClassGroups();
            }, 0);
        });
    });

    function selectAllLevel(btn, on) {
        const levelBlock = btn.closest('.class-level-block');
        if (!levelBlock) return;
        levelBlock.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
            cb.checked = on;
            const lbl = cb.closest('.class-opt');
            if (lbl) lbl.classList.toggle('selected', on);
        });
        levelBlock.querySelectorAll('.class-group-block').forEach(updateGroupButton);
    }
    function selectGroup(btn) {
        const groupBlock = btn.closest('.class-group-block');
        if (!groupBlock) return;
        const checkboxes = groupBlock.querySelectorAll('input[type="checkbox"]');
        const allChecked = checkboxes.length > 0 && Array.from(checkboxes).every(cb => cb.checked);
        checkboxes.forEach(function (cb) {
            cb.checked = !allChecked;
            const lbl = cb.closest('.class-opt');
            if (lbl) lbl.classList.toggle('selected', cb.checked);
        });
        btn.textContent = allChecked ? 'Select all' : 'Clear';
    }

    // TERMS multi-select
    document.querySelectorAll('#termPills .term-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            const cb = pill.querySelector('input');
            setTimeout(function () {
                pill.classList.toggle('selected', cb.checked);
            }, 0);
        });
    });
    function selectAllTerms() {
        document.querySelectorAll('#termPills .term-pill').forEach(function (p) {
            const cb = p.querySelector('input');
            cb.checked = true;
            p.classList.add('selected');
        });
        // Uncheck the All Terms row
        const allT = document.getElementById('allTermsRow');
        const atcb = allT.querySelector('input');
        atcb.checked = false;
        allT.classList.remove('on');
    }
    function clearAllTerms() {
        document.querySelectorAll('#termPills .term-pill').forEach(function (p) {
            const cb = p.querySelector('input');
            cb.checked = false;
            p.classList.remove('selected');
        });
    }

    // ALL TERMS row
    (function () {
        const row = document.getElementById('allTermsRow');
        if (!row) return;
        row.addEventListener('click', function (e) {
            e.preventDefault();
            const cb = row.querySelector('input');
            cb.checked = !cb.checked;
            row.classList.toggle('on', cb.checked);

            // When ON, uncheck individual terms + grey them
            const wrap = document.getElementById('termPills');
            wrap.style.opacity = cb.checked ? '0.55' : '1';
            wrap.style.pointerEvents = cb.checked ? 'none' : 'auto';

            if (cb.checked) clearAllTerms();
        });
        if (PRESELECT_ALL_TERMS === 1) {
            const wrap = document.getElementById('termPills');
            wrap.style.opacity = '0.55';
            wrap.style.pointerEvents = 'none';
        }
    })();

    // ALL CLASSES row
    (function () {
        const row = document.getElementById('allClassesRow');
        if (!row) return;
        row.addEventListener('click', function (e) {
            e.preventDefault();
            const cb = row.querySelector('input');
            cb.checked = !cb.checked;
            row.classList.toggle('on', cb.checked);

            const wrap = document.getElementById('classGroupsWrap');
            wrap.style.opacity = cb.checked ? '0.55' : '1';
            wrap.style.pointerEvents = cb.checked ? 'none' : 'auto';
        });
        if (PRESELECT_ALL_CLASSES === 1) {
            const wrap = document.getElementById('classGroupsWrap');
            wrap.style.opacity = '0.55';
            wrap.style.pointerEvents = 'none';
        }
    })();

    // PERIOD
    document.querySelectorAll('#periodPills .period-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            document.querySelectorAll('#periodPills .period-pill').forEach(p => p.classList.remove('selected'));
            pill.classList.add('selected');
            const radio = pill.querySelector('input');
            if (radio) radio.checked = true;
            document.getElementById('schedWrap').style.display = (radio.value === 'scheduled') ? '' : 'none';
        });
    });

    // THREE-DOT MENU
    (function () {
        const portal = document.getElementById('menuPortal');
        let currentMenu = null;
        let currentBtn  = null;

        function closeMenu() {
            if (currentMenu) { currentMenu.remove(); currentMenu = null; }
            if (currentBtn)  { currentBtn.classList.remove('open'); currentBtn = null; }
        }

        function buildMenu(id, btnRect) {
            const wrap = document.createElement('div');
            wrap.className = 'config-menu open';
            wrap.style.position = 'fixed';
            wrap.style.zIndex = '10000';
            wrap.style.pointerEvents = 'auto';

            wrap.innerHTML =
                '<a href="school-marks.php?edit=' + id + '"><i class="fas fa-pen"></i> Edit</a>' +
                '<div class="divider"></div>' +
                '<a href="school-marks.php?delete=' + id + '" class="danger" ' +
                'onclick="return confirm(\'Delete this configuration?\');"><i class="fas fa-trash"></i> Delete</a>';

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
            const btn = e.target.closest('.dots-btn');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                if (currentBtn === btn) { closeMenu(); return; }
                closeMenu();
                const id = btn.dataset.id;
                const rect = btn.getBoundingClientRect();
                const menu = buildMenu(id, rect);
                btn.classList.add('open');
                currentBtn = btn;
                currentMenu = menu;
                return;
            }
            if (e.target.closest('#menuPortal .config-menu')) return;
            closeMenu();
        });

        window.addEventListener('scroll', closeMenu, { passive: true });
        window.addEventListener('resize', closeMenu);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });
    })();

    rebuildClassGroups();

    document.getElementById('marksForm').addEventListener('submit', function () {
        const btn = document.getElementById('saveBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    });
</script>

</body>
</html>