<?php
require_once 'db.php';

/*
|--------------------------------------------------------------------------
| PUBLIC PAGE — no auth required
|--------------------------------------------------------------------------
*/

/* ---------- helpers ---------- */
function col_exists($conn, $table, $col) {
    $t = mysqli_real_escape_string($conn, $table);
    $c = mysqli_real_escape_string($conn, $col);
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    return ($r && mysqli_num_rows($r) > 0);
}
function table_exists($conn, $table) {
    $t = mysqli_real_escape_string($conn, $table);
    $r = @mysqli_query($conn, "SHOW TABLES LIKE '{$t}'");
    return ($r && mysqli_num_rows($r) > 0);
}

$school_code = strtoupper(trim($_GET['school'] ?? ''));
$school      = null;
$config      = null;
$message     = "";
$message_type = "";

$can_apply    = false;
$can_register = false;
$apply_reason = '';
$register_reason = '';
$levels_list  = [];
$effective_status = 'closed';

/* ---------------------------------------------------------------------
 | 1. VERIFY SCHOOL CODE
 | --------------------------------------------------------------------- */
if ($school_code !== '') {
    $name_col = 'name';
    foreach (['name','school_name','title','school'] as $cand) {
        if (col_exists($conn, 'schools', $cand)) { $name_col = $cand; break; }
    }
    $has_status = col_exists($conn, 'schools', 'status');
    $has_admin  = col_exists($conn, 'schools', 'admin_name');

    if (!col_exists($conn, 'schools', 'school_code')) {
        $message = "This portal isn't set up for online registration yet.";
        $message_type = "error";
    } else {
        $select  = "SELECT id, `{$name_col}` AS name, school_code";
        $select .= $has_admin  ? ", admin_name" : ", '' AS admin_name";
        $select .= $has_status ? ", status"     : ", 'active' AS status";
        $select .= " FROM schools WHERE school_code = ? LIMIT 1";

        $stmt = mysqli_prepare($conn, $select);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $school_code);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if (mysqli_num_rows($res) === 0) {
                $message = "We couldn't find a school with that code. Please check with your school.";
                $message_type = "error";
            } else {
                $row = mysqli_fetch_assoc($res);
                if (strtolower((string)$row['status']) !== 'active') {
                    $message = "This school is currently unavailable for online services.";
                    $message_type = "error";
                } else {
                    $school = $row;
                    if (table_exists($conn, 'online_registration_config')) {
                        $csql = mysqli_prepare($conn, "SELECT * FROM online_registration_config WHERE school_id = ? LIMIT 1");
                        if ($csql) {
                            mysqli_stmt_bind_param($csql, "i", $school['id']);
                            mysqli_stmt_execute($csql);
                            $cres = mysqli_stmt_get_result($csql);
                            $config = mysqli_fetch_assoc($cres);
                            mysqli_stmt_close($csql);
                        }
                    }
                }
            }
            mysqli_stmt_close($stmt);
        } else {
            $message = "Database error: " . mysqli_error($conn);
            $message_type = "error";
        }
    }
}

/* ---------------------------------------------------------------------
 | 2. DECIDE WHICH TILE IS OPEN
 | --------------------------------------------------------------------- */
if ($school) {
    if (!$config) {
        $apply_reason    = "This school hasn't set up online registration yet.";
        $register_reason = $apply_reason;
        $effective_status = 'closed';
    } else {
        $mode = $config['mode'] ?? 'closed';
        $now  = time();

        $registration_open = false;
        if ($mode === 'open') {
            $registration_open = true;
            $effective_status  = 'open';
        } elseif ($mode === 'scheduled') {
            $o = !empty($config['opens_at'])  ? strtotime($config['opens_at'])  : null;
            $c = !empty($config['closes_at']) ? strtotime($config['closes_at']) : null;
            if ($o && $c) {
                if ($now < $o) {
                    $effective_status = 'scheduled_future';
                    $register_reason  = "Registration opens on " . date('M j, Y · H:i', $o) . ".";
                } elseif ($now > $c) {
                    $effective_status = 'scheduled_past';
                    $register_reason  = "Registration closed on " . date('M j, Y · H:i', $c) . ".";
                } else {
                    $registration_open = true;
                    $effective_status  = 'scheduled_open';
                }
            } else {
                $register_reason = "The registration window is not fully scheduled yet.";
            }
        } else {
            $register_reason = "This school is not accepting online registrations at the moment.";
        }

        if ($registration_open) {
            $can_register = true;
        } elseif ($register_reason === '') {
            $register_reason = "Online registration is closed for this school.";
        }

        $allow_index_reg = (int)($config['allow_index_reg'] ?? 0);
        if ($allow_index_reg && $registration_open) {
            $can_apply = true;
        } else {
            $apply_reason = !$allow_index_reg
                ? "The school has not enabled index-number registration."
                : $register_reason;
        }

        $levels_list = array_filter(array_map('trim', explode(',', (string)($config['levels'] ?? ''))));
    }
}

