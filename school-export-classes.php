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
$school_code  = $_SESSION['school_code']  ?? '';

$type = $_GET['type'] ?? 'pdf'; // pdf | excel

/*
|--------------------------------------------------------------------------
| FILTERS (same as school-classes.php)
|--------------------------------------------------------------------------
*/
$q      = trim($_GET['q'] ?? '');
$level  = $_GET['level'] ?? '';
$year   = trim($_GET['year'] ?? '');
$status = $_GET['status'] ?? '';

$where  = ["c.school_id = ?"];
$params = [$school_id];
$types  = "i";

if ($q !== '') {
    $where[] = "(c.class_name LIKE ? OR c.academic_year LIKE ? OR c.room LIKE ?)";
    $like = "%{$q}%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= "sss";
}
if (in_array($level, ['Nursery','Primary','O_Level','A_Level'], true)) {
    $where[] = "c.level = ?"; $params[] = $level; $types .= "s";
}
if ($year !== '') {
    $where[] = "c.academic_year = ?"; $params[] = $year; $types .= "s";
}
if (in_array($status, ['active','inactive'], true)) {
    $where[] = "c.status = ?"; $params[] = $status; $types .= "s";
}

$where_sql = implode(" AND ", $where);

/*
|--------------------------------------------------------------------------
| FETCH ALL (no pagination for export)
|--------------------------------------------------------------------------
*/
$stmt = mysqli_prepare($conn, "
    SELECT c.*, t.name AS teacher_name
    FROM classes c
    LEFT JOIN teachers t ON t.id = c.class_teacher_id AND t.school_id = c.school_id
    WHERE $where_sql
    ORDER BY c.level ASC, c.class_name ASC
");
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$rows = [];
while ($r = mysqli_fetch_assoc($res)) {
    $rows[] = $r;
}
mysqli_stmt_close($stmt);

$level_map = [
    'Nursery' => 'Nursery',
    'Primary' => 'Primary',
    'O_Level' => "O' Level",
    'A_Level' => "A' Level",
];

/*
|--------------------------------------------------------------------------
| FILTER SUMMARY
|--------------------------------------------------------------------------
*/
$filter_bits = [];
if ($q !== '')      $filter_bits[] = "Search: \"{$q}\"";
if ($level !== '')  $filter_bits[] = "Level: " . ($level_map[$level] ?? $level);
if ($year !== '')   $filter_bits[] = "Academic Year: {$year}";
if ($status !== '') $filter_bits[] = "Status: " . ucfirst($status);
$filter_summary = empty($filter_bits) ? 'All classes (no filters)' : implode(' · ', $filter_bits);

$stamp = date('Y-m-d_His');

/*
|--------------------------------------------------------------------------
| EXCEL (CSV) EXPORT
|--------------------------------------------------------------------------
*/
if ($type === 'excel') {

    $filename = 'classes_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $school_code ?: 'school') . '_' . $stamp . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'No.',
        'Class Name',
        'Level',
        'Academic Year',
        'Class Teacher',
        'Room',
        'Capacity',
        'Status',
        'Created',
    ]);

    $i = 1;
    foreach ($rows as $r) {
        fputcsv($out, [
            $i++,
            $r['class_name'],
            $level_map[$r['level']] ?? $r['level'],
            $r['academic_year'],
            $r['teacher_name'] ?: '',
            $r['room'] ?: '',
            $r['capacity'] !== null ? (int) $r['capacity'] : '',
            ucfirst($r['status']),
            $r['created_at'],
        ]);
    }

    fputcsv($out, []);
    fputcsv($out, ['Filters:', $filter_summary]);
    fputcsv($out, ['Total:', count($rows)]);
    fputcsv($out, ['School:', $school_name]);
    fputcsv($out, ['School Code:', $school_code]);
    fputcsv($out, ['Exported At:', date('Y-m-d H:i:s')]);

    fclose($out);
    exit;
}

