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
| FILTERS
|--------------------------------------------------------------------------
*/
$q       = trim($_GET['q'] ?? '');
$level   = $_GET['level']   ?? '';
$status  = $_GET['status']  ?? '';

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

/* Single subject export */
$single_id = (int)($_GET['subject_id'] ?? 0);
if ($single_id > 0) {
    $where_sql = "s.school_id = ? AND s.id = ?";
    $params = [$school_id, $single_id];
    $types  = "ii";
}

/*
|--------------------------------------------------------------------------
| FETCH SUBJECTS
|--------------------------------------------------------------------------
*/
$subjects = [];

$stmt = mysqli_prepare($conn, "
    SELECT s.*
    FROM subjects s
    WHERE $where_sql
    ORDER BY s.subject_name ASC
");
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) {
    $subjects[] = $row;
}
mysqli_stmt_close($stmt);

$subject_ids = array_map(fn($r) => (int)$r['id'], $subjects);

/*
|--------------------------------------------------------------------------
| FETCH PER-CLASS DETAILS FOR ALL VISIBLE SUBJECTS
|--------------------------------------------------------------------------
| Builds:
|   $details[$subject_id] = [
|       classes    => [ ['class_name'=>..., 'level'=>..., 'room'=>..., 'teacher'=>..., 'credits'=>..., 'week_hours'=>..., 'max_marks'=>...], ... ],
|       class_count, levels, teacher_list, unassigned,
|       credits_min, credits_max,
|       hours_min, hours_max,
|       marks_min, marks_max
|   ]
*/
$details = [];

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
            c.room,
            sct.teacher_id,
            t.name AS teacher_name
        FROM subject_classes sc
        INNER JOIN classes c ON c.id = sc.class_id
        LEFT JOIN subject_class_teachers sct
               ON sct.subject_id = sc.subject_id
              AND sct.class_id = sc.class_id
        LEFT JOIN teachers t ON t.id = sct.teacher_id AND t.school_id = sc.school_id
        WHERE sc.school_id = ? AND sc.subject_id IN ($placeholders)
        ORDER BY FIELD(c.level,'Nursery','Primary','O_Level','A_Level'), c.class_name ASC
    ";

    $d_stmt = mysqli_prepare($conn, $sql);
    if ($d_stmt) {
        mysqli_stmt_bind_param($d_stmt, $types_d, ...$params_d);
        mysqli_stmt_execute($d_stmt);
        $d_res = mysqli_stmt_get_result($d_stmt);

        while ($row = mysqli_fetch_assoc($d_res)) {
            $sid = (int)$row['subject_id'];

            if (!isset($details[$sid])) {
                $details[$sid] = [
                    'classes'     => [],
                    'class_count' => 0,
                    'levels'      => [],
                    'teachers'    => [],
                    'unassigned'  => 0,
                    'credits_min' => null, 'credits_max' => null,
                    'hours_min'   => null, 'hours_max'   => null,
                    'marks_min'   => null, 'marks_max'   => null,
                ];
            }

            $details[$sid]['classes'][] = [
                'class_name'  => $row['class_name'],
                'level'       => $row['class_level'],
                'room'        => $row['room'],
                'teacher'     => $row['teacher_name'],
                'credits'     => $row['credits'],
                'week_hours'  => $row['week_hours'],
                'max_marks'   => $row['max_marks'],
            ];

            $details[$sid]['class_count']++;
            $lv = $row['class_level'];
            if ($lv) $details[$sid]['levels'][$lv] = true;

            $tid = (int)($row['teacher_id'] ?? 0);
            if ($tid > 0 && !empty($row['teacher_name'])) {
                $details[$sid]['teachers'][$tid] = $row['teacher_name'];
            } else {
                $details[$sid]['unassigned']++;
            }

            if ($row['credits'] !== null) {
                $c = (float)$row['credits'];
                if ($details[$sid]['credits_min'] === null || $c < $details[$sid]['credits_min']) $details[$sid]['credits_min'] = $c;
                if ($details[$sid]['credits_max'] === null || $c > $details[$sid]['credits_max']) $details[$sid]['credits_max'] = $c;
            }
            if ($row['week_hours'] !== null) {
                $h = (float)$row['week_hours'];
                if ($details[$sid]['hours_min'] === null || $h < $details[$sid]['hours_min']) $details[$sid]['hours_min'] = $h;
                if ($details[$sid]['hours_max'] === null || $h > $details[$sid]['hours_max']) $details[$sid]['hours_max'] = $h;
            }
            if ($row['max_marks'] !== null) {
                $m = (int)$row['max_marks'];
                if ($details[$sid]['marks_min'] === null || $m < $details[$sid]['marks_min']) $details[$sid]['marks_min'] = $m;
                if ($details[$sid]['marks_max'] === null || $m > $details[$sid]['marks_max']) $details[$sid]['marks_max'] = $m;
            }
        }
        mysqli_stmt_close($d_stmt);
    }
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function fmt_num($v) {
    if ($v === null) return null;
    return rtrim(rtrim(number_format((float)$v, 1), '0'), '.');
}

