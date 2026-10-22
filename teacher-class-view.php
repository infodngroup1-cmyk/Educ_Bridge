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
$class_id = (int)($_GET['id'] ?? $_GET['class_id'] ?? 0);
$export   = $_GET['export'] ?? '';   // '' | 'excel' | 'pdf'
$days     = (int)($_GET['days'] ?? 4);
if ($days < 1)   $days = 1;
if ($days > 60)  $days = 60;

/*
|--------------------------------------------------------------------------
| LOAD THE CLASS — must belong to this teacher
|--------------------------------------------------------------------------
| Count ALL students of this class (no status filter) so the pill matches
| what the teacher sees in the list below.
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
| LOAD STUDENTS of this class
|--------------------------------------------------------------------------
| Real columns: id, student_code, first_name, last_name, class_id, email,
|               preferred_contact, avatar, last_login
*/
$q = trim($_GET['q'] ?? '');

$students = [];
$sql = "SELECT id, student_code, first_name, last_name, email, preferred_contact
        FROM students
        WHERE class_id = ?";
$types  = "i";
$params = [$class_id];

if ($q !== '') {
    $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR student_code LIKE ? OR email LIKE ?)";
    $like = "%{$q}%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= "ssss";
}
$sql .= " ORDER BY first_name ASC, last_name ASC";

