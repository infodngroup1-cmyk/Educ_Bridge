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
$school_admin = $_SESSION['school_admin'] ?? 'Administrator';

/*
|--------------------------------------------------------------------------
| EXPORT TYPE
|--------------------------------------------------------------------------
*/
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
$diploma = $_GET['diploma'] ?? '';
$sex     = $_GET['sex']     ?? '';
$status  = $_GET['status']  ?? '';

$where  = ["school_id = ?"];
$params = [$school_id];
$types  = "i";

if ($q !== '') {
    $where[] = "(name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $like = "%{$q}%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= "sss";
}
if (in_array($level, $valid_level_enums, true)) {
    $where[] = "FIND_IN_SET(?, teaching_level)";
    $params[] = $level; $types .= "s";
} else {
    $level = '';
}
if (in_array($diploma, ['A2','A1','A0','Masters','PhD','Professor'], true)) {
    $where[] = "diploma = ?"; $params[] = $diploma; $types .= "s";
} else {
    $diploma = '';
}
if (in_array($sex, ['Male','Female'], true)) {
    $where[] = "sex = ?"; $params[] = $sex; $types .= "s";
} else {
    $sex = '';
}
if (in_array($status, ['active','inactive'], true)) {
    $where[] = "status = ?"; $params[] = $status; $types .= "s";
} else {
    $status = '';
}

$where_sql = implode(" AND ", $where);

/*
|--------------------------------------------------------------------------
| FETCH ALL (no pagination for export)
|--------------------------------------------------------------------------
*/
$stmt = mysqli_prepare($conn, "SELECT * FROM teachers WHERE $where_sql ORDER BY name ASC");
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$rows = [];
while ($r = mysqli_fetch_assoc($res)) {
    $rows[] = $r;
}
mysqli_stmt_close($stmt);

/*
|--------------------------------------------------------------------------
| HUMAN FILTER SUMMARY
|--------------------------------------------------------------------------
*/
$filter_bits = [];
if ($q !== '')       $filter_bits[] = "Search: \"{$q}\"";
if ($level !== '')   $filter_bits[] = "Level: " . ($level_map[$level] ?? $level);
if ($diploma !== '') $filter_bits[] = "Diploma: {$diploma}";
if ($sex !== '')     $filter_bits[] = "Sex: {$sex}";
if ($status !== '')  $filter_bits[] = "Status: " . ucfirst($status);
$filter_summary = empty($filter_bits) ? 'All teachers (no filters)' : implode(' · ', $filter_bits);

/*
|--------------------------------------------------------------------------
| HELPER: Force Excel to keep numeric strings as TEXT
|--------------------------------------------------------------------------
| Wrapping in ="..." prevents Excel from:
|   • converting to scientific notation (e.g. 1.19987E+15)
|   • truncating leading zeros
|   • losing precision on long numbers
| If the value contains a double quote, we escape it by doubling it.
*/
function excel_text($value) {
    $value = (string)$value;
    if ($value === '') return '';

    // Escape any double quotes inside the value
    $escaped = str_replace('"', '""', $value);

    // Wrap in ="..." so Excel treats it as text
    return '="' . $escaped . '"';
}

/*
|--------------------------------------------------------------------------
| TIMESTAMP
|--------------------------------------------------------------------------
*/
$stamp = date('Y-m-d_His');

