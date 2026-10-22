<?php
require_once 'db.php';
require_once '_teacher-auth.php';
/*
| After this require:
|   $teacher_id, $teacher_name, $school_id, $school_name, $school_code, $initials, $short_school
| are already defined and the user is guaranteed logged in.
*/

/*
|--------------------------------------------------------------------------
| PARAMS
|--------------------------------------------------------------------------
*/
$class_id = (int)($_GET['class_id'] ?? $_GET['id'] ?? 0);

$att_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $att_date)) $att_date = date('Y-m-d');

$from_date = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to_date   = $_GET['to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) $from_date = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date))   $to_date   = date('Y-m-d');
if ($to_date < $from_date) { $tmp = $from_date; $from_date = $to_date; $to_date = $tmp; }

/*
|--------------------------------------------------------------------------
| LOAD THE CLASS — must belong to this teacher
|--------------------------------------------------------------------------
*/
$class = null;
$stmt = mysqli_prepare($conn, "
    SELECT c.*,
           (SELECT COUNT(*) FROM students s
             WHERE s.class_id = c.id) AS student_count
    FROM classes c
    WHERE c.id = ? AND c.school_id = ? AND c.class_teacher_id = ?
    LIMIT 1
");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "iii", $class_id, $school_id, $teacher_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) $class = $row;
    mysqli_stmt_close($stmt);
}
if (!$class) {
    http_response_code(404);
    ?>
    <!DOCTYPE html><html><head><meta charset="utf-8"><title>Not found</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
    <style>
        body{font-family:Inter,sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
        .box{background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:36px 30px;text-align:center;max-width:440px;box-shadow:0 20px 45px rgba(0,0,0,.08)}
        .ico{width:64px;height:64px;border-radius:50%;background:rgba(239,68,68,.1);color:#ef4444;display:inline-flex;align-items:center;justify-content:center;font-size:26px;margin-bottom:16px}
        h1{font-family:Poppins;font-size:20px;margin-bottom:8px}p{color:#64748b;font-size:14px;margin-bottom:20px;line-height:1.6}
        a{display:inline-block;padding:11px 20px;background:#2563eb;color:#fff;text-decoration:none;border-radius:10px;font-weight:700;font-size:14px}
    </style></head><body>
    <div class="box"><div class="ico"><i class="fas fa-triangle-exclamation"></i></div>
    <h1>Class not found</h1><p>This class doesn't belong to you, or it doesn't exist.</p>
    <a href="teacher-classes.php"><i class="fas fa-arrow-left"></i> Back to My Classes</a></div></body></html>
    <?php exit;
}

/*
|--------------------------------------------------------------------------
| ATTENDANCE TABLE — ensure it exists
|--------------------------------------------------------------------------
*/
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS attendance (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    school_id   INT NOT NULL,
    class_id    INT NOT NULL,
    student_id  INT NOT NULL,
    teacher_id  INT NOT NULL,
    att_date    DATE NOT NULL,
    status      ENUM('present','absent','late','excused') NOT NULL,
    note        VARCHAR(255) DEFAULT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_att (class_id, student_id, att_date),
    KEY idx_att_school (school_id),
    KEY idx_att_date   (att_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/*
|--------------------------------------------------------------------------
| POST — SAVE ATTENDANCE FOR THE CHOSEN DATE
|--------------------------------------------------------------------------
*/
$flash = ""; $flash_type = "success";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_attendance') {

    $posted_date = $_POST['att_date'] ?? $att_date;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $posted_date)) $posted_date = date('Y-m-d');

    $allowed_ids = [];
    $chk = mysqli_prepare($conn, "SELECT id FROM students WHERE class_id = ?");
    if ($chk) {
        mysqli_stmt_bind_param($chk, "i", $class_id);
        mysqli_stmt_execute($chk);
        $r = mysqli_stmt_get_result($chk);
        while ($x = mysqli_fetch_assoc($r)) $allowed_ids[(int)$x['id']] = true;
        mysqli_stmt_close($chk);
    }

    $rows = $_POST['attendance'] ?? [];
    $upd = mysqli_prepare($conn, "
        INSERT INTO attendance (school_id, class_id, student_id, teacher_id, att_date, status)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), teacher_id = VALUES(teacher_id)
    ");
    $saved = 0;
    if ($upd) {
        foreach ($rows as $sid => $status) {
            $sid = (int)$sid;
            if (!isset($allowed_ids[$sid])) continue;
            $status = strtolower((string)$status);
            if (!in_array($status, ['present','absent','late','excused'], true)) continue;

            mysqli_stmt_bind_param($upd, "iiiiss",
                $school_id, $class_id, $sid, $teacher_id, $posted_date, $status);
            mysqli_stmt_execute($upd);
            $saved++;
        }
        mysqli_stmt_close($upd);
    }

    if ($saved > 0) {
        header("Location: teacher-attendance.php?class_id={$class_id}&date=" . urlencode($posted_date) . "&from=" . urlencode($from_date) . "&to=" . urlencode($to_date) . "&saved=1");
        exit;
    }
    $flash = "Nothing to save.";
    $flash_type = "error";
}

if (($_GET['saved'] ?? '') === '1' && $flash === '') {
    $flash = "Attendance saved for " . htmlspecialchars($att_date) . ".";
    $flash_type = "success";
}

/*
|--------------------------------------------------------------------------
| LOAD STUDENTS of this class
|--------------------------------------------------------------------------
*/
$students = [];
$st = mysqli_prepare($conn, "
    SELECT id, student_code, first_name, last_name, email
    FROM students
    WHERE class_id = ?
    ORDER BY first_name ASC, last_name ASC
");
if ($st) {
    mysqli_stmt_bind_param($st, "i", $class_id);
    mysqli_stmt_execute($st);
    $r = mysqli_stmt_get_result($st);
    while ($row = mysqli_fetch_assoc($r)) $students[] = $row;
    mysqli_stmt_close($st);
}

/*
|--------------------------------------------------------------------------
| EXISTING ATTENDANCE FOR THE CHOSEN DATE
|--------------------------------------------------------------------------
*/
$existing = [];
if (!empty($students)) {
    $ids = array_map(fn($x) => (int)$x['id'], $students);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $types = "is" . str_repeat('i', count($ids));
    $params = array_merge([$class_id, $att_date], $ids);

    $q = mysqli_prepare($conn, "
        SELECT student_id, status FROM attendance
        WHERE class_id = ? AND att_date = ? AND student_id IN ($ph)
    ");
    if ($q) {
        mysqli_stmt_bind_param($q, $types, ...$params);
        mysqli_stmt_execute($q);
        $qr = mysqli_stmt_get_result($q);
        while ($row = mysqli_fetch_assoc($qr)) $existing[(int)$row['student_id']] = $row['status'];
        mysqli_stmt_close($q);
    }
}

/*
|--------------------------------------------------------------------------
| SUMMARY BY DATE FOR THE CHOSEN RANGE
|--------------------------------------------------------------------------
*/
$summary = [];
if (!empty($students)) {
    $q = mysqli_prepare($conn, "
        SELECT att_date,
               SUM(status = 'present') AS present_cnt,
               SUM(status = 'absent')  AS absent_cnt,
               SUM(status = 'late')    AS late_cnt,
               SUM(status = 'excused') AS excused_cnt,
               COUNT(*)                AS total_cnt
        FROM attendance
        WHERE class_id = ? AND att_date BETWEEN ? AND ?
        GROUP BY att_date
        ORDER BY att_date DESC
    ");
    if ($q) {
        mysqli_stmt_bind_param($q, "iss", $class_id, $from_date, $to_date);
        mysqli_stmt_execute($q);
        $qr = mysqli_stmt_get_result($q);
        while ($row = mysqli_fetch_assoc($qr)) $summary[] = $row;
        mysqli_stmt_close($q);
    }
}

$summary_was_empty = empty($summary);
if ($summary_was_empty) {
    $q = mysqli_prepare($conn, "
        SELECT att_date,
               SUM(status = 'present') AS present_cnt,
               SUM(status = 'absent')  AS absent_cnt,
               SUM(status = 'late')    AS late_cnt,
               SUM(status = 'excused') AS excused_cnt,
               COUNT(*)                AS total_cnt
        FROM attendance
        WHERE class_id = ?
        GROUP BY att_date
        ORDER BY att_date DESC
        LIMIT 30
    ");
    if ($q) {
        mysqli_stmt_bind_param($q, "i", $class_id);
        mysqli_stmt_execute($q);
        $qr = mysqli_stmt_get_result($q);
        while ($row = mysqli_fetch_assoc($qr)) $summary[] = $row;
        mysqli_stmt_close($q);
    }
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function initials_from_name($first, $last) {
    $f = trim((string)$first);
    $l = trim((string)$last);
    $out = '';
    if ($f !== '') $out .= strtoupper($f[0]);
    if ($l !== '') $out .= strtoupper($l[0]);
    return $out !== '' ? substr($out, 0, 2) : 'S';
}
function full_name($first, $last) {
    return trim(((string)$first) . ' ' . ((string)$last));
}

$level_map = [
    'Nursery'=>'Nursery','Primary'=>'Primary',
    'O_Level'=>"O' Level",'A_Level'=>"A' Level",
];
$level_colors = [
    'Nursery'=>'pink','Primary'=>'blue',
    'O_Level'=>'green','A_Level'=>'orange',
];

$sum_present = 0; $sum_absent = 0; $sum_late = 0; $sum_excused = 0; $sum_total = 0;
foreach ($summary as $row) {
    $sum_present += (int)$row['present_cnt'];
    $sum_absent  += (int)$row['absent_cnt'];
    $sum_late    += (int)$row['late_cnt'];
    $sum_excused += (int)$row['excused_cnt'];
    $sum_total   += (int)$row['total_cnt'];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance — <?= htmlspecialchars($class['class_name']); ?> | Educ Bridge</title>
<link rel="icon" type="image/png" href="logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --primary:#2563eb;--primary-dark:#1d4ed8;--primary-light:#dbeafe;
        --success:#10b981;--success-light:rgba(16,185,129,.1);
        --warning:#f59e0b;--warning-light:rgba(245,158,11,.12);
        --danger:#ef4444;--danger-light:rgba(239,68,68,.1);
        --sidebar-bg:#ffffff;--sidebar-hover:rgba(37,99,235,.06);--sidebar-active:rgba(37,99,235,.10);
        --sidebar-text:#475569;--sidebar-text-active:#0f172a;--sidebar-border:#e2e8f0;--sidebar-title:#94a3b8;
        --bg-body:#f8fafc;--bg-card:#ffffff;--bg-alt:#f1f5f9;
        --text-primary:#0f172a;--text-secondary:#475569;--text-muted:#64748b;
        --border-color:#e2e8f0;
        --shadow-sm:0 1px 3px rgba(0,0,0,.06);--shadow-md:0 4px 12px rgba(0,0,0,.07);
        --shadow-lg:0 20px 45px rgba(0,0,0,.08);--shadow-sidebar:0 0 30px rgba(0,0,0,.05);
        --transition:all .3s cubic-bezier(.4,0,.2,1);
    }
    [data-theme="dark"] {
        --primary:#3b82f6;--primary-dark:#2563eb;--primary-light:#1e3a5f;
        --success:#34d399;--success-light:rgba(16,185,129,.15);
        --warning:#fbbf24;--warning-light:rgba(245,158,11,.18);
        --danger:#f87171;--danger-light:rgba(239,68,68,.15);
        --sidebar-bg:#0f172a;--sidebar-hover:rgba(255,255,255,.06);--sidebar-active:rgba(59,130,246,.18);
        --sidebar-text:#cbd5e1;--sidebar-text-active:#ffffff;--sidebar-border:rgba(255,255,255,.08);--sidebar-title:#94a3b8;
        --bg-body:#0b1120;--bg-card:#1e293b;--bg-alt:#1a2332;
        --text-primary:#f1f5f9;--text-secondary:#cbd5e1;--text-muted:#94a3b8;
        --border-color:#334155;
        --shadow-sm:0 1px 3px rgba(0,0,0,.3);--shadow-md:0 4px 12px rgba(0,0,0,.4);
        --shadow-lg:0 20px 45px rgba(0,0,0,.5);--shadow-sidebar:0 0 30px rgba(0,0,0,.4);
    }
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
    html,body{min-height:100vh}
    body{font-family:'Inter',sans-serif;background:var(--bg-body);color:var(--text-primary);overflow-x:hidden}
    .layout{display:flex;min-height:100vh}

    .sidebar{width:260px;background:var(--sidebar-bg);color:var(--sidebar-text);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:100;border-right:1px solid var(--sidebar-border);box-shadow:var(--shadow-sidebar)}
    .sidebar-header{padding:22px 22px 18px;border-bottom:1px solid var(--sidebar-border);display:flex;align-items:center;gap:12px}
    .sidebar-header img{width:42px;height:42px;object-fit:contain;border-radius:50%;border:2px solid var(--sidebar-border);padding:2px;background:#fff}
    .sidebar-brand{font-family:'Poppins',sans-serif;font-size:18px;font-weight:700;color:var(--text-primary);line-height:1.2}
    .sidebar-brand span{color:var(--primary)}
    .sidebar-brand small{display:block;font-size:11px;font-weight:500;color:var(--text-muted);letter-spacing:.4px;margin-top:2px;text-transform:uppercase}
    .sidebar-nav{flex:1;padding:18px 12px;overflow-y:auto}
    .nav-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--sidebar-title);padding:12px 14px 8px}
    .nav-item{display:flex;align-items:center;gap:12px;padding:11px 14px;color:var(--sidebar-text);text-decoration:none;font-size:14px;font-weight:500;border-radius:10px;margin-bottom:4px;transition:var(--transition);position:relative}
    .nav-item i.nav-icon{width:20px;text-align:center;font-size:15px;color:var(--sidebar-text)}
    .nav-item:hover{background:var(--sidebar-hover);color:var(--sidebar-text-active)}
    .nav-item:hover i.nav-icon{color:var(--primary)}
    .nav-item.active{background:var(--sidebar-active);color:var(--sidebar-text-active)}
    .nav-item.active i.nav-icon{color:var(--primary)}
    .nav-item.active::before{content:'';position:absolute;left:0;top:8px;bottom:8px;width:3px;background:var(--primary);border-radius:0 4px 4px 0}
    .sidebar-profile{border-top:1px solid var(--sidebar-border);padding:16px 18px;display:flex;align-items:center;gap:12px}
    .sidebar-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;box-shadow:0 4px 12px rgba(59,130,246,.35)}
    .sidebar-profile-info{flex:1;min-width:0}
    .sidebar-profile-info .p-name{font-size:13.5px;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sidebar-profile-info .p-role{font-size:11.5px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sidebar-logout{width:34px;height:34px;border-radius:8px;background:var(--danger-light);color:var(--danger);display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:13px;flex-shrink:0}

    .main{flex:1;margin-left:260px;min-height:100vh;display:flex;flex-direction:column}
    .topbar{background:var(--bg-card);border-bottom:1px solid var(--border-color);padding:14px 28px;display:flex;align-items:center;justify-content:space-between;gap:16px;position:sticky;top:0;z-index:50}
    .topbar-left{display:flex;align-items:center;gap:14px}
    .hamburger{display:none;width:40px;height:40px;border-radius:10px;border:1.5px solid var(--border-color);background:var(--bg-body);color:var(--text-secondary);align-items:center;justify-content:center;cursor:pointer;font-size:15px}
    .page-title{font-family:'Poppins',sans-serif;font-size:20px;font-weight:700;color:var(--text-primary);letter-spacing:-.4px}
    .page-subtitle{font-size:13px;color:var(--text-muted);margin-top:2px}
    .topbar-actions{display:flex;align-items:center;gap:10px}
    .topbar-btn{width:42px;height:42px;border-radius:50%;border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-secondary);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:15px;text-decoration:none}
    .topbar-btn:hover{border-color:var(--primary);color:var(--primary)}
    .topbar-btn.theme-btn:hover{transform:rotate(20deg)}
    .topbar-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;box-shadow:0 4px 12px rgba(59,130,246,.3)}

    .content{padding:28px;flex:1}

    .back-link{display:inline-flex;align-items:center;gap:8px;color:var(--primary);text-decoration:none;font-size:14px;font-weight:600;margin-bottom:18px;transition:var(--transition)}
    .back-link:hover{gap:11px}

    .alert{padding:13px 16px;border-radius:10px;margin-bottom:20px;font-size:14px;display:flex;align-items:flex-start;gap:10px}
    .alert-success{background:var(--success-light);color:#059669;border:1px solid rgba(16,185,129,.25)}
    [data-theme="dark"] .alert-success{color:#34d399}
    .alert-error{background:var(--danger-light);color:#dc2626;border:1px solid rgba(239,68,68,.25)}
    [data-theme="dark"] .alert-error{color:#f87171}

    .class-header{background:var(--bg-card);border:1px solid var(--border-color);border-radius:20px;padding:22px 24px;margin-bottom:22px;display:flex;align-items:center;gap:18px;box-shadow:var(--shadow-md);position:relative;overflow:hidden}
    .class-header::before{content:'';position:absolute;left:0;top:0;bottom:0;width:5px;background:var(--success)}
    .class-header.inactive::before{background:var(--danger)}
    .ch-avatar{width:72px;height:72px;border-radius:18px;background:linear-gradient(135deg,#2563eb,#7c3aed);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Poppins',sans-serif;font-size:26px;font-weight:700;flex-shrink:0;box-shadow:0 10px 24px rgba(37,99,235,.3)}
    .ch-info{flex:1;min-width:0}
    .ch-title{font-family:'Poppins',sans-serif;font-size:23px;font-weight:700;color:var(--text-primary);margin-bottom:6px;letter-spacing:-.4px}
    .ch-meta{display:flex;flex-wrap:wrap;gap:8px;font-size:12.5px;align-items:center}

    .pill{display:inline-block;padding:3px 10px;border-radius:50px;font-size:11.5px;font-weight:700;letter-spacing:.3px;line-height:1.5}
    .pill.blue{background:rgba(37,99,235,.12);color:#2563eb}
    .pill.green{background:rgba(16,185,129,.12);color:#059669}
    .pill.orange{background:rgba(245,158,11,.15);color:#b45309}
    .pill.purple{background:rgba(124,58,237,.12);color:#7c3aed}
    .pill.gray{background:rgba(100,116,139,.14);color:#475569}
    .pill.red{background:rgba(239,68,68,.12);color:#dc2626}
    .pill.pink{background:rgba(236,72,153,.12);color:#db2777}
    [data-theme="dark"] .pill.blue{color:#93c5fd}
    [data-theme="dark"] .pill.green{color:#34d399}
    [data-theme="dark"] .pill.orange{color:#fbbf24}
    [data-theme="dark"] .pill.purple{color:#a78bfa}
    [data-theme="dark"] .pill.gray{color:#cbd5e1}
    [data-theme="dark"] .pill.red{color:#f87171}
    [data-theme="dark"] .pill.pink{color:#f9a8d4}

    .controls{background:var(--bg-card);border:1px solid var(--border-color);border-radius:14px;padding:14px 18px;margin-bottom:18px;display:flex;flex-wrap:wrap;gap:12px;align-items:center}
    .ctrl-group{display:inline-flex;align-items:center;gap:8px;background:var(--bg-alt);border:1.5px solid var(--border-color);border-radius:10px;padding:6px 12px}
    .ctrl-group label{font-size:12.5px;font-weight:700;color:var(--text-secondary)}
    .ctrl-group input[type=date]{border:none;background:transparent;color:var(--text-primary);font-family:inherit;font-size:13.5px;font-weight:600;outline:none;padding:6px 4px;cursor:pointer}
    .ctrl-group input[type=date]:focus{color:var(--primary)}

    .btn{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:10px;font-size:13.5px;font-weight:700;cursor:pointer;border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);text-decoration:none;transition:var(--transition);font-family:inherit}
    .btn:hover{border-color:var(--primary);color:var(--primary)}
    .btn.primary{background:var(--primary);color:#fff;border-color:var(--primary);box-shadow:0 6px 18px rgba(37,99,235,.32)}
    .btn.primary:hover{background:var(--primary-dark);color:#fff}
    .btn.success{background:var(--success);color:#fff;border-color:var(--success);box-shadow:0 6px 18px rgba(16,185,129,.32)}
    .btn.success:hover{background:#059669;color:#fff}

    .tbl-wrap{background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px;overflow:hidden;margin-bottom:22px}
    table.tbl{width:100%;border-collapse:collapse;font-size:13.5px}
    table.tbl thead th{background:var(--bg-alt);text-align:left;padding:12px 14px;font-size:11.5px;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.6px;border-bottom:1px solid var(--border-color)}
    table.tbl thead th.center{text-align:center}
    table.tbl tbody td{padding:11px 14px;border-bottom:1px solid var(--border-color);vertical-align:middle}
    table.tbl tbody tr:last-child td{border-bottom:none}
    table.tbl tbody tr:hover{background:var(--sidebar-hover)}
    .s-cell{display:flex;align-items:center;gap:10px}
    .s-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Poppins',sans-serif;font-size:12px;font-weight:700;flex-shrink:0;box-shadow:0 4px 12px rgba(59,130,246,.28)}
    .s-name{font-weight:700}
    .s-code{font-family:'Courier New',monospace;font-size:12px;color:var(--text-muted);font-weight:700}
    .s-meta{font-size:11.5px;color:var(--text-muted);margin-top:2px}

    .att-cell{display:flex;gap:6px;justify-content:center;flex-wrap:wrap}
    .att-cell label{
        display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:8px;
        border:1.5px solid var(--border-color);background:var(--bg-card);
        cursor:pointer;font-size:12.5px;font-weight:700;color:var(--text-secondary);transition:var(--transition)
    }
    .att-cell input{display:none}
    .att-cell label.p:hover{border-color:var(--success);color:var(--success)}
    .att-cell label.l:hover{border-color:var(--warning);color:var(--warning)}
    .att-cell label.e:hover{border-color:var(--primary);color:var(--primary)}
    .att-cell label.a:hover{border-color:var(--danger);color:var(--danger)}
    .att-cell label.p.selected{background:var(--success-light);border-color:var(--success);color:var(--success)}
    .att-cell label.l.selected{background:var(--warning-light);border-color:var(--warning);color:var(--warning)}
    .att-cell label.e.selected{background:rgba(37,99,235,.12);border-color:var(--primary);color:var(--primary)}
    .att-cell label.a.selected{background:var(--danger-light);border-color:var(--danger);color:var(--danger)}

    .summary-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;flex-wrap:wrap}
    .summary-header h2{font-family:'Poppins',sans-serif;font-size:17px;font-weight:700;display:flex;align-items:center;gap:10px}
    .summary-header h2 i{color:var(--primary)}
    .summary-header .sum-meta{font-size:12.5px;color:var(--text-muted)}

    .summary-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px}
    .stile{padding:14px 16px;border-radius:12px;background:var(--bg-alt);border:1px solid var(--border-color)}
    .stile .st-lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted)}
    .stile .st-num{font-family:'Poppins',sans-serif;font-size:22px;font-weight:700;margin-top:4px}
    .stile.present .st-num{color:var(--success)}
    .stile.absent  .st-num{color:var(--danger)}
    .stile.late    .st-num{color:var(--warning)}
    .stile.excused .st-num{color:var(--primary)}
    .stile.total   .st-num{color:var(--text-primary)}

    .date-tbl{background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px;overflow:hidden}
    table.date-tbl-t{width:100%;border-collapse:collapse;font-size:13.5px}
    table.date-tbl-t thead th{background:var(--bg-alt);text-align:left;padding:11px 14px;font-size:11.5px;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.6px;border-bottom:1px solid var(--border-color)}
    table.date-tbl-t thead th.center{text-align:center}
    table.date-tbl-t tbody td{padding:11px 14px;border-bottom:1px solid var(--border-color)}
    table.date-tbl-t tbody td.center{text-align:center;font-weight:700}
    table.date-tbl-t tbody tr:last-child td{border-bottom:none}
    table.date-tbl-t tbody tr:hover{background:var(--sidebar-hover)}
    .dt-present{color:#059669}
    .dt-absent{color:#dc2626}
    .dt-late{color:#b45309}
    .dt-excused{color:var(--primary)}
    [data-theme="dark"] .dt-present{color:#34d399}
    [data-theme="dark"] .dt-absent{color:#f87171}
    [data-theme="dark"] .dt-late{color:#fbbf24}
    [data-theme="dark"] .dt-excused{color:#93c5fd}

    .empty-state{text-align:center;padding:50px 20px;color:var(--text-muted);background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px}
    .empty-state i{font-size:46px;opacity:.35;margin-bottom:12px;display:block}
    .empty-state h3{font-family:'Poppins',sans-serif;font-size:16px;font-weight:700;color:var(--text-primary);margin-bottom:6px}
    .empty-state p{font-size:13.5px;line-height:1.6}

    .overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:90;backdrop-filter:blur(2px)}
    .overlay.show{display:block}

    @media (max-width:900px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main{margin-left:0}.hamburger{display:flex}}
    @media (max-width:640px){.content{padding:20px 16px}.topbar{padding:12px 16px}.att-cell label{padding:8px 10px;font-size:11.5px}}
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
            <a href="teacher-classes.php" class="nav-item active"><i class="fas fa-layer-group nav-icon"></i> My Classes</a>
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
            <a href="teacher-logout.php" class="sidebar-logout" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </aside>

    <div class="overlay" id="overlay"></div>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburger" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <div>
                    <div class="page-title">Attendance</div>
                    <div class="page-subtitle"><?= htmlspecialchars($class['class_name']); ?> · <?= htmlspecialchars($school_name); ?></div>
                </div>
            </div>
            <div class="topbar-actions">
                <button class="topbar-btn theme-btn" id="themeToggle" aria-label="Toggle theme"><i class="fas fa-moon"></i></button>
                <a href="teacher-messages.php" class="topbar-btn" title="Messages"><i class="fas fa-bell"></i></a>
                <div class="topbar-avatar" title="<?= htmlspecialchars($teacher_name); ?>"><?= htmlspecialchars($initials); ?></div>
            </div>
        </header>

        <main class="content">

            <a href="teacher-classes.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to My Classes</a>

            <?php if ($flash !== ""): ?>
                <div class="alert alert-<?= htmlspecialchars($flash_type); ?>">
                    <i class="fas <?= $flash_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($flash); ?></span>
                </div>
            <?php endif; ?>

            <?php
                $ch_init = "";
                foreach (explode(' ', trim($class['class_name'])) as $p) {
                    if ($p !== '') { $ch_init .= strtoupper($p[0]); if (strlen($ch_init) >= 2) break; }
                }
                if ($ch_init === '') $ch_init = 'C';
                $ch_lvlb = $level_map[$class['level']] ?? $class['level'];
                $ch_lvcl = $level_colors[$class['level']] ?? 'gray';
                $ch_active = $class['status'] === 'active';
            ?>

            <!-- ============ 1. CLASS HEADER ============ -->
            <div class="class-header <?= $ch_active ? '' : 'inactive'; ?>">
                <div class="ch-avatar"><?= htmlspecialchars($ch_init); ?></div>
                <div class="ch-info">
                    <div class="ch-title"><?= htmlspecialchars($class['class_name']); ?></div>
                    <div class="ch-meta">
                        <span class="pill <?= $ch_lvcl; ?>"><?= htmlspecialchars($ch_lvlb); ?></span>
                        <span class="pill <?= $ch_active ? 'green' : 'red'; ?>"><?= $ch_active ? 'Active' : 'Inactive'; ?></span>
                        <span class="pill blue"><i class="fas fa-user-graduate"></i> <?= count($students); ?> student<?= count($students)===1?'':'s'; ?></span>
                        <?php if (!empty($class['room'])): ?>
                            <span class="pill gray"><i class="fas fa-door-open"></i> <?= htmlspecialchars($class['room']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ============ 2. SUMMARY FIRST ============ -->
            <div class="summary-header">
                <h2><i class="fas fa-chart-column"></i> Attendance summary</h2>
                <div class="sum-meta">
                    <?php if ($summary_was_empty): ?>
                        Showing latest records (no attendance in the chosen range)
                    <?php else: ?>
                        Range: <strong><?= date('d M Y', strtotime($from_date)); ?></strong> → <strong><?= date('d M Y', strtotime($to_date)); ?></strong>
                    <?php endif; ?>
                </div>
            </div>

            <form method="GET" action="teacher-attendance.php" class="controls" style="margin-bottom:14px;">
                <input type="hidden" name="class_id" value="<?= $class_id; ?>">
                <input type="hidden" name="date" value="<?= htmlspecialchars($att_date); ?>">

                <div class="ctrl-group">
                    <label for="from"><i class="fas fa-calendar"></i> From</label>
                    <input type="date" id="from" name="from" value="<?= htmlspecialchars($from_date); ?>">
                </div>
                <div class="ctrl-group">
                    <label for="to"><i class="fas fa-calendar-check"></i> To</label>
                    <input type="date" id="to" name="to" value="<?= htmlspecialchars($to_date); ?>">
                </div>

                <a class="btn" href="teacher-attendance.php?class_id=<?= $class_id; ?>&date=<?= urlencode($att_date); ?>&from=<?= date('Y-m-d'); ?>&to=<?= date('Y-m-d'); ?>">Today</a>
                <a class="btn" href="teacher-attendance.php?class_id=<?= $class_id; ?>&date=<?= urlencode($att_date); ?>&from=<?= date('Y-m-d', strtotime('monday this week')); ?>&to=<?= date('Y-m-d', strtotime('sunday this week')); ?>">This week</a>
                <a class="btn" href="teacher-attendance.php?class_id=<?= $class_id; ?>&date=<?= urlencode($att_date); ?>&from=<?= date('Y-m-01'); ?>&to=<?= date('Y-m-t'); ?>">This month</a>

                <button type="submit" class="btn primary"><i class="fas fa-filter"></i> Apply range</button>
            </form>

            <div class="summary-tiles">
                <div class="stile present"><div class="st-lbl">Present</div><div class="st-num"><?= $sum_present; ?></div></div>
                <div class="stile late"><div class="st-lbl">Late</div><div class="st-num"><?= $sum_late; ?></div></div>
                <div class="stile excused"><div class="st-lbl">Excused</div><div class="st-num"><?= $sum_excused; ?></div></div>
                <div class="stile absent"><div class="st-lbl">Absent</div><div class="st-num"><?= $sum_absent; ?></div></div>
                <div class="stile total"><div class="st-lbl">Total entries</div><div class="st-num"><?= $sum_total; ?></div></div>
            </div>

            <?php if (empty($summary)): ?>
                <div class="empty-state" style="margin-bottom:26px;">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>No attendance saved yet</h3>
                    <p>Take attendance for a date below — the summary will appear here.</p>
                </div>
            <?php else: ?>
                <div class="date-tbl" style="margin-bottom:26px;">
                    <table class="date-tbl-t">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th class="center">Present</th>
                                <th class="center">Late</th>
                                <th class="center">Excused</th>
                                <th class="center">Absent</th>
                                <th class="center">Total</th>
                                <th class="center" style="width:80px;">Open</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($summary as $row):
                                $d = $row['att_date'];
                            ?>
                                <tr>
                                    <td><strong><?= date('d M Y', strtotime($d)); ?></strong> <span style="color:var(--text-muted);font-size:12px;">(<?= date('D', strtotime($d)); ?>)</span></td>
                                    <td class="center dt-present"><?= (int)$row['present_cnt']; ?></td>
                                    <td class="center dt-late"><?= (int)$row['late_cnt']; ?></td>
                                    <td class="center dt-excused"><?= (int)$row['excused_cnt']; ?></td>
                                    <td class="center dt-absent"><?= (int)$row['absent_cnt']; ?></td>
                                    <td class="center"><strong><?= (int)$row['total_cnt']; ?></strong></td>
                                    <td class="center">
                                        <a class="btn" style="padding:6px 12px;font-size:12px;"
                                           href="teacher-attendance.php?class_id=<?= $class_id; ?>&date=<?= urlencode($d); ?>&from=<?= urlencode($from_date); ?>&to=<?= urlencode($to_date); ?>">
                                            <i class="fas fa-pen"></i> Edit
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- ============ 3. TAKE ATTENDANCE ============ -->
            <div class="summary-header">
                <h2><i class="fas fa-clipboard-check"></i> Take attendance</h2>
                <div class="sum-meta">
                    Choose a date, then mark each student below.
                </div>
            </div>

            <form method="GET" action="teacher-attendance.php" class="controls">
                <input type="hidden" name="class_id" value="<?= $class_id; ?>">
                <div class="ctrl-group">
                    <label for="date"><i class="fas fa-calendar-day"></i> Attendance date</label>
                    <input type="date" id="date" name="date" value="<?= htmlspecialchars($att_date); ?>">
                </div>
                <button type="submit" class="btn primary"><i class="fas fa-filter"></i> Load date</button>
                <a href="teacher-attendance.php?class_id=<?= $class_id; ?>" class="btn"><i class="fas fa-rotate"></i> Today</a>
            </form>

            <form method="POST" action="teacher-attendance.php?class_id=<?= $class_id; ?>&date=<?= urlencode($att_date); ?>&from=<?= urlencode($from_date); ?>&to=<?= urlencode($to_date); ?>">
                <input type="hidden" name="action" value="save_attendance">
                <input type="hidden" name="att_date" value="<?= htmlspecialchars($att_date); ?>">

                <?php if (empty($students)): ?>
                    <div class="empty-state">
                        <i class="fas fa-user-graduate"></i>
                        <h3>No students in this class yet</h3>
                        <p>Students assigned to <strong><?= htmlspecialchars($class['class_name']); ?></strong> will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="tbl-wrap">
                        <table class="tbl">
                            <thead>
                                <tr>
                                    <th>Student Code</th>
                                    <th>Student</th>
                                    <th class="center" style="width:420px;">Attendance for <?= date('d M Y', strtotime($att_date)); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $st):
                                    $sid   = (int)$st['id'];
                                    $code  = $st['student_code'] ?: sprintf('STU-%04d', $sid);
                                    $first = $st['first_name'] ?? '';
                                    $last  = $st['last_name']  ?? '';
                                    $full  = full_name($first, $last) ?: '—';
                                    $init  = initials_from_name($first, $last);
                                    $cur   = $existing[$sid] ?? '';
                                ?>
                                    <tr>
                                        <td class="s-code"><?= htmlspecialchars($code); ?></td>
                                        <td>
                                            <div class="s-cell">
                                                <div class="s-avatar"><?= htmlspecialchars($init); ?></div>
                                                <div>
                                                    <div class="s-name"><?= htmlspecialchars($full); ?></div>
                                                    <div class="s-meta"><?= htmlspecialchars($st['email'] ?: ''); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="att-cell">
                                                <input type="radio" id="p_<?= $sid; ?>" name="attendance[<?= $sid; ?>]" value="present" <?= $cur === 'present' ? 'checked' : ''; ?>>
                                                <label for="p_<?= $sid; ?>" class="p <?= $cur === 'present' ? 'selected' : ''; ?>">
                                                    <i class="fas fa-check"></i> Present
                                                </label>

                                                <input type="radio" id="l_<?= $sid; ?>" name="attendance[<?= $sid; ?>]" value="late" <?= $cur === 'late' ? 'checked' : ''; ?>>
                                                <label for="l_<?= $sid; ?>" class="l <?= $cur === 'late' ? 'selected' : ''; ?>">
                                                    <i class="fas fa-clock"></i> Late
                                                </label>

                                                <input type="radio" id="e_<?= $sid; ?>" name="attendance[<?= $sid; ?>]" value="excused" <?= $cur === 'excused' ? 'checked' : ''; ?>>
                                                <label for="e_<?= $sid; ?>" class="e <?= $cur === 'excused' ? 'selected' : ''; ?>">
                                                    <i class="fas fa-file-medical"></i> Excused
                                                </label>

                                                <input type="radio" id="a_<?= $sid; ?>" name="attendance[<?= $sid; ?>]" value="absent" <?= $cur === 'absent' ? 'checked' : ''; ?>>
                                                <label for="a_<?= $sid; ?>" class="a <?= $cur === 'absent' ? 'selected' : ''; ?>">
                                                    <i class="fas fa-xmark"></i> Absent
                                                </label>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap;">
                        <button type="submit" class="btn success">
                            <i class="fas fa-floppy-disk"></i> Save attendance for <?= date('d M Y', strtotime($att_date)); ?>
                        </button>
                    </div>
                <?php endif; ?>
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
        t.addEventListener('click', () => apply(html.getAttribute('data-theme') === 'light' ? 'dark' : 'light'));
    })();

    /* SIDEBAR */
    (function () {
        const h = document.getElementById('hamburger');
        const s = document.getElementById('sidebar');
        const o = document.getElementById('overlay');
        function open() { s.classList.add('open'); o.classList.add('show'); }
        function close() { s.classList.remove('open'); o.classList.remove('show'); }
        h.addEventListener('click', () => s.classList.contains('open') ? close() : open());
        o.addEventListener('click', close);
    })();

    /* Radio label styling */
    document.querySelectorAll('.att-cell').forEach(group => {
        group.querySelectorAll('input[type="radio"]').forEach(radio => {
            radio.addEventListener('change', () => {
                group.querySelectorAll('label').forEach(l => l.classList.remove('selected'));
                const lbl = group.querySelector('label[for="' + radio.id + '"]');
                if (lbl) lbl.classList.add('selected');
            });
        });
    });

    /* Auto-submit date filter */
    const dInput = document.getElementById('date');
    if (dInput) dInput.addEventListener('change', () => dInput.form.submit());
</script>

</body>
</html>