/*
|--------------------------------------------------------------------------
| PDF EXPORT (print-ready HTML)
|--------------------------------------------------------------------------
*/
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Classes Report — <?= htmlspecialchars($school_name); ?></title>
    <link rel="icon" type="image/png" href="logo.png">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; color: #0f172a; background: #fff; margin: 0; padding: 24px 28px; font-size: 12.5px; }

        .head { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding-bottom: 14px; border-bottom: 3px solid #2563eb; margin-bottom: 16px; }
        .head .brand { display: flex; align-items: center; gap: 12px; }
        .head .brand img { width: 46px; height: 46px; border-radius: 50%; object-fit: contain; border: 2px solid #e2e8f0; background: #fff; }
        .head .brand-text h1 { margin: 0; font-size: 20px; color: #0f172a; letter-spacing: -0.3px; }
        .head .brand-text p { margin: 3px 0 0; font-size: 12.5px; color: #64748b; }

        .head .meta { text-align: right; font-size: 11.5px; color: #475569; line-height: 1.6; }
        .head .meta .school { font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 2px; }
        .head .meta .code { font-family: 'Courier New', monospace; color: #2563eb; font-weight: 700; }

        .filter-bar { background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; font-size: 12px; color: #475569; margin-bottom: 14px; }
        .filter-bar strong { color: #0f172a; }

        table { width: 100%; border-collapse: collapse; }
        thead th { background: #f1f5f9; text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: #64748b; padding: 9px 8px; border-bottom: 1.5px solid #cbd5e1; white-space: nowrap; }
        tbody td { padding: 8px 8px; font-size: 11.5px; border-bottom: 1px solid #e2e8f0; vertical-align: top; color: #0f172a; }
        tbody tr:nth-child(even) td { background: #fafbfc; }

        .tag { display: inline-block; padding: 1.5px 7px; border-radius: 50px; font-size: 10.5px; font-weight: 700; letter-spacing: 0.3px; }
        .tag.blue { background: #dbeafe; color: #2563eb; }
        .tag.green { background: #d1fae5; color: #059669; }
        .tag.red { background: #fee2e2; color: #dc2626; }
        .tag.gray { background: #e5e7eb; color: #475569; }
        .tag.purple { background: #ede9fe; color: #7c3aed; }
        .tag.orange { background: #fef3c7; color: #b45309; }
        .tag.teal { background: #ccfbf1; color: #0d9488; }
        .tag.pink { background: #fce7f3; color: #db2777; }

        .small { color: #64748b; font-size: 11px; }
        .mono { font-family: 'Courier New', monospace; }

        .foot { margin-top: 18px; padding-top: 12px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; font-size: 11px; color: #64748b; }

        @page { size: A4 landscape; margin: 12mm; }
        @media print { body { padding: 0; } .no-print { display: none !important; } }

        .no-print { text-align: center; margin-bottom: 16px; }
        .no-print button { padding: 10px 20px; background: #2563eb; color: #fff; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; }
        .no-print button:hover { background: #1d4ed8; }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="window.print()">🖨️ Print / Save as PDF</button>
    </div>

    <div class="head">
        <div class="brand">
            <img src="logo.png" alt="Educ Bridge">
            <div class="brand-text">
                <h1>Educ Bridge — Classes Report</h1>
                <p><?= htmlspecialchars($filter_summary); ?></p>
            </div>
        </div>
        <div class="meta">
            <div class="school"><?= htmlspecialchars($school_name); ?></div>
            <div class="code"><?= htmlspecialchars($school_code); ?></div>
            <div>Generated: <?= date('M j, Y · H:i'); ?></div>
            <div>Total classes: <strong><?= count($rows); ?></strong></div>
        </div>
    </div>

    <?php if (empty($rows)): ?>
        <div class="filter-bar" style="text-align:center;">No classes matched the current filters.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Class Name</th>
                    <th>Level</th>
                    <th>Academic Year</th>
                    <th>Class Teacher</th>
                    <th>Room</th>
                    <th>Capacity</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($rows as $c): ?>
                    <?php
                        $lvl = $c['level'];
                        $lv_lbl = $level_map[$lvl] ?? $lvl;
                        $lv_col = [
                            'Nursery' => 'pink',
                            'Primary' => 'blue',
                            'O_Level' => 'green',
                            'A_Level' => 'orange',
                        ][$lvl] ?? 'gray';
                    ?>
                    <tr>
                        <td><?= $i++; ?></td>
                        <td><div style="font-weight:600;"><?= htmlspecialchars($c['class_name']); ?></div></td>
                        <td><span class="tag <?= $lv_col; ?>"><?= htmlspecialchars($lv_lbl); ?></span></td>
                        <td><span class="tag gray mono"><?= htmlspecialchars($c['academic_year']); ?></span></td>
                        <td>
                            <?php if (!empty($c['teacher_name'])): ?>
                                <?= htmlspecialchars($c['teacher_name']); ?>
                            <?php else: ?>
                                <span class="small">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= !empty($c['room']) ? htmlspecialchars($c['room']) : '<span class="small">—</span>'; ?></td>
                        <td><?= $c['capacity'] !== null ? (int)$c['capacity'] : '<span class="small">—</span>'; ?></td>
                        <td>
                            <?php if ($c['status'] === 'active'): ?>
                                <span class="tag green">Active</span>
                            <?php else: ?>
                                <span class="tag red">Inactive</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="foot">
        <div>Educ Bridge · Connecting Parents, Schools, and Education</div>
        <div>Page generated automatically · <?= date('Y-m-d H:i:s'); ?></div>
    </div>

    <script>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 350);
        });
    </script>
</body>
</html>