/*
|--------------------------------------------------------------------------
| EXCEL (CSV) EXPORT
|--------------------------------------------------------------------------
*/
if ($type === 'excel') {

    $filename = 'teachers_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $school_code ?: 'school') . '_' . $stamp . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');

    // UTF-8 BOM so Excel opens it correctly
    fputs($out, "\xEF\xBB\xBF");

    // Header row
    fputcsv($out, [
        'No.',
        'Name',
        'Email',
        'Phone',
        'National ID',
        'Sex',
        'Diploma',
        'Teaching Level(s)',
        'Bank / Wallet',
        'Account Name',
        'Account Number',
        'Disability',
        'Disability Details',
        'Status',
        'Joined',
        'Last Updated',
    ]);

    $i = 1;
    foreach ($rows as $r) {
        // Teaching levels to readable
        $lvs = array_filter(array_map('trim', explode(',', (string) $r['teaching_level'])));
        $lvs_readable = [];
        foreach ($lvs as $lv) {
            $lvs_readable[] = $level_map[$lv] ?? $lv;
        }

        fputcsv($out, [
            $i++,
            $r['name'],
            $r['email'],
            // Phone as text (protects leading zeros like 078...)
            excel_text($r['phone'] ?? ''),
            // ✅ National ID as text — full digits preserved
            excel_text($r['id_number'] ?? ''),
            $r['sex'],
            $r['diploma'],
            implode(', ', $lvs_readable),
            $r['bank_type']     ?: '',
            $r['account_name']  ?: '',
            // ✅ Account number as text — full digits preserved
            excel_text($r['account_number'] ?? ''),
            ((int)$r['has_disability'] === 1) ? 'Yes' : 'No',
            ((int)$r['has_disability'] === 1) ? $r['disability_details'] : '',
            ucfirst($r['status']),
            $r['created_at'],
            $r['updated_at'],
        ]);
    }

    // Footer info
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
    <title>Teachers Report — <?= htmlspecialchars($school_name); ?></title>
    <link rel="icon" type="image/png" href="logo.png">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            color: #0f172a;
            background: #fff;
            margin: 0;
            padding: 24px 28px;
            font-size: 12px;
        }

        .head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding-bottom: 14px;
            border-bottom: 3px solid #2563eb;
            margin-bottom: 16px;
        }
        .head .brand {
            display: flex; align-items: center; gap: 12px;
        }
        .head .brand img {
            width: 46px; height: 46px; border-radius: 50%;
            object-fit: contain;
            border: 2px solid #e2e8f0;
            background: #fff;
        }
        .head .brand-text h1 {
            margin: 0;
            font-size: 20px;
            color: #0f172a;
            letter-spacing: -0.3px;
        }
        .head .brand-text p {
            margin: 3px 0 0;
            font-size: 12.5px;
            color: #64748b;
        }

        .head .meta {
            text-align: right;
            font-size: 11.5px;
            color: #475569;
            line-height: 1.6;
        }
        .head .meta .school {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 2px;
        }
        .head .meta .code {
            font-family: 'Courier New', monospace;
            color: #2563eb;
            font-weight: 700;
        }

        .filter-bar {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 12px;
            color: #475569;
            margin-bottom: 14px;
        }
        .filter-bar strong { color: #0f172a; }

        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead th {
            background: #f1f5f9;
            text-align: left;
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #64748b;
            padding: 8px 7px;
            border-bottom: 1.5px solid #cbd5e1;
            white-space: nowrap;
        }
        tbody td {
            padding: 7px 7px;
            font-size: 11px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
            color: #0f172a;
        }
        tbody tr:nth-child(even) td { background: #fafbfc; }

        .tag {
            display: inline-block;
            padding: 1.5px 7px;
            border-radius: 50px;
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }
        .tag.blue { background: #dbeafe; color: #2563eb; }
        .tag.green { background: #d1fae5; color: #059669; }
        .tag.red { background: #fee2e2; color: #dc2626; }
        .tag.gray { background: #e5e7eb; color: #475569; }
        .tag.purple { background: #ede9fe; color: #7c3aed; }
        .tag.orange { background: #fef3c7; color: #b45309; }
        .tag.teal { background: #ccfbf1; color: #0d9488; }

        .small { color: #64748b; font-size: 10.5px; }
        .mono { font-family: 'Courier New', monospace; }

        .foot {
            margin-top: 18px;
            padding-top: 12px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: #64748b;
        }

        @page {
            size: A4 landscape;
            margin: 12mm;
        }
        @media print {
            body { padding: 0; }
            .no-print { display: none !important; }
        }

        .no-print {
            text-align: center;
            margin-bottom: 16px;
        }
        .no-print button {
            padding: 10px 20px;
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
        }
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
                <h1>Educ Bridge — Teachers Report</h1>
                <p><?= htmlspecialchars($filter_summary); ?></p>
            </div>
        </div>
        <div class="meta">
            <div class="school"><?= htmlspecialchars($school_name); ?></div>
            <div class="code"><?= htmlspecialchars($school_code); ?></div>
            <div>Generated: <?= date('M j, Y · H:i'); ?></div>
            <div>Total teachers: <strong><?= count($rows); ?></strong></div>
        </div>
    </div>

    <?php if (empty($rows)): ?>
        <div class="filter-bar" style="text-align:center;">
            No teachers matched the current filters.
        </div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Contact</th>
                    <th>National ID</th>
                    <th>Sex</th>
                    <th>Diploma</th>
                    <th>Level(s)</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Last Updated</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($rows as $t): ?>
                    <?php
                        $t_levels = array_filter(array_map('trim', explode(',', (string) $t['teaching_level'])));
                        $diploma_class = [
                            'A2' => 'blue', 'A1' => 'blue', 'A0' => 'purple',
                            'Masters' => 'orange', 'PhD' => 'red', 'Professor' => 'red',
                        ][$t['diploma']] ?? 'gray';
                    ?>
                    <tr>
                        <td><?= $i++; ?></td>
                        <td>
                            <div style="font-weight:600;"><?= htmlspecialchars($t['name']); ?></div>
                            <?php if ((int)$t['has_disability'] === 1): ?>
                                <div class="small">♿ Has disability</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?= htmlspecialchars($t['email']); ?></div>
                            <div class="small"><?= htmlspecialchars($t['phone']); ?></div>
                        </td>
                        <td class="mono" style="font-size:10.5px;">
                            <?= !empty($t['id_number']) ? htmlspecialchars($t['id_number']) : '<span class="small">—</span>'; ?>
                        </td>
                        <td><?= htmlspecialchars($t['sex']); ?></td>
                        <td><span class="tag <?= $diploma_class; ?>"><?= htmlspecialchars($t['diploma']); ?></span></td>
                        <td>
                            <?php if (!empty($t_levels)): ?>
                                <?php foreach ($t_levels as $lv): ?>
                                    <span class="tag teal"><?= htmlspecialchars($level_map[$lv] ?? $lv); ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="small">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($t['bank_type']) || !empty($t['account_number'])): ?>
                                <?php if (!empty($t['bank_type'])): ?>
                                    <div style="font-weight:600;color:#2563eb;font-size:10.5px;"><?= htmlspecialchars($t['bank_type']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($t['account_name'])): ?>
                                    <div style="font-weight:600;"><?= htmlspecialchars($t['account_name']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($t['account_number'])): ?>
                                    <div class="small mono"><?= htmlspecialchars($t['account_number']); ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="small">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($t['status'] === 'active'): ?>
                                <span class="tag green">Active</span>
                            <?php else: ?>
                                <span class="tag red">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?= !empty($t['created_at']) ? date('M j, Y', strtotime($t['created_at'])) : '—'; ?>
                        </td>
                        <td class="small">
                            <?= !empty($t['updated_at']) ? date('M j, Y', strtotime($t['updated_at'])) : '—'; ?>
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