$level_map = ['Nursery'=>'Nursery','Primary'=>'Primary','O_Level'=>"O' Level",'A_Level'=>"A' Level"];
$status_map = [
    'open'             => ['Open',        'green'],
    'closed'           => ['Closed',      'red'],
    'scheduled_future' => ['Opens soon',  'orange'],
    'scheduled_open'   => ['Open',        'green'],
    'scheduled_past'   => ['Closed',      'red'],
];
$status_label = $status_map[$effective_status] ?? ['Closed','red'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Registration | Educ Bridge</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary:#2563eb; --primary-dark:#1d4ed8; --primary-light:#dbeafe;
            --success:#10b981; --warning:#f59e0b; --danger:#ef4444;
            --bg-body:#f1f5f9; --bg-card:#ffffff; --bg-alt:#f8fafc;
            --text-primary:#0f172a; --text-secondary:#475569; --text-muted:#64748b;
            --border-color:#e2e8f0;
            --shadow-lg:0 20px 45px rgba(0,0,0,0.08);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        [data-theme="dark"] {
            --primary:#3b82f6; --primary-dark:#2563eb; --primary-light:#1e3a5f;
            --success:#34d399; --warning:#fbbf24; --danger:#f87171;
            --bg-body:#0b1120; --bg-card:#1e293b; --bg-alt:#1a2332;
            --text-primary:#f1f5f9; --text-secondary:#cbd5e1; --text-muted:#94a3b8;
            --border-color:#334155;
            --shadow-lg:0 20px 45px rgba(0,0,0,0.5);
        }
        *,*::before,*::after { margin:0; padding:0; box-sizing:border-box; }
        html, body { min-height:100vh; }
        body { font-family:'Inter',sans-serif; background:var(--bg-body); color:var(--text-primary); display:flex; align-items:center; justify-content:center; padding:24px 16px; position:relative; }
        body::before { content:''; position:fixed; inset:0; background: radial-gradient(circle at 10% 10%, rgba(37,99,235,0.10), transparent 45%), radial-gradient(circle at 90% 90%, rgba(124,58,237,0.10), transparent 45%); pointer-events:none; z-index:0; }
        .theme-toggle { position:fixed; top:20px; right:20px; width:42px; height:42px; border-radius:50%; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; z-index:20; }
        .theme-toggle:hover { color:var(--primary); border-color:var(--primary); }

        .wrap { width:100%; max-width:720px; position:relative; z-index:1; }

        /* ---------- Top-of-page navigation row ---------- */
        .topnav {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:16px;
            flex-wrap:wrap;
        }
        .topnav a {
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:9px 14px;
            border-radius:10px;
            background: var(--bg-card);
            border: 1.5px solid var(--border-color);
            color: var(--text-secondary);
            text-decoration:none;
            font-size: 13px;
            font-weight: 600;
            transition: var(--transition);
        }
        .topnav a:hover { border-color: var(--primary); color: var(--primary); }

        .brand { display:flex; align-items:center; justify-content:center; gap:12px; margin-bottom:22px; }
        .brand img { width:48px; height:48px; border-radius:50%; background:#fff; padding:3px; border:2px solid var(--border-color); }
        .brand-text { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; letter-spacing:-0.4px; }
        .brand-text span { color: var(--primary); }

        .card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:20px; box-shadow:var(--shadow-lg); overflow:hidden; }
        .card-head { padding:30px 30px 22px; border-bottom:1px solid var(--border-color); background:linear-gradient(135deg, rgba(37,99,235,0.07), rgba(124,58,237,0.05)); text-align:center; }
        .card-head h1 { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; margin-bottom:8px; }
        .card-head p { font-size:14px; color:var(--text-muted); line-height:1.55; }
        .card-body { padding:28px 30px 30px; }

        /* School identity box */
        .school-identity {
            display:flex; align-items:center; gap:16px;
            padding: 18px 20px;
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            margin-bottom: 20px;
        }
        .school-identity .si-avatar {
            width: 60px; height: 60px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--primary), #7c3aed);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Poppins', sans-serif;
            font-size: 24px; font-weight: 700;
            flex-shrink: 0;
        }
        .school-identity .si-body { flex:1; min-width:0; }
        .school-identity .si-name {
            font-family: 'Poppins', sans-serif;
            font-size: 17px; font-weight: 700;
            color: var(--text-primary);
            line-height: 1.3;
            margin-bottom: 4px;
        }
        .school-identity .si-meta {
            font-size: 12.5px; color: var(--text-muted);
            display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
        }
        .school-identity .si-meta .tag {
            display: inline-flex; align-items: center; gap: 5px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            padding: 3px 9px;
            border-radius: 50px;
            font-weight: 600;
        }
        .school-identity .si-meta .tag i { font-size: 11px; }

        .status-pill {
            display:inline-flex; align-items:center; gap:8px;
            padding: 6px 14px; border-radius: 50px;
            font-size: 12.5px; font-weight: 700;
            margin-top: 12px;
        }
        .status-pill .dot { width: 8px; height: 8px; border-radius: 50%; }
        .status-pill.green { background: rgba(16,185,129,0.14); color:#059669; }
        .status-pill.green .dot { background: var(--success); }
        [data-theme="dark"] .status-pill.green { color:#34d399; }
        .status-pill.red { background: rgba(239,68,68,0.12); color:#dc2626; }
        .status-pill.red .dot { background: var(--danger); }
        [data-theme="dark"] .status-pill.red { color:#f87171; }
        .status-pill.orange { background: rgba(245,158,11,0.14); color:#b45309; }
        .status-pill.orange .dot { background: var(--warning); }
        [data-theme="dark"] .status-pill.orange { color:#fbbf24; }

        .alert { padding:13px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; display:flex; align-items:flex-start; gap:10px; line-height:1.55; }
        .alert-error { background: rgba(239,68,68,0.08); color:#dc2626; border:1px solid rgba(239,68,68,0.25); }
        .alert-info  { background: rgba(37,99,235,0.08); color:#1d4ed8; border:1px solid rgba(37,99,235,0.25); }
        .alert-warn  { background: rgba(245,158,11,0.10); color:#b45309; border:1px solid rgba(245,158,11,0.28); }
        [data-theme="dark"] .alert-error { color:#f87171; }
        [data-theme="dark"] .alert-info  { color:#93c5fd; }
        [data-theme="dark"] .alert-warn  { color:#fbbf24; }

        .note-box {
            background: var(--bg-alt);
            border: 1px dashed var(--border-color);
            border-radius: 12px;
            padding: 14px 16px;
            font-size: 13.5px;
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 20px;
        }
        .note-box i { color: var(--warning); margin-right: 6px; }

        /* Code entry form */
        .lookup-form { display:flex; flex-direction:column; gap:12px; }
        .lookup-form label { font-size:12.5px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; }
        .lookup-row { display:flex; gap:8px; }
        .lookup-row input {
            flex:1;
            padding: 14px 16px;
            border:1.5px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-alt);
            color: var(--text-primary);
            font-family:inherit; font-size:16px;
            text-transform: uppercase;
            letter-spacing: 1.1px;
            font-weight: 700;
            text-align: center;
            outline: none;
            transition: var(--transition);
        }
        .lookup-row input:focus { border-color:var(--primary); background: var(--bg-card); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        .lookup-row button {
            padding: 14px 24px;
            border-radius: 10px;
            border: none;
            background: var(--primary);
            color: #fff;
            font-family: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex; align-items: center; gap: 8px;
            transition: var(--transition);
        }
        .lookup-row button:hover { background: var(--primary-dark); }
        .lookup-hint { font-size: 12px; color: var(--text-muted); margin-top: 4px; }

        /* Action tiles */
        .option-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 560px) { .option-grid { grid-template-columns: 1fr; } }

        .option-tile {
            display: block;
            padding: 24px 22px;
            background: var(--bg-alt);
            border: 1.5px solid var(--border-color);
            border-radius: 16px;
            text-decoration: none;
            color: var(--text-primary);
            transition: var(--transition);
            position: relative;
            text-align: center;
        }
        .option-tile:hover:not(.disabled) {
            border-color: var(--primary);
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        .option-tile .ot-icon {
            width: 64px; height: 64px;
            border-radius: 18px;
            background: linear-gradient(135deg, var(--primary), #7c3aed);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 26px;
            margin: 0 auto 14px;
        }
        .option-tile .ot-title {
            font-family:'Poppins',sans-serif;
            font-size: 16px; font-weight: 700;
            margin-bottom: 6px;
        }
        .option-tile .ot-desc {
            font-size: 12.5px;
            color: var(--text-muted);
            line-height: 1.55;
        }
        .option-tile.disabled { opacity: 0.55; pointer-events: none; cursor: not-allowed; }
        .option-tile .closed-tag {
            position: absolute; top: 12px; right: 12px;
            background: rgba(239,68,68,0.14); color: #dc2626;
            font-size: 10.5px; font-weight: 700;
            padding: 3px 9px; border-radius: 50px;
        }
        [data-theme="dark"] .option-tile .closed-tag { color:#f87171; }

        /* Back / change-code bar (school-found view) */
        .action-bar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 22px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }
        .action-bar a {
            flex: 1 1 200px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 16px;
            border-radius: 10px;
            background: var(--bg-alt);
            border: 1.5px solid var(--border-color);
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 600;
            transition: var(--transition);
            text-align: center;
        }
        .action-bar a:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--bg-card);
        }
        .action-bar a.primary-action {
            background: var(--primary-light);
            border-color: transparent;
            color: var(--primary);
            font-weight: 700;
        }
        .action-bar a.primary-action:hover {
            background: var(--primary);
            color: #fff;
        }

        .foot { text-align:center; margin-top:20px; font-size:13px; color:var(--text-muted); }
        .foot a { color:var(--primary); font-weight:600; text-decoration:none; }
    </style>
</head>
<body>

<button class="theme-toggle" id="themeToggle" aria-label="Toggle theme"><i class="fas fa-moon"></i></button>

<div class="wrap">

    <!-- ============ TOP NAV (always visible) ============ -->
    <div class="topnav">
        <a href="index.php">
            <i class="fas fa-arrow-left"></i> Home
        </a>
        <?php if ($school): ?>
            <a href="online-registration.php">
                <i class="fas fa-rotate-left"></i> 
            </a>
        <?php endif; ?>
    </div>

    <div class="brand">
        <img src="logo.png" alt="Educ Bridge">
        <div class="brand-text">Educ<span>Bridge</span></div>
    </div>

    <div class="card">
        <div class="card-head">
            <h1>Online Registration</h1>
            <p><?= $school ? 'Choose how you\'d like to continue.' : 'Enter your school code to get started. Your school provides the code, or you can scan their QR code.'; ?></p>
            <?php if ($school): ?>
                <div class="status-pill <?= htmlspecialchars($status_label[1]); ?>">
                    <span class="dot"></span> <?= htmlspecialchars($status_label[0]); ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card-body">

            <?php if ($message !== ""): ?>
                <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                    <i class="fas <?= $message_type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($school): ?>

                <!-- ============ SCHOOL IDENTITY ============ -->
                <?php
                    $school_initial = "";
                    foreach (explode(" ", trim($school['name'])) as $p) {
                        if ($p !== "") { $school_initial .= strtoupper($p[0]); if (strlen($school_initial) >= 2) break; }
                    }
                    if ($school_initial === "") $school_initial = "S";
                ?>
                <div class="school-identity">
                    <div class="si-avatar"><?= htmlspecialchars($school_initial); ?></div>
                    <div class="si-body">
                        <div class="si-name"><?= htmlspecialchars($school['name']); ?></div>
                        <div class="si-meta">
                            <?php if (!empty($school['admin_name'])): ?>
                                <span class="tag">
                                    <i class="fas fa-user-tie"></i>
                                    <?= htmlspecialchars($school['admin_name']); ?>
                                </span>
                            <?php endif; ?>
                            <span class="tag">
                                <i class="fas fa-hashtag"></i>
                                <?= htmlspecialchars($school['school_code']); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <?php if (!empty($config['note'])): ?>
                    <div class="note-box">
                        <i class="fas fa-info-circle"></i>
                        <?= nl2br(htmlspecialchars($config['note'])); ?>
                    </div>
                <?php endif; ?>

                <?php if (!$can_apply && !$can_register): ?>
                    <div class="alert alert-warn">
                        <i class="fas fa-clock" style="margin-top:2px;"></i>
                        <span>
                            <?= htmlspecialchars($register_reason ?: 'This school is not accepting online applications or registrations at the moment.'); ?>
                        </span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($levels_list)): ?>
                    <div class="note-box" style="margin-top:0;">
                        <i class="fas fa-graduation-cap" style="color:var(--primary);"></i>
                        <strong>Levels open:</strong>
                        <?= htmlspecialchars(implode(', ', array_map(fn($l) => $level_map[$l] ?? $l, $levels_list))); ?>
                    </div>
                <?php endif; ?>

                <!-- ============ TWO TILES ============ -->
                <div class="option-grid">

                    <a href="online-school-application.php?school=<?= urlencode($school['school_code']); ?>"
                       class="option-tile <?= $can_apply ? '' : 'disabled'; ?>"
                       <?= $can_apply ? '' : 'aria-disabled="true" tabindex="-1" title="' . htmlspecialchars($apply_reason) . '"'; ?>>
                        <?php if (!$can_apply): ?><span class="closed-tag">Closed</span><?php endif; ?>
                        <div class="ot-icon"><i class="fas fa-user-plus"></i></div>
                        <div class="ot-title">Apply for School</div>
                        <div class="ot-desc">
                            For students coming from another school, or those who already have an index number.
                        </div>
                    </a>

                    <a href="online-school-registration.php?school=<?= urlencode($school['school_code']); ?>"
                       class="option-tile <?= $can_register ? '' : 'disabled'; ?>"
                       <?= $can_register ? '' : 'aria-disabled="true" tabindex="-1" title="' . htmlspecialchars($register_reason) . '"'; ?>>
                        <?php if (!$can_register): ?><span class="closed-tag">Closed</span><?php endif; ?>
                        <div class="ot-icon" style="background:linear-gradient(135deg,#10b981,#14b8a6);"><i class="fas fa-id-card"></i></div>
                        <div class="ot-title">Online Registration</div>
                        <div class="ot-desc">
                            Fill in the student's details and complete their registration for this school.
                        </div>
                    </a>

                </div>

                <!-- ============ ACTION BAR (back / change code) ============ -->
                <div class="action-bar">
                    <a href="online-registration.php" class="primary-action">
                        <i class="fas fa-rotate-left"></i> Enter a different code
                    </a>
                    <a href="index.php">
                        <i class="fas fa-home"></i> Back to Home
                    </a>
                </div>

            <?php else: ?>

                <!-- ============ CODE ENTRY ============ -->
                <form class="lookup-form" method="GET" action="online-registration.php" autocomplete="off">
                    <label for="schoolCode">School Code</label>
                    <div class="lookup-row">
                        <input type="text" id="schoolCode" name="school"
                               placeholder="e.g. 5092022026"
                               maxlength="40" required autofocus>
                        <button type="submit"><i class="fas fa-arrow-right"></i> Verify</button>
                    </div>
                    <div class="lookup-hint">
                        <i class="fas fa-info-circle"></i>
                        The code is case-insensitive. Ask your school if you don't have it.
                    </div>
                </form>

                <!-- Back to home below the form -->
             
<div style="margin-top:20px;text-align:center;">
    <a href="student-login.php"
       style="display:inline-flex;
              align-items:center;
              justify-content:center;
              gap:8px;
              padding:12px 28px;
              border-radius:10px;
              background:var(--primary-color);
              border:1.5px solid var(--primary-color);
              color:#fff;
              text-decoration:none;
              font-size:14px;
              font-weight:600;
              transition:all 0.2s ease;">
        <i class="fas fa-sign-in-alt"></i> Login
    </a>
</div>


            <?php endif; ?>

        </div>
    </div>

    <div class="foot">Powered by <a href="#">Educ Bridge</a></div>

</div>

<script>
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
            apply(html.getAttribute('data-theme') === 'light' ? 'dark' : 'light');
        });
    })();
</script>

</body>
</html>