function range_str($min, $max, $is_int = false) {
    if ($min === null && $max === null) return '—';
    $a = $is_int ? (string)(int)$min : (string)fmt_num($min);
    $b = $is_int ? (string)(int)$max : (string)fmt_num($max);
    if ($min === $max) return $a;
    return $a . '–' . $b;
}

function excel_text($value) {
    $value = (string)$value;
    if ($value === '') return '';
    return '="' . str_replace('"', '""', $value) . '"';
}

/*
|--------------------------------------------------------------------------
| HUMAN FILTER SUMMARY
|--------------------------------------------------------------------------
*/
$filter_bits = [];
if ($single_id > 0)         $filter_bits[] = "Single subject (ID: {$single_id})";
if ($q !== '')              $filter_bits[] = "Search: \"{$q}\"";
if ($level !== '')          $filter_bits[] = "Level: " . ($level_map[$level] ?? $level);
if ($status !== '')         $filter_bits[] = "Status: " . ucfirst($status);
$filter_summary = empty($filter_bits) ? 'All subjects (no filters)' : implode(' · ', $filter_bits);

$stamp = date('Y-m-d_His');

/*
|--------------------------------------------------------------------------
| EXCEL (CSV) — Two sections
|--------------------------------------------------------------------------
| Section 1: One row per subject (summary)
| Section 2: One row per subject × class (detailed)
*/
if ($type === 'excel') {

    $filename = 'subjects_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $school_code ?: 'school') . '_' . $stamp . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM

    /*
    |----------------------------------------------------------------------
    | SECTION 1: SUBJECTS SUMMARY
    |----------------------------------------------------------------------
    */
    fputcsv($out, ['SUBJECTS SUMMARY']);
    fputcsv($out, ['No.', 'Subject Name', 'Code', 'Levels', 'Classes', 'Credits', 'Week Hrs', 'Max Marks', 'Teachers', 'Coverage', 'Status', 'Created']);

    $i = 1;
    foreach ($subjects as $s) {
        $sid = (int)$s['id'];
        $d   = $details[$sid] ?? [
            'classes' => [], 'class_count' => 0, 'levels' => [], 'teachers' => [],
            'unassigned' => 0,
            'credits_min' => null, 'credits_max' => null,
            'hours_min' => null, 'hours_max' => null,
            'marks_min' => null, 'marks_max' => null,
        ];

        /* Levels */
        $lvl_str = '—';
        if (!empty($d['levels'])) {
            $labels = [];
            foreach (array_keys($d['levels']) as $lv) {
                $labels[] = $level_map[$lv] ?? $lv;
            }
            $lvl_str = implode(', ', $labels);
        }

        /* Teachers */
        $teacher_names = array_values($d['teachers']);
        if (count($teacher_names) === 0) {
            $teacher_str = 'Unassigned';
        } elseif (count($teacher_names) === 1 && $d['unassigned'] === 0) {
            $teacher_str = $teacher_names[0];
        } else {
            $teacher_str = count($teacher_names) . ' teacher' . (count($teacher_names) === 1 ? '' : 's');
            if ($d['unassigned'] > 0) {
                $teacher_str .= ' (' . $d['unassigned'] . ' gap' . ($d['unassigned'] === 1 ? '' : 's') . ')';
            }
        }

        /* Coverage */
        if ($d['class_count'] === 0) {
            $coverage = 'No classes';
        } elseif ($d['unassigned'] === 0) {
            $coverage = 'Full';
        } elseif ($d['unassigned'] === $d['class_count']) {
            $coverage = 'None';
        } else {
            $coverage = ($d['class_count'] - $d['unassigned']) . '/' . $d['class_count'];
        }

        fputcsv($out, [
            $i++,
            $s['subject_name'],
            $s['subject_code'] ?: '',
            $lvl_str,
            $d['class_count'],
            range_str($d['credits_min'], $d['credits_max']),
            range_str($d['hours_min'],   $d['hours_max']),
            range_str($d['marks_min'],   $d['marks_max'], true),
            $teacher_str,
            $coverage,
            ucfirst($s['status']),
            $s['created_at'],
        ]);
    }

    fputcsv($out, []);
    fputcsv($out, ['Filters:', $filter_summary]);
    fputcsv($out, ['Total subjects:', count($subjects)]);
    fputcsv($out, ['School:', $school_name]);
    fputcsv($out, ['School Code:', excel_text($school_code)]);
    fputcsv($out, ['Exported At:', date('Y-m-d H:i:s')]);

    /*
    |----------------------------------------------------------------------
    | SECTION 2: DETAILED — SUBJECT × CLASS
    |----------------------------------------------------------------------
    */
    fputcsv($out, []);
    fputcsv($out, []);
    fputcsv($out, ['DETAILED — one row per subject & class']);
    fputcsv($out, ['No.', 'Subject', 'Code', 'Class', 'Level', 'Room', 'Teacher', 'Credits', 'Week Hrs', 'Max Marks']);

    $k = 1;
    foreach ($subjects as $s) {
        $sid = (int)$s['id'];
        if (empty($details[$sid]['classes'])) continue;

        foreach ($details[$sid]['classes'] as $row) {
            fputcsv($out, [
                $k++,
                $s['subject_name'],
                $s['subject_code'] ?: '',
                $row['class_name'],
                $level_map[$row['level']] ?? $row['level'],
                $row['room'] ?: '',
                $row['teacher'] ?: 'Unassigned',
                $row['credits']    !== null ? fmt_num($row['credits'])    : '',
                $row['week_hours'] !== null ? fmt_num($row['week_hours']) : '',
                $row['max_marks']  !== null ? (int)$row['max_marks']      : '',
            ]);
        }
    }

    fputcsv($out, []);
    fputcsv($out, ['Total rows (subject × class):', $k - 1]);
    fputcsv($out, ['Exported At:', date('Y-m-d H:i:s')]);

    fclose($out);
    exit;
}