$st = mysqli_prepare($conn, $sql);
if ($st) {
    mysqli_stmt_bind_param($st, $types, ...$params);
    mysqli_stmt_execute($st);
    $r = mysqli_stmt_get_result($st);
    while ($row = mysqli_fetch_assoc($r)) $students[] = $row;
    mysqli_stmt_close($st);
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
    if ($out === '') $out = 'S';
    return substr($out, 0, 2);
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

/*
|--------------------------------------------------------------------------
| EXPORT HANDLING (Excel / PDF)
|--------------------------------------------------------------------------
*/
if ($export === 'excel' || $export === 'pdf') {

    $cls_name = $class['class_name'];
    $cls_lvl  = $level_map[$class['level']] ?? $class['level'];

    $headers = ['Student Code', 'First Name', 'Last Name'];
    for ($i = 1; $i <= $days; $i++) $headers[] = 'Day ' . $i;

    $rows = [];
    foreach ($students as $s) {
        $row = [
            $s['student_code'] ?: sprintf('STU-%04d', (int)$s['id']),
            $s['first_name'] ?? '',
            $s['last_name']  ?? '',
        ];
        for ($i = 1; $i <= $days; $i++) $row[] = '';
        $rows[] = $row;
    }

    $safe = function($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

    if ($export === 'excel') {
        $filename = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $cls_name . '_report_' . date('Y-m-d'));
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        ?>
        <html><head><meta charset="utf-8"></head><body>
        <table border="1" cellpadding="6" cellspacing="0" style="font-family:Arial,sans-serif;font-size:12px;">
            <tr><td colspan="<?= count($headers); ?>" style="background:#2563eb;color:#fff;font-weight:bold;font-size:14px;text-align:center;">
                <?= $safe($cls_name); ?> — Student Register
            </td></tr>
            <tr><td colspan="<?= count($headers); ?>" style="text-align:center;">
                <?= $safe($school_name); ?> · <?= $safe($cls_lvl); ?> · <?= date('d M Y'); ?>
            </td></tr>
            <tr>
                <?php foreach ($headers as $h): ?>
                    <th style="background:#f1f5f9;font-weight:bold;"><?= $safe($h); ?></th>
                <?php endforeach; ?>
            </tr>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach ($row as $cell): ?>
                        <td><?= $safe($cell); ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </table>
        </body></html>
        <?php
        exit;
    }

    if ($export === 'pdf') {
        ?>
        <!DOCTYPE html>
        <html lang="en"><head>
        <meta charset="utf-8">
        <title><?= $safe($cls_name); ?> — Report</title>
        <style>
            @page { size: A4 landscape; margin: 12mm; }
            *,*::before,*::after { box-sizing:border-box; }
            body { font-family: Arial, Helvetica, sans-serif; color:#0f172a; margin:0; padding:0; }
            .print-bar { background:#1e293b; color:#fff; padding:10px 20px; display:flex; align-items:center; justify-content:space-between; gap:10px; }
            .print-bar span { font-weight:600; font-size:14px; }
            .print-bar button { padding:9px 18px; border-radius:8px; border:none; cursor:pointer; background:#2563eb; color:#fff; font-weight:700; font-size:13px; }
            .print-bar a { color:#93c5fd; font-size:13px; text-decoration:none; font-weight:600; }
            .sheet { padding: 20px; }
            .sheet h1 { font-size:20px; margin:0 0 4px; }
            .sheet .meta { font-size:12px; color:#475569; margin-bottom:14px; }
            table { width:100%; border-collapse:collapse; font-size:12px; }
            th, td { border:1px solid #94a3b8; padding:7px 8px; text-align:left; }
            th { background:#e2e8f0; font-weight:700; }
            th.day, td.day { text-align:center; width:70px; }
            tr:nth-child(even) td { background:#f8fafc; }
            .signature { margin-top:30px; font-size:12px; display:flex; justify-content:space-between; }
            .signature div { border-top:1px solid #94a3b8; padding-top:6px; width:220px; }
            @media print { .print-bar { display:none !important; } .sheet { padding:0; } }
        </style>
        </head><body>

        <div class="print-bar">
            <span><i>📄</i> Ready to print — use "Save as PDF" if you want a file</span>
            <div>
                <button onclick="window.print()">Print</button>
                <a href="teacher-class-view.php?id=<?= $class_id; ?>">← Back</a>
            </div>
        </div>

        <div class="sheet">
            <h1><?= $safe($cls_name); ?> — Student Register</h1>
            <div class="meta">
                <?= $safe($school_name); ?> · <?= $safe($cls_lvl); ?> · <?= count($students); ?> student<?= count($students)===1?'':'s'; ?> · <?= $days; ?> day<?= $days===1?'':'s'; ?> · Printed <?= date('d M Y'); ?>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width:120px;">Student Code</th>
                        <th>First Name</th>
                        <th>Last Name</th>
                        <?php for ($i = 1; $i <= $days; $i++): ?>
                            <th class="day">Day <?= $i; ?></th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="<?= count($headers); ?>" style="text-align:center;padding:20px;">No students in this class.</td></tr>
                    <?php else: foreach ($rows as $row): ?>
                        <tr>
                            <td><?= $safe($row[0]); ?></td>
                            <td><?= $safe($row[1]); ?></td>
                            <td><?= $safe($row[2]); ?></td>
                            <?php for ($i = 1; $i <= $days; $i++): ?>
                                <td class="day">&nbsp;</td>
                            <?php endfor; ?>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <div class="signature">
                <div>Class Teacher's signature</div>
                <div>Date</div>
            </div>
        </div>

        <script>
            window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 400); });
        </script>
        </body></html>
        <?php
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| RENDER THE PAGE (default view)
|--------------------------------------------------------------------------
*/
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($class['class_name']); ?> — Students | Educ Bridge</title>
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
        --sidebar-bg: #ffffff; --sidebar-hover: rgba(37,99,235,0.06); --sidebar-active: rgba(37,99,235,0.10);
        --sidebar-text: #475569; --sidebar-text-active: #0f172a; --sidebar-border: #e2e8f0; --sidebar-title: #94a3b8;
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
        --sidebar-bg: #0f172a; --sidebar-hover: rgba(255,255,255,0.06); --sidebar-active: rgba(59,130,246,0.18);
        --sidebar-text: #cbd5e1; --sidebar-text-active: #ffffff; --sidebar-border: rgba(255,255,255,0.08); --sidebar-title: #94a3b8;
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

    .sidebar { width:260px; background:var(--sidebar-bg); color:var(--sidebar-text); display:flex; flex-direction:column; position:fixed; top:0; left:0; height:100vh; z-index:100; border-right:1px solid var(--sidebar-border); box-shadow:var(--shadow-sidebar); }
    .sidebar-header { padding:22px 22px 18px; border-bottom:1px solid var(--sidebar-border); display:flex; align-items:center; gap:12px; }
    .sidebar-header img { width:42px; height:42px; object-fit:contain; border-radius:50%; border:2px solid var(--sidebar-border); padding:2px; background:#fff; }
    .sidebar-brand { font-family:'Poppins',sans-serif; font-size:18px; font-weight:700; color:var(--text-primary); line-height:1.2; }
    .sidebar-brand span { color:var(--primary); }
    .sidebar-brand small { display:block; font-size:11px; font-weight:500; color:var(--text-muted); letter-spacing:0.4px; margin-top:2px; text-transform:uppercase; }
    .sidebar-nav { flex:1; padding:18px 12px; overflow-y:auto; }
    .nav-section-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; color:var(--sidebar-title); padding:12px 14px 8px; }
    .nav-item { display:flex; align-items:center; gap:12px; padding:11px 14px; color:var(--sidebar-text); text-decoration:none; font-size:14px; font-weight:500; border-radius:10px; margin-bottom:4px; transition:var(--transition); position:relative; }
    .nav-item i.nav-icon { width:20px; text-align:center; font-size:15px; color:var(--sidebar-text); }
    .nav-item:hover { background:var(--sidebar-hover); color:var(--sidebar-text-active); }
    .nav-item:hover i.nav-icon { color:var(--primary); }
    .nav-item.active { background:var(--sidebar-active); color:var(--sidebar-text-active); }
    .nav-item.active i.nav-icon { color:var(--primary); }
    .nav-item.active::before { content:''; position:absolute; left:0; top:8px; bottom:8px; width:3px; background:var(--primary); border-radius:0 4px 4px 0; }
    .sidebar-profile { border-top:1px solid var(--sidebar-border); padding:16px 18px; display:flex; align-items:center; gap:12px; }
    .sidebar-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-size:15px; font-weight:700; box-shadow:0 4px 12px rgba(59,130,246,0.35); }
    .sidebar-profile-info { flex:1; min-width:0; }
    .sidebar-profile-info .p-name { font-size:13.5px; font-weight:600; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .sidebar-profile-info .p-role { font-size:11.5px; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .sidebar-logout { width:34px; height:34px; border-radius:8px; background:var(--danger-light); color:var(--danger); display:flex; align-items:center; justify-content:center; text-decoration:none; font-size:13px; flex-shrink:0; }

    .main { flex:1; margin-left:260px; min-height:100vh; display:flex; flex-direction:column; }
    .topbar { background:var(--bg-card); border-bottom:1px solid var(--border-color); padding:14px 28px; display:flex; align-items:center; justify-content:space-between; gap:16px; position:sticky; top:0; z-index:50; }
    .topbar-left { display:flex; align-items:center; gap:14px; }
    .hamburger { display:none; width:40px; height:40px; border-radius:10px; border:1.5px solid var(--border-color); background:var(--bg-body); color:var(--text-secondary); align-items:center; justify-content:center; cursor:pointer; font-size:15px; }
    .page-title { font-family:'Poppins',sans-serif; font-size:20px; font-weight:700; color:var(--text-primary); letter-spacing:-0.4px; }
    .page-subtitle { font-size:13px; color:var(--text-muted); margin-top:2px; }
    .topbar-actions { display:flex; align-items:center; gap:10px; }
    .topbar-btn { width:42px; height:42px; border-radius:50%; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; text-decoration:none; }
    .topbar-btn:hover { border-color:var(--primary); color:var(--primary); }
    .topbar-btn.theme-btn:hover { transform:rotate(20deg); }
    .topbar-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:700; box-shadow:0 4px 12px rgba(59,130,246,0.3); }

    .content { padding:28px; flex:1; }

    .back-link { display:inline-flex; align-items:center; gap:8px; color:var(--primary); text-decoration:none; font-size:14px; font-weight:600; margin-bottom:18px; transition:var(--transition); }
    .back-link:hover { gap:11px; }

    .class-header { background:var(--bg-card); border:1px solid var(--border-color); border-radius:20px; padding:22px 24px; margin-bottom:22px; display:flex; align-items:center; gap:18px; box-shadow:var(--shadow-md); position:relative; overflow:hidden; }
    .class-header::before { content:''; position:absolute; left:0; top:0; bottom:0; width:5px; background:var(--success); }
    .class-header.inactive::before { background:var(--danger); }
    .ch-avatar { width:72px; height:72px; border-radius:18px; background:linear-gradient(135deg,#2563eb,#7c3aed); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Poppins',sans-serif; font-size:26px; font-weight:700; flex-shrink:0; box-shadow:0 10px 24px rgba(37,99,235,0.3); }
    .ch-info { flex:1; min-width:0; }
    .ch-title { font-family:'Poppins',sans-serif; font-size:23px; font-weight:700; color:var(--text-primary); margin-bottom:6px; letter-spacing:-0.4px; }
    .ch-meta { display:flex; flex-wrap:wrap; gap:8px; font-size:12.5px; align-items:center; }

    .pill { display:inline-block; padding:3px 10px; border-radius:50px; font-size:11.5px; font-weight:700; letter-spacing:0.3px; line-height:1.5; }
    .pill.blue   { background: rgba(37,99,235,0.12);  color: #2563eb; }
    .pill.green  { background: rgba(16,185,129,0.12); color: #059669; }
    .pill.orange { background: rgba(245,158,11,0.15); color: #b45309; }
    .pill.purple { background: rgba(124,58,237,0.12); color: #7c3aed; }
    .pill.gray   { background: rgba(100,116,139,0.14);color: #475569; }
    .pill.red    { background: rgba(239,68,68,0.12);  color: #dc2626; }
    .pill.pink   { background: rgba(236,72,153,0.12); color: #db2777; }
    [data-theme="dark"] .pill.blue   { color: #93c5fd; }
    [data-theme="dark"] .pill.green  { color: #34d399; }
    [data-theme="dark"] .pill.orange { color: #fbbf24; }
    [data-theme="dark"] .pill.purple { color: #a78bfa; }
    [data-theme="dark"] .pill.gray   { color: #cbd5e1; }
    [data-theme="dark"] .pill.red    { color: #f87171; }
    [data-theme="dark"] .pill.pink   { color: #f9a8d4; }

    .report-card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:16px; padding:18px 22px; margin-bottom:20px; display:flex; flex-wrap:wrap; gap:14px; align-items:center; box-shadow:var(--shadow-sm); }
    .report-card .rc-title { font-family:'Poppins',sans-serif; font-size:14px; font-weight:700; display:flex; align-items:center; gap:8px; margin-right:auto; }
    .report-card .rc-title i { color:var(--primary); }
    .report-card .rc-sub { font-size:12.5px; color:var(--text-muted); margin-top:2px; }
    .days-input { display:inline-flex; align-items:center; gap:8px; background:var(--bg-alt); border:1.5px solid var(--border-color); border-radius:10px; padding:6px 10px 6px 12px; }
    .days-input label { font-size:12.5px; font-weight:700; color:var(--text-secondary); }
    .days-input input { width:70px; padding:8px 10px; font-family:inherit; font-size:14px; font-weight:700; border:1.5px solid var(--border-color); border-radius:8px; background:var(--bg-card); color:var(--text-primary); outline:none; text-align:center; }
    .days-input input:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(37,99,235,0.1); }
    .btn { display:inline-flex; align-items:center; gap:8px; padding:11px 18px; border-radius:10px; font-size:13.5px; font-weight:700; cursor:pointer; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-primary); text-decoration:none; transition:var(--transition); font-family:inherit; }
    .btn:hover { border-color:var(--primary); color:var(--primary); }
    .btn.excel { background:#16a34a; border-color:#16a34a; color:#fff; box-shadow:0 6px 18px rgba(22,163,74,0.32); }
    .btn.excel:hover { background:#15803d; color:#fff; }
    .btn.pdf { background:#dc2626; border-color:#dc2626; color:#fff; box-shadow:0 6px 18px rgba(220,38,38,0.32); }
    .btn.pdf:hover { background:#b91c1c; color:#fff; }

    .search-row { display:flex; gap:10px; align-items:center; margin-bottom:16px; flex-wrap:wrap; }
    .search-row .filter-group { position:relative; display:flex; align-items:center; flex:1 1 240px; min-width:180px; }
    .search-row .filter-group i { position:absolute; left:13px; color:var(--text-muted); font-size:13px; pointer-events:none; }
    .search-row .filter-group input { width:100%; padding:11px 14px 11px 36px; border:1.5px solid var(--border-color); border-radius:10px; background:var(--bg-card); color:var(--text-primary); font-family:inherit; font-size:13.5px; outline:none; }
    .search-row .filter-group input:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(37,99,235,0.1); }
    .search-row button { padding:11px 20px; border-radius:10px; font-size:13.5px; font-weight:700; border:none; background:var(--primary); color:#fff; cursor:pointer; font-family:inherit; }

    .students-table-wrap { background:var(--bg-card); border:1px solid var(--border-color); border-radius:16px; overflow:hidden; }
    table.students-table { width:100%; border-collapse:collapse; font-size:13.5px; }
    table.students-table thead th {
        background:var(--bg-alt); text-align:left; padding:12px 14px;
        font-size:11.5px; font-weight:800; color:var(--text-muted);
        text-transform:uppercase; letter-spacing:0.6px;
        border-bottom:1px solid var(--border-color);
    }
    table.students-table tbody td { padding:12px 14px; border-bottom:1px solid var(--border-color); }
    table.students-table tbody tr:last-child td { border-bottom:none; }
    table.students-table tbody tr:hover { background:var(--sidebar-hover); }

    .s-cell { display:flex; align-items:center; gap:10px; }
    .s-avatar { width:38px; height:38px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Poppins',sans-serif; font-size:13px; font-weight:700; flex-shrink:0; box-shadow:0 4px 12px rgba(59,130,246,0.28); }
    .s-name { font-weight:700; }
    .s-code { font-family:'Courier New', monospace; font-size:12px; color:var(--text-muted); font-weight:700; }

    .empty-state { text-align:center; padding:60px 20px; color:var(--text-muted); background:var(--bg-card); border:1px solid var(--border-color); border-radius:16px; }
    .empty-state i { font-size:50px; opacity:0.35; margin-bottom:14px; display:block; }
    .empty-state h3 { font-family:'Poppins',sans-serif; font-size:17px; font-weight:700; color:var(--text-primary); margin-bottom:6px; }
    .empty-state p { font-size:14px; line-height:1.6; margin-bottom:18px; }

    .overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:90; backdrop-filter:blur(2px); }
    .overlay.show { display:block; }

    @media (max-width: 900px) {
        .sidebar { transform:translateX(-100%); }
        .sidebar.open { transform:translateX(0); }
        .main { margin-left:0; }
        .hamburger { display:flex; }
        .class-header { flex-direction:column; align-items:flex-start; }
    }
    @media (max-width: 640px) {
        .content { padding:20px 16px; }
        .topbar { padding:12px 16px; }
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
            <a href="teacher-subjects.php" class="nav-item"><i class="fas fa-book nav-icon"></i> Subjects</a>
            <a href="teacher-classes.php" class="nav-item active"><i class="fas fa-layer-group nav-icon"></i> Classes</a>
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
                    <div class="page-title"><?= htmlspecialchars($class['class_name']); ?></div>
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

            <a href="teacher-classes.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to My Classes</a>

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

            <!-- ============ CLASS HEADER ============ -->
            <div class="class-header <?= $ch_active ? '' : 'inactive'; ?>">
                <div class="ch-avatar"><?= htmlspecialchars($ch_init); ?></div>
                <div class="ch-info">
                    <div class="ch-title"><?= htmlspecialchars($class['class_name']); ?></div>
                    <div class="ch-meta">
                        <span class="pill <?= $ch_lvcl; ?>"><?= htmlspecialchars($ch_lvlb); ?></span>
                        <?php if ($ch_active): ?>
                            <span class="pill green"><i class="fas fa-circle"></i> Active</span>
                        <?php else: ?>
                            <span class="pill red"><i class="fas fa-circle"></i> Inactive</span>
                        <?php endif; ?>
                        <span class="pill blue"><i class="fas fa-user-graduate"></i> <?= (int)$class['student_count']; ?> student<?= ((int)$class['student_count'] === 1) ? '' : 's'; ?></span>
                        <?php if (!empty($class['room'])): ?>
                            <span class="pill gray"><i class="fas fa-door-open"></i> <?= htmlspecialchars($class['room']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($class['year_of_study'])): ?>
                            <span class="pill gray"><i class="fas fa-calendar"></i> <?= htmlspecialchars($class['year_of_study']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ============ REPORT BUILDER ============ -->
            <form method="GET" action="teacher-class-view.php" class="report-card" id="reportForm">
                <input type="hidden" name="id" value="<?= $class_id; ?>">
                <input type="hidden" name="export" id="exportField" value="">

                <div class="rc-title">
                    <i class="fas fa-file-export"></i>
                    <div>
                        Print attendance / exam register
                        <div class="rc-sub">Pick how many days to print, then choose Excel or PDF.</div>
                    </div>
                </div>

                <div class="days-input">
                    <label for="days"><i class="fas fa-calendar-day"></i> Days</label>
                    <input type="number" id="days" name="days" min="1" max="60" value="<?= $days; ?>">
                </div>

                <button type="button" class="btn excel" id="btnExcel">
                    <i class="fas fa-file-excel"></i> Print Excel
                </button>
                <button type="button" class="btn pdf" id="btnPdf">
                    <i class="fas fa-file-pdf"></i> Print PDF
                </button>
            </form>

            <!-- ============ SEARCH ============ -->
            <form method="GET" action="teacher-class-view.php" class="search-row">
                <input type="hidden" name="id" value="<?= $class_id; ?>">
                <div class="filter-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="q" placeholder="Search by name, code or email…" value="<?= htmlspecialchars($q); ?>">
                </div>
                <button type="submit"><i class="fas fa-filter"></i> Search</button>
            </form>

            <!-- ============ STUDENT LIST ============ -->
            <?php if (empty($students)): ?>
                <div class="empty-state">
                    <i class="fas fa-user-graduate"></i>
                    <h3>No students in this class yet</h3>
                    <p>Students assigned to <strong><?= htmlspecialchars($class['class_name']); ?></strong> will appear here.</p>
                </div>
            <?php else: ?>
                <div class="students-table-wrap">
                    <table class="students-table">
                        <thead>
                            <tr>
                                <th>Student Code</th>
                                <th>Full Name</th>
                                <th>First Name</th>
                                <th>Last Name</th>
                                <th>Email</th>
                                <th>Preferred Contact</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $st):
                                $sid   = (int)$st['id'];
                                $code  = $st['student_code'] ?: sprintf('STU-%04d', $sid);
                                $first = $st['first_name'] ?? '';
                                $last  = $st['last_name']  ?? '';
                                $full  = full_name($first, $last);
                                $init  = initials_from_name($first, $last);
                            ?>
                                <tr>
                                    <td class="s-code"><?= htmlspecialchars($code); ?></td>
                                    <td>
                                        <div class="s-cell">
                                            <div class="s-avatar"><?= htmlspecialchars($init); ?></div>
                                            <div class="s-name"><?= htmlspecialchars($full ?: '—'); ?></div>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($first ?: '—'); ?></td>
                                    <td><?= htmlspecialchars($last ?: '—'); ?></td>
                                    <td><?= htmlspecialchars($st['email'] ?: '—'); ?></td>
                                    <td><?= htmlspecialchars($st['preferred_contact'] ?: '—'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        </main>
    </div>
</div>

<script>
    /* THEME */
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

    /* SIDEBAR */
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

    /* REPORT BUTTONS */
    const reportForm  = document.getElementById('reportForm');
    const exportField = document.getElementById('exportField');
    document.getElementById('btnExcel').addEventListener('click', function () {
        exportField.value = 'excel';
        reportForm.submit();
    });
    document.getElementById('btnPdf').addEventListener('click', function () {
        exportField.value = 'pdf';
        reportForm.submit();
    });
</script>

</body>
</html>