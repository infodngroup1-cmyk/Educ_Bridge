<?php
session_start();
require_once 'db.php';

/* Already logged in → go to dashboard */
if (!empty($_SESSION['parent_verified']) && !empty($_SESSION['parent_student_id'])) {
    header("Location: parent-dashboard.php");
    exit;
}

function col_exists($conn, $table, $col) {
    $t = mysqli_real_escape_string($conn, $table);
    $c = mysqli_real_escape_string($conn, $col);
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    return ($r && mysqli_num_rows($r) > 0);
}

$errors = [];
$prefilled_code  = strtoupper(trim($_GET['code'] ?? ''));
$prefilled_phone = trim($_GET['phone'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code  = strtoupper(trim($_POST['student_code'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');

    if ($code === '' || $phone === '') {
        $errors[] = "Please enter both the student code and your phone number.";
    } else {
        /* Normalise phone: strip non-digits for comparison */
        $phone_digits = preg_replace('/\D/', '', $phone);

        $stmt = mysqli_prepare($conn, "
            SELECT id, student_code, first_name, last_name, school_id, status,
                   father_name, father_phone, father_email,
                   mother_name, mother_phone, mother_email,
                   guardian_name, guardian_phone, guardian_email
            FROM students
            WHERE student_code = ?
            LIMIT 1
        ");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $code);
            mysqli_stmt_execute($stmt);
            $r = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($r)) {

                /* Check the phone against father, mother, guardian */
                $matches = [];
                foreach ([
                    'father'   => ['name' => $row['father_name'],   'phone' => $row['father_phone'],   'email' => $row['father_email']],
                    'mother'   => ['name' => $row['mother_name'],   'phone' => $row['mother_phone'],   'email' => $row['mother_email']],
                    'guardian' => ['name' => $row['guardian_name'], 'phone' => $row['guardian_phone'], 'email' => $row['guardian_email']],
                ] as $role => $info) {
                    if (empty($info['phone'])) continue;
                    $stored_digits = preg_replace('/\D/', '', $info['phone']);
                    if ($stored_digits !== '' && $stored_digits === $phone_digits) {
                        $matches[] = $role;
                    }
                }

                if (empty($matches)) {
                    $errors[] = "The phone number doesn't match any parent or guardian on this student's record.";
                } else {
                    /* Log them in */
                    $_SESSION['parent_verified']    = true;
                    $_SESSION['parent_student_id']  = (int)$row['id'];
                    $_SESSION['parent_student_code']= $row['student_code'];
                    $_SESSION['parent_student_name']= $row['first_name'] . ' ' . $row['last_name'];
                    $_SESSION['parent_school_id']   = (int)$row['school_id'];
                    $_SESSION['parent_roles']       = $matches; // e.g. ['father'] or ['mother','guardian']

                    header("Location: parent-dashboard.php");
                    exit;
                }
            } else {
                $errors[] = "No student found with that code.";
            }
            mysqli_stmt_close($stmt);
        } else {
            $errors[] = "Database error: " . mysqli_error($conn);
        }
    }

    if (!empty($errors)) {
        $prefilled_code  = $code;
        $prefilled_phone = $phone;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent Login | Educ Bridge</title>
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
        body::before { content:''; position:fixed; inset:0; background: radial-gradient(circle at 10% 10%, rgba(37,99,235,0.10), transparent 45%), radial-gradient(circle at 90% 90%, rgba(16,185,129,0.10), transparent 45%); pointer-events:none; z-index:0; }
        .theme-toggle { position:fixed; top:20px; right:20px; width:42px; height:42px; border-radius:50%; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; z-index:20; }

        .wrap { width:100%; max-width:520px; position:relative; z-index:1; }
        .brand { display:flex; align-items:center; justify-content:center; gap:12px; margin-bottom:22px; }
        .brand img { width:48px; height:48px; border-radius:50%; background:#fff; padding:3px; border:2px solid var(--border-color); }
        .brand-text { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; }
        .brand-text span { color: var(--primary); }

        .card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:20px; box-shadow:var(--shadow-lg); overflow:hidden; }
        .card-head { padding:30px 30px 22px; text-align:center; border-bottom:1px solid var(--border-color); background:linear-gradient(135deg, rgba(16,185,129,0.07), rgba(37,99,235,0.05)); }
        .card-head .icon {
            width: 68px; height: 68px;
            border-radius: 18px;
            background: linear-gradient(135deg, #10b981, #14b8a6);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; margin: 0 auto 14px;
            box-shadow: 0 12px 30px rgba(16,185,129,0.35);
        }
        .card-head h1 { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; margin-bottom:6px; }
        .card-head p { font-size:13.5px; color:var(--text-muted); line-height:1.55; }
        .card-body { padding:26px 30px 30px; }

        .alert { padding:13px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; display:flex; align-items:flex-start; gap:10px; line-height:1.55; }
        .alert-error { background: rgba(239,68,68,0.08); color:#dc2626; border:1px solid rgba(239,68,68,0.25); }
        [data-theme="dark"] .alert-error { color:#f87171; }
        .alert ul { margin-left:16px; }

        .form-group { margin-bottom: 16px; }
        .form-group label { display:block; font-size:12.5px; font-weight:700; color:var(--text-secondary); margin-bottom:7px; text-transform:uppercase; letter-spacing:0.5px; }
        .form-group input {
            width: 100%; padding: 13px 15px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-alt);
            color: var(--text-primary);
            font-family: inherit; font-size: 15px;
            outline: none; transition: var(--transition);
        }
        .form-group input:focus { border-color: var(--primary); background: var(--bg-card); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        .form-group input.code {
            text-align: center;
            font-family: 'Courier New', monospace;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 3px;
            text-transform: uppercase;
        }
        .form-group .hint { font-size: 11.5px; color: var(--text-muted); margin-top: 5px; }

        .btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; width: 100%; padding: 13px 22px; border-radius:10px; font-family:inherit; font-size:14.5px; font-weight:700; border:1.5px solid transparent; cursor:pointer; transition:var(--transition); text-decoration:none; }
        .btn.primary { background: var(--primary); color:#fff; box-shadow:0 6px 18px rgba(37,99,235,0.32); }
        .btn.primary:hover { background: var(--primary-dark); }

        .foot { text-align:center; margin-top:20px; font-size:13px; color:var(--text-muted); }
        .foot a { color:var(--primary); font-weight:600; text-decoration:none; }
    </style>
</head>
<body>
<button class="theme-toggle" id="themeToggle"><i class="fas fa-moon"></i></button>

<div class="wrap">
    <div class="brand">
        <img src="logo.png" alt="Educ Bridge">
        <div class="brand-text">Educ<span>Bridge</span></div>
    </div>

    <div class="card">
        <div class="card-head">
            <div class="icon"><i class="fas fa-user-friends"></i></div>
            <h1>Parent / Guardian Login</h1>
            <p>Enter your student's code and your phone number to access their dashboard.</p>
        </div>
        <div class="card-body">

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle" style="margin-top:2px;"></i>
                    <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e); ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="parent-login.php" autocomplete="off">
                <div class="form-group">
                    <label>Student Code</label>
                    <input type="text" name="student_code" class="code"
                           placeholder="5012342026"
                           maxlength="20" required autofocus
                           value="<?= htmlspecialchars($prefilled_code); ?>">
                    <div class="hint">The 10-digit code your student received when registering.</div>
                </div>

                <div class="form-group">
                    <label>Your Phone Number</label>
                    <input type="tel" name="phone"
                           placeholder="+250 7XX XXX XXX"
                           maxlength="40" required
                           value="<?= htmlspecialchars($prefilled_phone); ?>">
                    <div class="hint">Use the same phone you put on the registration form (father, mother, or guardian).</div>
                </div>

                <button type="submit" class="btn primary">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>
            </form>

            <div style="text-align:center;margin-top:20px;">
                <a href="online-registration.php" style="font-size:13px;color:var(--text-muted);text-decoration:none;">
                    <i class="fas fa-arrow-left"></i> Back to Registration
                </a>
            </div>

        </div>
    </div>

    <div class="foot">Powered by Educ Bridge</div>
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