/*
|--------------------------------------------------------------------------
| PDF EXPORT
|--------------------------------------------------------------------------
*/
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Subjects Report — <?= htmlspecialchars($school_name); ?></title>
    <link rel="icon" type="image/png" href="logo.png">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; color: #0f172a; background: #fff; margin: 0; padding: 24px 28px; font-size: 12px; }

        .head { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding-bottom: 14px; border-bottom: 3px solid #2563eb; margin-bottom: 16px; }
        .head .brand { display: flex; align-items: center; gap: 12px; }
        .head .brand img { width: 46px; height: 46px; border-radius: 50%; object-fit: contain; border: 2px solid #e2e8f0; background: #fff; }
        .head .brand-text h1 { margin: 0; font-size: 20px; letter-spacing: -0.3px; }
        .head .brand-text p { margin: 3px 0 0; font-size: 12.5px; color: #64748b; }

        .head .meta { text-align: right; font-size: 11.5px; color: #475569; line-height: 1.6; }
        .head .meta .school { font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 2px; }
        .head .meta .code { font-family: 'Courier New', monospace; color: #2563eb; font-weight: 700; }

        .filter-bar { background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; font-size: 12px; color: #475569; margin-bottom: 14px; }
        .filter-bar strong { color: #0f172a; }

        .section-heading {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            margin: 18px 0 8px;
            padding-bottom: 6px;
            border-bottom: 2px solid #cbd5e1;
            letter-spacing: -0.2px;
        }
        .section-heading:first-of-type { margin-top: 0; }

        table { width: 100%; border-collapse: collapse; }
        thead th { background: #f1f5f9; text-align: left; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: #64748b; padding: 8px 7px; border-bottom: 1.5px solid #cbd5e1; white-space: nowrap; }
        tbody td { padding: 7px 7px; font-size: 11px; border-bottom: 1px solid #e2e8f0; vertical-align: top; color: #0f172a; }
        tbody tr:nth-child(even) td { background: #fafbfc; }

        .tag { display: inline-block; padding: 1.5px 7px; border-radius: 50px; font-size: 10px; font-weight: 700; }
        .tag.blue   { background: #dbeafe; color: #2563eb; }
        .tag.green  { background: #d1fae5; color: #059669; }
        .tag.red    { background: #fee2e2; color: #dc2626; }
        .tag.gray   { background: #e5e7eb; color: #475569; }
        .tag.orange { background: #fef3c7; color: #b45309; }
        .tag.pink   { background: #fce7f3; color: #db2777; }
        .tag.teal   { background: #ccfbf1; color: #0d9488; }

        .coverage-badge { display: inline-block; padding: 1.5px 8px; border-radius: 50px; font-size: 10px; font-weight: 700; }
        .coverage-badge.full    { background: #d1fae5; color: #059669; }
        .coverage-badge.partial { background: #fef3c7; color: #b45309; }
        .coverage-badge.none    { background: #fee2e2; color: #dc2626; }
        .coverage-badge.na      { background: #e5e7eb; color: #475569; }

        .small { color: #64748b; font-size: 10.5px; }
        .mono { font-family: 'Courier New', monospace; }

        .foot { margin-top: 22px; padding-top: 12px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; font-size: 10.5px; color: #64748b; }

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
                <h1>Educ Bridge — Subjects Report</h1>
                <p><?= htmlspecialchars($filter_summary); ?></p>
            </div>
        </div>
        <div class="meta">
            <div class="school"><?= htmlspecialchars($school_name); ?></div>
            <div class="code"><?= htmlspecialchars($school_code); ?></div>
            <div>Generated: <?= date('M j, Y · H:i'); ?></div>
            <div>Total subjects: <strong><?= count($subjects); ?></strong></div>
        </div>
    </div>

    <?php if (empty($subjects)): ?>
        <div class="filter-bar" style="text-align:center;">No subjects matched the current filters.</div>
    <?php else: ?>

        <!-- ====================== SUMMARY TABLE ====================== -->
        <div class="section-heading">Summary — Subjects</div>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Subject</th>
                    <th>Code</th>
                    <th>Levels</th>
                    <th>Classes</th>
                    <th>Credits</th>
                    <th>Week Hrs</th>
                    <th>Max Marks</th>
                    <th>Teachers</th>
                    <th>Coverage</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($subjects as $s): ?>
                    <?php
                        $sid = (int)$s['id'];
                        $d   = $details[$sid] ?? [
                            'classes' => [], 'class_count' => 0, 'levels' => [], 'teachers' => [],
                            'unassigned' => 0,
                            'credits_min' => null, 'credits_max' => null,
                            'hours_min' => null, 'hours_max' => null,
                            'marks_min' => null, 'marks_max' => null,
                        ];

                        /* Levels */
                        $lvl_labels = [];
                        foreach (array_keys($d['levels']) as $lv) {
                            $lvl_labels[] = $level_map[$lv] ?? $lv;
                        }

                        /* Teachers */
                        $teacher_names = array_values($d['teachers']);
                        if (count($teacher_names) === 0) {
                            $teacher_disp = '<span class="small">Unassigned</span>';
                        } elseif (count($teacher_names) === 1 && $d['unassigned'] === 0) {
                            $teacher_disp = htmlspecialchars($teacher_names[0]);
                        } else {
                            $teacher_disp = count($teacher_names) . ' teacher' . (count($teacher_names) === 1 ? '' : 's');
                            if ($d['unassigned'] > 0) {
                                $teacher_disp .= ' <span class="small">(' . $d['unassigned'] . ' gap' . ($d['unassigned'] === 1 ? '' : 's') . ')</span>';
                            }
                        }

                        /* Coverage */
                        if ($d['class_count'] === 0) {
                            $cov = 'na'; $cov_label = 'No classes';
                        } elseif ($d['unassigned'] === 0) {
                            $cov = 'full'; $cov_label = 'Full';
                        } elseif ($d['unassigned'] === $d['class_count']) {
                            $cov = 'none'; $cov_label = 'None';
                        } else {
                            $cov = 'partial'; $cov_label = ($d['class_count'] - $d['unassigned']) . '/' . $d['class_count'];
                        }
                    ?>
                    <tr>
                        <td><?= $i++; ?></td>
                        <td><div style="font-weight:600;"><?= htmlspecialchars($s['subject_name']); ?></div></td>
                        <td>
                            <?php if (!empty($s['subject_code'])): ?>
                                <span class="mono small"><?= htmlspecialchars($s['subject_code']); ?></span>
                            <?php else: ?>
                                <span class="small">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($lvl_labels)): ?>
                                <?php foreach ($lvl_labels as $lb): ?>
                                    <span class="tag teal"><?= htmlspecialchars($lb); ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="small">—</span>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= $d['class_count']; ?></strong></td>
                        <td><?= htmlspecialchars(range_str($d['credits_min'], $d['credits_max'])); ?></td>
                        <td><?= htmlspecialchars(range_str($d['hours_min'], $d['hours_max'])); ?></td>
                        <td><?= htmlspecialchars(range_str($d['marks_min'], $d['marks_max'], true)); ?></td>
                        <td><?= $teacher_disp; ?></td>
                        <td><span class="coverage-badge <?= $cov; ?>"><?= $cov_label; ?></span></td>
                        <td>
                            <?php if ($s['status'] === 'active'): ?>
                                <span class="tag green">Active</span>
                            <?php else: ?>
                                <span class="tag red">Inactive</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- ====================== DETAILED TABLE ====================== -->
        <div class="section-heading">Detailed — one row per Subject &amp; Class</div>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Subject</th>
                    <th>Code</th>
                    <th>Class</th>
                    <th>Level</th>
                    <th>Room</th>
                    <th>Teacher</th>
                    <th>Credits</th>
                    <th>Week Hrs</th>
                    <th>Max Marks</th>
                </tr>
            </thead>
            <tbody>
                <?php
                    $k = 1;
                    $detail_rows = 0;
                    foreach ($subjects as $s):
                        $sid = (int)$s['id'];
                        if (empty($details[$sid]['classes'])) continue;
                        $lv_colors = ['Nursery'=>'pink','Primary'=>'blue','O_Level'=>'green','A_Level'=>'orange'];
                        foreach ($details[$sid]['classes'] as $row):
                            $detail_rows++;
                            $lv = $row['level'];
                            $lv_col = $lv_colors[$lv] ?? 'gray';
                ?>
                    <tr>
                        <td><?= $k++; ?></td>
                        <td><div style="font-weight:600;"><?= htmlspecialchars($s['subject_name']); ?></div></td>
                        <td>
                            <?php if (!empty($s['subject_code'])): ?>
                                <span class="mono small"><?= htmlspecialchars($s['subject_code']); ?></span>
                            <?php else: ?>
                                <span class="small">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($row['class_name']); ?></td>
                        <td><span class="tag <?= $lv_col; ?>"><?= htmlspecialchars($level_map[$lv] ?? $lv); ?></span></td>
                        <td><?= !empty($row['room']) ? htmlspecialchars($row['room']) : '<span class="small">—</span>'; ?></td>
                        <td><?= !empty($row['teacher']) ? htmlspecialchars($row['teacher']) : '<span class="small">Unassigned</span>'; ?></td>
                        <td><?= $row['credits']    !== null ? htmlspecialchars(fmt_num($row['credits']))    : '<span class="small">—</span>'; ?></td>
                        <td><?= $row['week_hours'] !== null ? htmlspecialchars(fmt_num($row['week_hours'])) : '<span class="small">—</span>'; ?></td>
                        <td><?= $row['max_marks']  !== null ? (int)$row['max_marks']                        : '<span class="small">—</span>'; ?></td>
                    </tr>
                <?php
                        endforeach;
                    endforeach;
                    if ($detail_rows === 0):
                ?>
                    <tr>
                        <td colspan="10" style="text-align:center;color:#64748b;padding:20px;">
                            No class assignments yet for the selected subjects.
                        </td>
                    </tr>
                <?php endif; ?>
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