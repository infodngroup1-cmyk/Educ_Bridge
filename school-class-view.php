<?php
require_once 'db.php';
require_once '_school-auth.php';

/*
|--------------------------------------------------------------------------
| AUTO-CREATE / MIGRATE required tables
|--------------------------------------------------------------------------
*/
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS classes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    school_id       INT NOT NULL,
    class_name      VARCHAR(120) NOT NULL,
    level           ENUM('Nursery','Primary','O_Level','A_Level') NOT NULL,
    year_of_study   VARCHAR(60)  DEFAULT NULL,
    academic_year   VARCHAR(20)  DEFAULT NULL,
    section         VARCHAR(20)  DEFAULT NULL,
    capacity        INT DEFAULT 0,
    teacher_id      INT DEFAULT NULL,
    status          ENUM('active','inactive') DEFAULT 'active',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_classes_school (school_id),
    KEY idx_classes_level  (school_id, level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

@mysqli_query($conn, "ALTER TABLE classes ADD COLUMN year_of_study VARCHAR(60) DEFAULT NULL AFTER level");
@mysqli_query($conn, "ALTER TABLE classes ADD COLUMN section VARCHAR(20) DEFAULT NULL AFTER academic_year");
@mysqli_query($conn, "ALTER TABLE classes ADD COLUMN teacher_id INT DEFAULT NULL AFTER capacity");

/* Ensure teachers table exists (safe if it already does) */
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS teachers (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    school_id   INT NOT NULL,
    name        VARCHAR(150) NOT NULL,
    email       VARCHAR(150) DEFAULT NULL,
    phone       VARCHAR(30)  DEFAULT NULL,
    subject     VARCHAR(120) DEFAULT NULL,
    status      ENUM('active','inactive') DEFAULT 'active',
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_teachers_school (school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* Add transfer tracking columns to students if missing */
@mysqli_query($conn, "ALTER TABLE students ADD COLUMN is_transferred TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
@mysqli_query($conn, "ALTER TABLE students ADD COLUMN transferred_at DATETIME DEFAULT NULL AFTER is_transferred");
@mysqli_query($conn, "ALTER TABLE students ADD COLUMN transferred_to VARCHAR(180) DEFAULT NULL AFTER transferred_at");

/*
|--------------------------------------------------------------------------
| LOAD CLASS (school-scoped)
|--------------------------------------------------------------------------
*/
$class_id = (int)($_GET['id'] ?? 0);
if ($class_id <= 0) {
    header("Location: school-classes.php?error=" . urlencode("Invalid class."));
    exit;
}

$class = null;
$cstmt = mysqli_prepare($conn, "
    SELECT c.*, t.name AS teacher_name, t.email AS teacher_email, t.phone AS teacher_phone, t.subject AS teacher_subject
    FROM classes c
    LEFT JOIN teachers t ON t.id = c.teacher_id AND t.school_id = c.school_id
    WHERE c.id = ? AND c.school_id = ?
    LIMIT 1
");
if ($cstmt) {
    mysqli_stmt_bind_param($cstmt, "ii", $class_id, $school_id);
    mysqli_stmt_execute($cstmt);
    $cres = mysqli_stmt_get_result($cstmt);
    $class = $cres ? mysqli_fetch_assoc($cres) : null;
    mysqli_stmt_close($cstmt);
}

if (!$class) {
    header("Location: school-classes.php?error=" . urlencode("Class not found."));
    exit;
}

$level_map = ['Nursery'=>'Nursery','Primary'=>'Primary','O_Level'=>"O' Level",'A_Level'=>"A' Level"];
$level_colors = ['Nursery'=>'pink','Primary'=>'blue','O_Level'=>'green','A_Level'=>'orange'];

$full_class_label = $class['class_name'];
if (!empty($class['section'])) $full_class_label .= ' ' . $class['section'];

/*
|--------------------------------------------------------------------------
| SEARCH / FILTER (for on-screen list AND for the report)
|--------------------------------------------------------------------------
*/
$q          = trim($_GET['q'] ?? '');
$sex_filter = $_GET['sex'] ?? '';
$status_filter = $_GET['status'] ?? '';
$export     = $_GET['export'] ?? '';

/*
|--------------------------------------------------------------------------
| SUMMARY STATS (always for the whole class — unfiltered)
|--------------------------------------------------------------------------
*/
function stat_count($conn, $school_id, $class_id, $where_extra = '', $types_extra = '', $params_extra = []) {
    $sql = "SELECT COUNT(*) AS t FROM students WHERE school_id = ? AND class_id = ? $where_extra";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return 0;
    $types = "ii" . $types_extra;
    $params = array_merge([$school_id, $class_id], $params_extra);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    $n = $r ? (int)mysqli_fetch_assoc($r)['t'] : 0;
    mysqli_stmt_close($stmt);
    return $n;
}

$total_all           = stat_count($conn, $school_id, $class_id);
$total_male          = stat_count($conn, $school_id, $class_id, "AND sex = 'Male'");
$total_female        = stat_count($conn, $school_id, $class_id, "AND sex = 'Female'");
$total_disability    = stat_count($conn, $school_id, $class_id, "AND has_disability = 1");
$total_transferred   = stat_count($conn, $school_id, $class_id, "AND is_transferred = 1");
$total_active        = stat_count($conn, $school_id, $class_id, "AND status = 'active'");
$total_inactive      = stat_count($conn, $school_id, $class_id, "AND status = 'inactive'");

$male_pct   = $total_all > 0 ? round(($total_male   / $total_all) * 100, 1) : 0;
$female_pct = $total_all > 0 ? round(($total_female / $total_all) * 100, 1) : 0;

/*
|--------------------------------------------------------------------------
| BUILD STUDENTS QUERY (with filters applied)
|--------------------------------------------------------------------------
*/
$where  = ["s.school_id = ?", "s.class_id = ?"];
$params = [$school_id, $class_id];
$types  = "ii";

if ($q !== '') {
    $where[] = "(s.name LIKE ? OR s.student_code LIKE ? OR s.index_number LIKE ? OR s.email LIKE ? OR s.phone LIKE ? OR s.father_name LIKE ? OR s.mother_name LIKE ? OR s.guardian_name LIKE ? OR s.id_number LIKE ?)";
    $like = "%{$q}%";
    for ($i = 0; $i < 9; $i++) { $params[] = $like; $types .= "s"; }
}
if (in_array($sex_filter, ['Male','Female'], true)) {
    $where[] = "s.sex = ?";
    $params[] = $sex_filter;
    $types .= "s";
}
if (in_array($status_filter, ['active','inactive'], true)) {
    $where[] = "s.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$students = [];
$sql = "SELECT s.* FROM students s WHERE " . implode(" AND ", $where) . " ORDER BY s.name ASC";
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($r)) $students[] = $row;
    mysqli_stmt_close($stmt);
}

$filtered_count = count($students);

/*
|--------------------------------------------------------------------------
| EXPORT EXCEL — based on current filter
| Filename includes the class name for clarity.
|--------------------------------------------------------------------------
*/
if ($export === 'excel') {
    $safe_class = preg_replace('/[^A-Za-z0-9_-]+/', '_', $full_class_label);
    $filename = 'students_report_' . $safe_class . '_' . date('Y-m-d_His') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    ?>
    <!DOCTYPE html>
    <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
    <head>
        <meta charset="UTF-8">
        <!--[if gte mso 9]>
        <xml>
            <x:ExcelWorkbook>
                <x:ExcelWorksheets>
                    <x:ExcelWorksheet>
                        <x:Name>Students Report</x:Name>
                        <x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
                    </x:ExcelWorksheet>
                </x:ExcelWorksheets>
            </x:ExcelWorkbook>
        </xml>
        <![endif]-->
        <style>
            table { border-collapse: collapse; font-family: Calibri, Arial, sans-serif; font-size: 11pt; }
            th { background: #2563eb; color: #ffffff; font-weight: bold; padding: 8px 10px; border: 1px solid #1e40af; text-align: left; vertical-align: middle; }
            td { padding: 6px 10px; border: 1px solid #cbd5e1; vertical-align: top; }
            .as-text { mso-number-format: "\@"; text-align: left; }
            .title { font-size: 16pt; font-weight: bold; color: #1e293b; padding: 6px 0; }
            .subtitle { font-size: 11pt; color: #475569; padding-bottom: 10px; }
            .meta td { border: none; padding: 3px 10px 3px 0; font-size: 10.5pt; color: #334155; }
            .meta .k { font-weight: bold; color: #0f172a; }
            .alt { background: #f8fafc; }
            .center { text-align: center; }
            .num { text-align: right; }
            .section-title { font-size: 12pt; font-weight: bold; color: #1e293b; padding: 12px 0 4px; }
        </style>
    </head>
    <body>

        <!-- HEADER -->
        <div class="title"><?= htmlspecialchars($school_name); ?> — Students Report — <?= htmlspecialchars($full_class_label); ?></div>
        <div class="subtitle">
            Level: <?= htmlspecialchars($level_map[$class['level']] ?? $class['level']); ?>
            · Academic Year: <?= htmlspecialchars($class['academic_year'] ?: '—'); ?>
            · Year of Study: <?= htmlspecialchars($class['year_of_study'] ?: '—'); ?>
            <?php if ($class['teacher_name']): ?>
                · Teacher: <?= htmlspecialchars($class['teacher_name']); ?>
            <?php else: ?>
                · Teacher: Not assigned
            <?php endif; ?>
            <br>
            Filters applied:
            <?= $q !== '' ? ' · Search: "' . htmlspecialchars($q) . '"' : ''; ?>
            <?= $sex_filter !== '' ? ' · Sex: ' . htmlspecialchars($sex_filter) : ''; ?>
            <?= $status_filter !== '' ? ' · Status: ' . htmlspecialchars(ucfirst($status_filter)) : ''; ?>
            <?= ($q === '' && $sex_filter === '' && $status_filter === '') ? ' None (all students)' : ''; ?>
            · Generated: <?= date('d M Y, H:i'); ?>
        </div>

        <!-- SUMMARY -->
        <div class="section-title">Class Summary</div>
        <table class="meta">
            <tr>
                <td class="k">Total students:</td><td><?= $total_all; ?></td>
                <td class="k">Male:</td><td><?= $total_male; ?> (<?= $male_pct; ?>%)</td>
                <td class="k">Female:</td><td><?= $total_female; ?> (<?= $female_pct; ?>%)</td>
            </tr>
            <tr>
                <td class="k">Active:</td><td><?= $total_active; ?></td>
                <td class="k">Inactive:</td><td><?= $total_inactive; ?></td>
                <td class="k">With disability:</td><td><?= $total_disability; ?></td>
            </tr>
            <tr>
                <td class="k">Transferred:</td><td><?= $total_transferred; ?></td>
                <td class="k">Assigned teacher:</td>
                <td colspan="3"><?= $class['teacher_name'] ? htmlspecialchars($class['teacher_name']) : 'Not assigned'; ?></td>
            </tr>
        </table>

        <!-- STUDENT LIST -->
        <div class="section-title">Students (<?= $filtered_count; ?> shown)</div>
        <table>
            <thead>
                <tr>
                    <th style="width:40px;">#</th>
                    <th>Student Code</th>
                    <th>Full Name</th>
                    <th>Sex</th>
                    <th>Date of Birth</th>
                    <th>Index Number</th>
                    <th>National ID</th>
                    <th>Father Name</th>
                    <th>Father Phone</th>
                    <th>Mother Name</th>
                    <th>Mother Phone</th>
                    <th>Guardian Name</th>
                    <th>Guardian Phone</th>
                    <th>Has Disability</th>
                    <th>Disability Details</th>
                    <th>Transferred</th>
                    <th>Transferred To</th>
                    <th>Status</th>
                    <th>Registered On</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                    <tr><td colspan="19" class="center">No students match the current filter.</td></tr>
                <?php else: $i = 1; foreach ($students as $s): ?>
                <tr class="<?= $i % 2 === 0 ? 'alt' : ''; ?>">
                    <td class="center"><?= $i++; ?></td>
                    <td class="as-text"><?= htmlspecialchars($s['student_code'] ?: ('STD-' . str_pad($s['id'], 5, '0', STR_PAD_LEFT))); ?></td>
                    <td><?= htmlspecialchars($s['name']); ?></td>
                    <td class="center"><?= htmlspecialchars($s['sex'] ?: ''); ?></td>
                    <td class="as-text"><?= htmlspecialchars($s['dob'] ?: ''); ?></td>
                    <td class="as-text"><?= htmlspecialchars($s['index_number'] ?: ''); ?></td>
                    <td class="as-text"><?= htmlspecialchars($s['id_number'] ?: ''); ?></td>
                    <td><?= htmlspecialchars($s['father_name'] ?: ''); ?></td>
                    <td class="as-text"><?= htmlspecialchars($s['father_phone'] ?: ''); ?></td>
                    <td><?= htmlspecialchars($s['mother_name'] ?: ''); ?></td>
                    <td class="as-text"><?= htmlspecialchars($s['mother_phone'] ?: ''); ?></td>
                    <td><?= htmlspecialchars($s['guardian_name'] ?: ''); ?></td>
                    <td class="as-text"><?= htmlspecialchars($s['guardian_phone'] ?: ''); ?></td>
                    <td class="center"><?= !empty($s['has_disability']) ? 'Yes' : 'No'; ?></td>
                    <td><?= htmlspecialchars($s['disability_details'] ?: ''); ?></td>
                    <td class="center"><?= !empty($s['is_transferred']) ? 'Yes' : 'No'; ?></td>
                    <td><?= htmlspecialchars($s['transferred_to'] ?: ''); ?></td>
                    <td class="center"><?= htmlspecialchars(ucfirst($s['status'])); ?></td>
                    <td class="as-text"><?= htmlspecialchars($s['created_at'] ?: ''); ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <br>
        <div style="font-size:9pt; color:#64748b;">
            Report generated by EducBridge · <?= htmlspecialchars($school_name); ?> · <?= date('d M Y, H:i'); ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/* Flash */
$flash_message = "";
$flash_type = "";
if (isset($_GET['deleted'])) { $flash_message = "Student deleted successfully."; $flash_type = "success"; }
if (isset($_GET['transferred'])) { $flash_message = "Student transfer recorded successfully."; $flash_type = "success"; }
if (isset($_GET['updated'])) { $flash_message = "Student updated successfully."; $flash_type = "success"; }
if (isset($_GET['error'])) { $flash_message = urldecode($_GET['error']); $flash_type = "error"; }
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($full_class_label); ?> | Educ Bridge</title>
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
            --pink: #ec4899; --pink-light: rgba(236,72,153,0.12);
            --teal: #14b8a6; --teal-light: rgba(20,184,166,0.12);
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
            --pink: #f9a8d4; --pink-light: rgba(236,72,153,0.18);
            --teal: #2dd4bf; --teal-light: rgba(20,184,166,0.18);
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

        /* Class hero */
        .class-hero {
            background: var(--bg-card);
            border:1px solid var(--border-color);
            border-radius:18px;
            padding: 22px 26px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
            background-image: linear-gradient(135deg, rgba(37,99,235,0.06), rgba(124,58,237,0.04));
        }
        .class-hero-left { display: flex; align-items: center; gap: 18px; }
        .class-avatar {
            width: 68px; height: 68px; border-radius: 18px;
            background: linear-gradient(135deg,#2563eb,#7c3aed);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Poppins', sans-serif; font-size: 24px; font-weight: 800;
            box-shadow: 0 10px 24px rgba(37,99,235,0.30);
            flex-shrink: 0;
        }
        .class-meta .title { font-family:'Poppins',sans-serif; font-size: 22px; font-weight: 800; color: var(--text-primary); }
        .class-meta .sub { display:flex; gap:10px; flex-wrap:wrap; margin-top: 6px; font-size: 13px; color: var(--text-muted); }

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

        /* Summary cards */
        .stats-grid {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap:14px;
            margin-bottom: 22px;
        }
        .stat-card {
            background: var(--bg-card);
            border:1px solid var(--border-color);
            border-radius:14px;
            padding: 16px 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content:''; position:absolute; left:0; top:0; bottom:0; width:4px; background: var(--primary);
        }
        .stat-card.blue::before   { background: linear-gradient(180deg, #2563eb, #3b82f6); }
        .stat-card.pink::before   { background: linear-gradient(180deg, #ec4899, #f472b6); }
        .stat-card.green::before  { background: linear-gradient(180deg, #10b981, #34d399); }
        .stat-card.orange::before { background: linear-gradient(180deg, #f59e0b, #fbbf24); }
        .stat-card.purple::before { background: linear-gradient(180deg, #7c3aed, #a78bfa); }
        .stat-card.red::before    { background: linear-gradient(180deg, #ef4444, #f87171); }
        .stat-card.teal::before   { background: linear-gradient(180deg, #14b8a6, #2dd4bf); }
        .stat-card.slate::before  { background: linear-gradient(180deg, #475569, #64748b); }

        .stat-icon {
            width: 44px; height: 44px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 18px;
            flex-shrink: 0;
            box-shadow: 0 6px 16px rgba(0,0,0,0.10);
        }
        .stat-icon.blue   { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .stat-icon.pink   { background: linear-gradient(135deg, #ec4899, #f472b6); }
        .stat-icon.green  { background: linear-gradient(135deg, #10b981, #34d399); }
        .stat-icon.orange { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .stat-icon.purple { background: linear-gradient(135deg, #7c3aed, #a78bfa); }
        .stat-icon.red    { background: linear-gradient(135deg, #ef4444, #f87171); }
        .stat-icon.teal   { background: linear-gradient(135deg, #14b8a6, #2dd4bf); }
        .stat-icon.slate  { background: linear-gradient(135deg, #475569, #64748b); }

        .stat-info .n { font-family:'Poppins',sans-serif; font-size: 22px; font-weight: 700; line-height:1.1; }
        .stat-info .l { font-size: 12.5px; color: var(--text-muted); margin-top: 3px; font-weight: 500; }
        .stat-info .s { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }

        /* Teacher card */
        .teacher-card {
            background: var(--bg-card);
            border:1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px 22px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            flex-wrap: wrap;
        }
        .teacher-card.assigned { border-left: 5px solid var(--success); }
        .teacher-card.unassigned { border-left: 5px solid var(--warning); background: linear-gradient(135deg, rgba(245,158,11,0.05), rgba(245,158,11,0.02)); }
        .teacher-card .t-left { display: flex; align-items: center; gap: 16px; }
        .teacher-avatar {
            width: 56px; height: 56px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Poppins', sans-serif; font-size: 20px; font-weight: 700;
            color: #fff; flex-shrink: 0;
        }
        .teacher-avatar.assigned   { background: linear-gradient(135deg, #10b981, #34d399); box-shadow: 0 8px 20px rgba(16,185,129,0.3); }
        .teacher-avatar.unassigned { background: linear-gradient(135deg, #f59e0b, #fbbf24); box-shadow: 0 8px 20px rgba(245,158,11,0.3); }
        .teacher-card .t-info .t-title { font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.8px; color: var(--text-muted); font-weight: 700; }
        .teacher-card .t-info .t-name  { font-family: 'Poppins', sans-serif; font-size: 18px; font-weight: 700; color: var(--text-primary); margin-top: 3px; }
        .teacher-card .t-info .t-meta  { font-size: 13px; color: var(--text-muted); margin-top: 4px; display: flex; gap: 14px; flex-wrap: wrap; }
        .teacher-card .t-info .t-meta i { color: var(--primary); margin-right: 4px; }

        .btn { display:inline-flex; align-items:center; gap:8px; padding:11px 20px; border-radius:10px; font-size:14px; font-weight:600; text-decoration:none; cursor:pointer; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-primary); transition:var(--transition); font-family:'Inter',sans-serif; }
        .btn:hover { border-color:var(--primary); color:var(--primary); transform:translateY(-1px); }
        .btn.primary { background:var(--primary); color:#fff; border-color:var(--primary); box-shadow:0 6px 18px rgba(37,99,235,0.32); }
        .btn.primary:hover { background:var(--primary-dark); color:#fff; }
        .btn.warning { background: var(--warning); color:#fff; border-color: var(--warning); box-shadow: 0 6px 18px rgba(245,158,11,0.32); }
        .btn.warning:hover { background: #d97706; color:#fff; }
        .btn.export { background: var(--bg-alt); border-color: var(--border-color); color: var(--text-secondary); font-size:13px; padding: 10px 16px; }
        .btn.export:hover { border-color: var(--success); color: var(--success); }

        /* Filters */
        .filters {
            background: var(--bg-card);
            border:1px solid var(--border-color);
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 18px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .filter-group { position: relative; display: flex; align-items: center; flex: 1 1 180px; min-width: 160px; }
        .filter-group i { position: absolute; left: 13px; color: var(--text-muted); font-size: 13px; pointer-events: none; }
        .filter-group input, .filter-group select {
            width: 100%;
            padding: 11px 14px 11px 36px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-alt);
            color: var(--text-primary);
            font-family: inherit;
            font-size: 13.5px;
            outline: none;
            transition: var(--transition);
        }
        .filter-group input:focus, .filter-group select:focus {
            border-color: var(--primary); background: var(--bg-card);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }

        /* Students table */
        .students-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
        }
        .students-card-head {
            padding: 16px 22px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            background: linear-gradient(135deg, rgba(37,99,235,0.04), rgba(124,58,237,0.02));
        }
        .students-card-head h3 {
            font-family:'Poppins',sans-serif; font-size: 16px; font-weight: 700;
            display: flex; align-items: center; gap: 10px;
        }
        .students-card-head h3 i { color: var(--primary); }
        .students-card-head .count-pill {
            font-size: 12.5px; font-weight: 700;
            background: var(--bg-alt); color: var(--text-secondary);
            padding: 5px 12px; border-radius: 50px;
        }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        thead th {
            background: var(--bg-alt);
            text-align: left;
            font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px;
            color: var(--text-muted);
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }
        tbody td {
            padding: 12px 16px;
            font-size: 13.5px;
            color: var(--text-primary);
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        tbody tr:hover { background: var(--bg-alt); }
        tbody tr:last-child td { border-bottom: none; }

        .st-cell { display: flex; align-items: center; gap: 12px; }
        .st-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: linear-gradient(135deg,#2563eb,#7c3aed);
            color: #fff; display: flex; align-items: center; justify-content: center;
            font-family: 'Poppins', sans-serif; font-size: 13.5px; font-weight: 700;
            flex-shrink: 0;
        }
        .st-info .n { font-weight: 600; font-size: 14px; }
        .st-info .s { font-size: 12px; color: var(--text-muted); margin-top: 2px; }

        .code-badge {
            display: inline-block; padding: 4px 10px; border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 12.5px; font-weight: 700; letter-spacing: 0.5px;
            background: rgba(37,99,235,0.10); color: var(--primary);
            border: 1px dashed rgba(37,99,235,0.35);
        }
        [data-theme="dark"] .code-badge {
            color: #93c5fd; border-color: rgba(147,197,253,0.4);
            background: rgba(59,130,246,0.15);
        }

        /* Three-dot action menu */
        .action-menu-wrap { position: relative; display: inline-block; }
        .action-dots {
            width: 34px; height: 34px; border-radius: 8px;
            border: 1.5px solid var(--border-color);
            background: var(--bg-card); color: var(--text-muted);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 15px; transition: var(--transition);
        }
        .action-dots:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-light); }
        .action-dropdown {
            display: none; position: absolute; right: 0; top: 100%; margin-top: 6px;
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 12px; box-shadow: var(--shadow-lg);
            min-width: 220px; z-index: 40; overflow: hidden;
        }
        .action-dropdown.show { display: block; }
        .action-dropdown a {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 16px;
            font-size: 13.5px; color: var(--text-primary);
            text-decoration: none; transition: var(--transition);
            border-bottom: 1px solid var(--border-color);
        }
        .action-dropdown a:last-child { border-bottom: none; }
        .action-dropdown a:hover { background: var(--bg-alt); }
        .action-dropdown a i { width: 18px; text-align: center; font-size: 14px; }
        .action-dropdown a .lbl { font-weight: 500; }
        .action-dropdown a.danger { color: var(--danger); }
        .action-dropdown a.danger:hover { background: var(--danger-light); }

        .empty-state {
            text-align: center; padding: 60px 20px; color: var(--text-muted);
        }
        .empty-state i { font-size: 50px; opacity: 0.35; margin-bottom: 14px; display: block; }
        .empty-state h3 { font-family: 'Poppins', sans-serif; font-size: 17px; font-weight: 700; color: var(--text-primary); margin-bottom: 6px; }
        .empty-state p { font-size: 14px; line-height: 1.6; margin-bottom: 18px; }

        .overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.5); z-index: 90; backdrop-filter: blur(2px); }
        .overlay.show { display: block; }

        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); box-shadow: 0 0 40px rgba(0,0,0,0.2); }
            .main { margin-left: 0; }
            .hamburger { display: flex; }
        }
        @media (max-width: 600px) {
            .content { padding: 20px 16px; }
            .topbar { padding: 12px 16px; }
            .page-title { font-size: 17px; }
            .class-meta .title { font-size: 18px; }
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
            <a href="school-classes.php" class="nav-item active"><i class="fas fa-layer-group nav-icon"></i> Classes</a>
            <a href="school-subjects.php" class="nav-item"><i class="fas fa-book nav-icon"></i> Subjects</a>
            <div class="nav-section-title">Communication</div>
            <a href="school-announcements.php" class="nav-item"><i class="fas fa-bullhorn nav-icon"></i> Announcements</a>
            <a href="school-events.php" class="nav-item"><i class="fas fa-calendar-alt nav-icon"></i> Events</a>
            <div class="nav-section-title">System</div>
            <a href="school-reports.php" class="nav-item active"><i class="fas fa-chart-line nav-icon"></i> Reports</a>
            <a href="school-settings.php" class="nav-item"><i class="fas fa-cog nav-icon"></i> Settings</a>
            <a href="school-applications.php" class="nav-item"><i class="fas fa-user-plus nav-icon"></i> Applications</a>
            <a href="school-marks.php" class="nav-item"><i class="fas fa-clipboard-list nav-icon"></i> Marks</a>
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
                    <div class="page-title"><?= htmlspecialchars($full_class_label); ?></div>
                    <div class="page-subtitle"><?= htmlspecialchars($school_name); ?> · <?= htmlspecialchars($level_map[$class['level']] ?? $class['level']); ?> <?= $class['academic_year'] ? '· ' . htmlspecialchars($class['academic_year']) : ''; ?></div>
                </div>
            </div>
            <div class="topbar-actions">
                <button class="topbar-btn theme-btn" id="themeToggle" aria-label="Toggle theme"><i class="fas fa-moon"></i></button>
                <a href="school-announcements.php" class="topbar-btn" title="Announcements"><i class="fas fa-bell"></i></a>
                <div class="topbar-avatar" title="<?= htmlspecialchars($school_admin); ?>"><?= htmlspecialchars($initials); ?></div>
            </div>
        </header>

        <main class="content">

            <a href="school-classes.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Classes</a>

            <?php if ($flash_message !== ""): ?>
                <div class="alert alert-<?= htmlspecialchars($flash_type); ?>">
                    <i class="fas <?= $flash_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($flash_message); ?></span>
                </div>
            <?php endif; ?>

            <!-- CLASS HERO -->
            <?php
                $init = "";
                foreach (explode(" ", trim($full_class_label)) as $p) {
                    if ($p !== "") { $init .= strtoupper($p[0]); if (strlen($init) >= 2) break; }
                }
                if ($init === "") $init = "C";
            ?>
            <div class="class-hero">
                <div class="class-hero-left">
                    <div class="class-avatar"><?= htmlspecialchars($init); ?></div>
                    <div class="class-meta">
                        <div class="title"><?= htmlspecialchars($full_class_label); ?></div>
                        <div class="sub">
                            <span class="pill <?= $level_colors[$class['level']] ?? 'gray'; ?>"><?= htmlspecialchars($level_map[$class['level']] ?? $class['level']); ?></span>
                            <?php if ($class['year_of_study']): ?>
                                <span><i class="fas fa-book" style="color:var(--text-muted);margin-right:5px;"></i><?= htmlspecialchars($class['year_of_study']); ?></span>
                            <?php endif; ?>
                            <?php if ($class['academic_year']): ?>
                                <span><i class="fas fa-calendar-alt" style="color:var(--text-muted);margin-right:5px;"></i><?= htmlspecialchars($class['academic_year']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <a href="?id=<?= (int)$class_id; ?>&<?= http_build_query(array_filter(['q'=>$q,'sex'=>$sex_filter,'status'=>$status_filter,'export'=>'excel'])); ?>" class="btn export">
                        <i class="fas fa-file-excel"></i> Export Report
                    </a>
                </div>
            </div>

            <!-- SUMMARY STATS -->
            <div class="stats-grid">
                <div class="stat-card slate">
                    <div class="stat-icon slate"><i class="fas fa-users"></i></div>
                    <div class="stat-info">
                        <div class="n"><?= $total_all; ?></div>
                        <div class="l">Total Students</div>
                        <div class="s"><?= $total_active; ?> active · <?= $total_inactive; ?> inactive</div>
                    </div>
                </div>

                <div class="stat-card blue">
                    <div class="stat-icon blue"><i class="fas fa-mars"></i></div>
                    <div class="stat-info">
                        <div class="n"><?= $total_male; ?></div>
                        <div class="l">Male</div>
                        <div class="s"><?= $male_pct; ?>% of class</div>
                    </div>
                </div>

                <div class="stat-card pink">
                    <div class="stat-icon pink"><i class="fas fa-venus"></i></div>
                    <div class="stat-info">
                        <div class="n"><?= $total_female; ?></div>
                        <div class="l">Female</div>
                        <div class="s"><?= $female_pct; ?>% of class</div>
                    </div>
                </div>

                <div class="stat-card orange">
                    <div class="stat-icon orange"><i class="fas fa-wheelchair"></i></div>
                    <div class="stat-info">
                        <div class="n"><?= $total_disability; ?></div>
                        <div class="l">With Disability</div>
                        <div class="s"><?= $total_disability === 0 ? 'No students' : 'Special needs'; ?></div>
                    </div>
                </div>

                <div class="stat-card red">
                    <div class="stat-icon red"><i class="fas fa-right-left"></i></div>
                    <div class="stat-info">
                        <div class="n"><?= $total_transferred; ?></div>
                        <div class="l">Transferred</div>
                        <div class="s">Left this class</div>
                    </div>
                </div>
            </div>

            <!-- TEACHER CARD -->
            <?php if ($class['teacher_name']): ?>
                <div class="teacher-card assigned">
                    <div class="t-left">
                        <div class="teacher-avatar assigned">
                            <?php
                                $ti = "";
                                foreach (explode(" ", trim($class['teacher_name'])) as $p) {
                                    if ($p !== "") { $ti .= strtoupper($p[0]); if (strlen($ti) >= 2) break; }
                                }
                                if ($ti === "") $ti = "T";
                                echo htmlspecialchars($ti);
                            ?>
                        </div>
                        <div class="t-info">
                            <div class="t-title">Assigned Teacher</div>
                            <div class="t-name"><?= htmlspecialchars($class['teacher_name']); ?></div>
                            <div class="t-meta">
                                <?php if ($class['teacher_subject']): ?>
                                    <span><i class="fas fa-book"></i> <?= htmlspecialchars($class['teacher_subject']); ?></span>
                                <?php endif; ?>
                                <?php if ($class['teacher_phone']): ?>
                                    <span><i class="fas fa-phone"></i> <?= htmlspecialchars($class['teacher_phone']); ?></span>
                                <?php endif; ?>
                                <?php if ($class['teacher_email']): ?>
                                    <span><i class="fas fa-envelope"></i> <?= htmlspecialchars($class['teacher_email']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <a href="school-class-assign-teacher.php?id=<?= (int)$class_id; ?>" class="btn">
                        <i class="fas fa-user-edit"></i> Change Teacher
                    </a>
                </div>
            <?php else: ?>
                <div class="teacher-card unassigned">
                    <div class="t-left">
                        <div class="teacher-avatar unassigned"><i class="fas fa-user-slash"></i></div>
                        <div class="t-info">
                            <div class="t-title">Assigned Teacher</div>
                            <div class="t-name">Not Assigned</div>
                            <div class="t-meta">
                                <span><i class="fas fa-info-circle"></i> No teacher is currently assigned to this class.</span>
                            </div>
                        </div>
                    </div>
                    <a href="school-class-assign-teacher.php?id=<?= (int)$class_id; ?>" class="btn warning">
                        <i class="fas fa-user-plus"></i> Assign Teacher
                    </a>
                </div>
            <?php endif; ?>

            <!-- FILTERS -->
            <form method="GET" action="school-class-view.php" class="filters">
                <input type="hidden" name="id" value="<?= (int)$class_id; ?>">

                <div class="filter-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="q" placeholder="Search by name, code, index, parent..." value="<?= htmlspecialchars($q); ?>">
                </div>

                <div class="filter-group">
                    <i class="fas fa-venus-mars"></i>
                    <select name="sex">
                        <option value="">All Sex</option>
                        <option value="Male"   <?= $sex_filter === 'Male'   ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?= $sex_filter === 'Female' ? 'selected' : ''; ?>>Female</option>
                    </select>
                </div>

                <div class="filter-group">
                    <i class="fas fa-toggle-on"></i>
                    <select name="status">
                        <option value="">All Status</option>
                        <option value="active"   <?= $status_filter === 'active'   ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <button type="submit" class="btn primary"><i class="fas fa-filter"></i> Filter</button>
                <a href="school-class-view.php?id=<?= (int)$class_id; ?>" class="btn"><i class="fas fa-rotate"></i> Reset</a>
            </form>

            <!-- STUDENT LIST -->
            <div class="students-card">
                <div class="students-card-head">
                    <h3><i class="fas fa-user-graduate"></i> Students in <?= htmlspecialchars($full_class_label); ?></h3>
                    <span class="count-pill">
                        <?= $filtered_count; ?> student<?= $filtered_count === 1 ? '' : 's'; ?>
                        <?= ($q !== '' || $sex_filter !== '' || $status_filter !== '') ? ' (filtered)' : ''; ?>
                    </span>
                </div>

                <?php if (!empty($students)): ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Code</th>
                                    <th>Sex</th>
                                    <th>Date of Birth</th>
                                    <th>Status</th>
                                    <th style="width:70px;text-align:center;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $s):
                                    $si = "";
                                    foreach (explode(" ", trim($s['name'])) as $p) {
                                        if ($p !== "") { $si .= strtoupper($p[0]); if (strlen($si) >= 2) break; }
                                    }
                                    if ($si === "") $si = "S";
                                    $code = $s['student_code'] ?: ('STD-' . str_pad($s['id'], 5, '0', STR_PAD_LEFT));
                                ?>
                                    <tr>
                                        <td>
                                            <div class="st-cell">
                                                <div class="st-avatar"><?= htmlspecialchars($si); ?></div>
                                                <div class="st-info">
                                                    <div class="n"><?= htmlspecialchars($s['name']); ?></div>
                                                    <div class="s">
                                                        <?php if (!empty($s['index_number'])): ?>
                                                            <span style="font-family:'Courier New',monospace;">#<?= htmlspecialchars($s['index_number']); ?></span>
                                                        <?php else: ?>
                                                            ID #<?= (int)$s['id']; ?>
                                                        <?php endif; ?>
                                                        <?php if (!empty($s['is_transferred'])): ?>
                                                            · <span class="pill red" style="font-size:10px;">Transferred</span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($s['has_disability'])): ?>
                                                            · <span class="pill orange" style="font-size:10px;">Disability</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><span class="code-badge"><?= htmlspecialchars($code); ?></span></td>
                                        <td>
                                            <?php if ($s['sex'] === 'Male'): ?>
                                                <span class="pill blue">Male</span>
                                            <?php elseif ($s['sex'] === 'Female'): ?>
                                                <span class="pill pink">Female</span>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="font-size:13px;color:var(--text-secondary);">
                                                <?= htmlspecialchars($s['dob'] ?: '—'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($s['status'] === 'active'): ?>
                                                <span class="pill green">Active</span>
                                            <?php else: ?>
                                                <span class="pill gray">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <div class="action-menu-wrap">
                                                <button type="button" class="action-dots" onclick="toggleActionMenu(this)" aria-label="Actions">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <div class="action-dropdown">
                                                    <a href="school-edit-student.php?id=<?= (int)$s['id']; ?>">
                                                        <i class="fas fa-pen" style="color:var(--primary);"></i>
                                                        <span class="lbl">Edit</span>
                                                    </a>
                                                    <a href="school-print-student.php?id=<?= (int)$s['id']; ?>" target="_blank">
                                                        <i class="fas fa-print" style="color:var(--success);"></i>
                                                        <span class="lbl">Print Proof of Registration</span>
                                                    </a>
                                                    <a href="school-transfer-student.php?id=<?= (int)$s['id']; ?>">
                                                        <i class="fas fa-right-left" style="color:var(--warning);"></i>
                                                        <span class="lbl">Transfer Student</span>
                                                    </a>
                                                    <a href="javascript:void(0)" class="danger" onclick="confirmDelete(<?= (int)$s['id']; ?>, '<?= htmlspecialchars(addslashes($s['name'])); ?>')">
                                                        <i class="fas fa-trash-alt"></i>
                                                        <span class="lbl">Delete</span>
                                                    </a>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-user-graduate"></i>
                        <h3>No students found</h3>
                        <p>
                            <?php if ($q !== '' || $sex_filter !== '' || $status_filter !== ''): ?>
                                No students matched your filters in this class.
                                <br><a href="school-class-view.php?id=<?= (int)$class_id; ?>" style="color:var(--primary);font-weight:600;">Clear filters</a>
                            <?php else: ?>
                                This class has no students yet.
                            <?php endif; ?>
                        </p>
                        <a href="school-add-student.php" class="btn primary">
                            <i class="fas fa-plus"></i> Add Student
                        </a>
                    </div>
                <?php endif; ?>
            </div>

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
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');
        function openSidebar() { sidebar.classList.add('open'); overlay.classList.add('show'); }
        function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('show'); }
        hamburger.addEventListener('click', function () {
            if (sidebar.classList.contains('open')) closeSidebar(); else openSidebar();
        });
        overlay.addEventListener('click', closeSidebar);
    })();

    // ACTION MENU
    function toggleActionMenu(btn) {
        document.querySelectorAll('.action-dropdown.show').forEach(function (d) {
            if (d !== btn.nextElementSibling) d.classList.remove('show');
        });
        btn.nextElementSibling.classList.toggle('show');
    }
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.action-menu-wrap')) {
            document.querySelectorAll('.action-dropdown.show').forEach(function (d) {
                d.classList.remove('show');
            });
        }
    });

    function confirmDelete(id, name) {
        if (confirm('Are you sure you want to delete ' + name + '?\nThis action cannot be undone.')) {
            window.location.href = 'school-delete-student.php?id=' + id + '&return_class=<?= (int)$class_id; ?>';
        }
    }
</script>

</body>
</html>