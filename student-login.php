<?php
session_start();
require_once 'db.php';

/* If already logged in, go straight to the dashboard */
if (!empty($_SESSION['student_id']) && !empty($_SESSION['student_verified'])) {
    header("Location: student-dashboard.php");
    exit;
}

/* ---------- helpers ---------- */
function col_exists($conn, $table, $col) {
    $t = mysqli_real_escape_string($conn, $table);
    $c = mysqli_real_escape_string($conn, $col);
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    return ($r && mysqli_num_rows($r) > 0);
}

/* Make sure the new auth columns exist */
if (col_exists($conn, 'students', 'id')) {
    if (!col_exists($conn, 'students', 'password')) @mysqli_query($conn, "ALTER TABLE students ADD COLUMN password VARCHAR(255) DEFAULT NULL AFTER student_code");
    if (!col_exists($conn, 'students', 'avatar'))   @mysqli_query($conn, "ALTER TABLE students ADD COLUMN avatar VARCHAR(255) DEFAULT NULL AFTER password");
    if (!col_exists($conn, 'students', 'last_login')) @mysqli_query($conn, "ALTER TABLE students ADD COLUMN last_login DATETIME DEFAULT NULL AFTER avatar");
}

$step        = 'code';        // code | set-password | enter-password
$errors      = [];
$info        = '';
$student     = null;
$prefilled   = trim($_GET['code'] ?? '');

/* If setup=1 with a code, jump to password-setup step */
if (isset($_GET['setup']) && $prefilled !== '') {
    $step = 'set-password';
}

/* ---------------------------------------------------------------------
 | POST: handle code / set password / login
 | --------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* Step 1: verify the code */
    if ($action === 'check_code') {
        $code = strtoupper(trim($_POST['student_code'] ?? ''));
        if ($code === '') {
            $errors[] = "Please enter your student code.";
        } else {
            $stmt = mysqli_prepare($conn, "
                SELECT id, student_code, first_name, last_name, password, school_id, status
                FROM students
                WHERE student_code = ?
                LIMIT 1
            ");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "s", $code);
                mysqli_stmt_execute($stmt);
                $r = mysqli_stmt_get_result($stmt);
                if ($row = mysqli_fetch_assoc($r)) {
                    $student = $row;
                    $prefilled = $row['student_code'];
                    $step = empty($row['password']) ? 'set-password' : 'enter-password';
                } else {
                    $errors[] = "No student found with that code.";
                }
                mysqli_stmt_close($stmt);
            }
        }
    }

    /* Step 2: set password (first time) */
    if ($action === 'set_password') {
        $code  = strtoupper(trim($_POST['student_code'] ?? ''));
        $p1    = $_POST['password']  ?? '';
        $p2    = $_POST['password2'] ?? '';

        if (strlen($p1) < 6)          $errors[] = "Password must be at least 6 characters.";
        if ($p1 !== $p2)              $errors[] = "Passwords do not match.";
        if ($code === '')             $errors[] = "Student code missing.";

        if (empty($errors)) {
            $stmt = mysqli_prepare($conn, "SELECT id, school_id, first_name, last_name, status FROM students WHERE student_code = ? LIMIT 1");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "s", $code);
                mysqli_stmt_execute($stmt);
                $r = mysqli_stmt_get_result($stmt);
                if ($row = mysqli_fetch_assoc($r)) {
                    $hash = password_hash($p1, PASSWORD_DEFAULT);
                    $upd = mysqli_prepare($conn, "UPDATE students SET password = ?, last_login = NOW() WHERE id = ?");
                    if ($upd) {
                        mysqli_stmt_bind_param($upd, "si", $hash, $row['id']);
                        mysqli_stmt_execute($upd);
                        mysqli_stmt_close($upd);

                        /* Log them in */
                        $_SESSION['student_id']       = (int)$row['id'];
                        $_SESSION['student_code']     = $code;
                        $_SESSION['student_name']     = $row['first_name'] . ' ' . $row['last_name'];
                        $_SESSION['student_school_id']= (int)$row['school_id'];
                        $_SESSION['student_verified'] = true;

                        header("Location: student-dashboard.php?welcome=1");
                        exit;
                    }
                } else {
                    $errors[] = "Student not found.";
                }
                mysqli_stmt_close($stmt);
            }
        }
        if (!empty($errors)) { $step = 'set-password'; $prefilled = $code; }
    }

    /* Step 3: login */
    if ($action === 'login') {
        $code = strtoupper(trim($_POST['student_code'] ?? ''));
        $pass = $_POST['password'] ?? '';

        if ($code === '' || $pass === '') {
            $errors[] = "Enter your code and password.";
        } else {
            $stmt = mysqli_prepare($conn, "
                SELECT id, student_code, first_name, last_name, password, school_id, status
                FROM students
                WHERE student_code = ?
                LIMIT 1
            ");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "s", $code);
                mysqli_stmt_execute($stmt);
                $r = mysqli_stmt_get_result($stmt);
                if ($row = mysqli_fetch_assoc($r)) {
                    if (empty($row['password'])) {
                        $errors[] = "You haven't set a password yet.";
                        $step = 'set-password';
                        $prefilled = $code;
                    } elseif (!password_verify($pass, $row['password'])) {
                        $errors[] = "Incorrect password.";
                        $step = 'enter-password';
                        $prefilled = $code;
                    } else {
                        /* Update last_login */
                        @mysqli_query($conn, "UPDATE students SET last_login = NOW() WHERE id = " . (int)$row['id']);

                        $_SESSION['student_id']        = (int)$row['id'];
                        $_SESSION['student_code']      = $row['student_code'];
                        $_SESSION['student_name']      = $row['first_name'] . ' ' . $row['last_name'];
                        $_SESSION['student_school_id'] = (int)$row['school_id'];
                        $_SESSION['student_verified']  = true;

                        header("Location: student-dashboard.php");
                        exit;
                    }
                } else {
                    $errors[] = "No student found with that code.";
                }
                mysqli_stmt_close($stmt);
            }
        }
        if (!empty($errors) && $step === 'code') $step = 'enter-password';
    }
}

/* If we got a code in the URL, pre-check it and go to right step */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $prefilled !== '' && $step !== 'set-password') {
    $stmt = mysqli_prepare($conn, "SELECT id, student_code, first_name, last_name, password FROM students WHERE student_code = ? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $prefilled);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($r)) {
            $student = $row;
            $step = empty($row['password']) ? 'set-password' : 'enter-password';
        } else {
            $errors[] = "No student found with that code.";
        }
        mysqli_stmt_close($stmt);
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login | Educ Bridge</title>
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

        .wrap { width:100%; max-width:520px; position:relative; z-index:1; }
        .brand { display:flex; align-items:center; justify-content:center; gap:12px; margin-bottom:22px; }
        .brand img { width:48px; height:48px; border-radius:50%; background:#fff; padding:3px; border:2px solid var(--border-color); }
        .brand-text { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; }
        .brand-text span { color: var(--primary); }

        .card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:20px; box-shadow:var(--shadow-lg); overflow:hidden; }
        .card-head { padding:30px 30px 22px; text-align:center; border-bottom:1px solid var(--border-color); background:linear-gradient(135deg, rgba(37,99,235,0.07), rgba(124,58,237,0.05)); }
        .card-head .icon {
            width: 68px; height: 68px;
            border-radius: 18px;
            background: linear-gradient(135deg, var(--primary), #7c3aed);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; margin: 0 auto 14px;
            box-shadow: 0 12px 30px rgba(37,99,235,0.35);
        }
        .card-head h1 { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; margin-bottom:6px; }
        .card-head p { font-size:13.5px; color:var(--text-muted); line-height:1.55; }
        .card-body { padding:26px 30px 30px; }

        .alert { padding:13px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; display:flex; align-items:flex-start; gap:10px; line-height:1.55; }
        .alert-error { background: rgba(239,68,68,0.08); color:#dc2626; border:1px solid rgba(239,68,68,0.25); }
        .alert-info  { background: rgba(37,99,235,0.08); color:#1d4ed8; border:1px solid rgba(37,99,235,0.25); }
        [data-theme="dark"] .alert-error { color:#f87171; }
        [data-theme="dark"] .alert-info  { color:#93c5fd; }
        .alert ul { margin-left: 16px; }

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

        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%;
            padding: 13px 22px;
            border-radius: 10px;
            font-family: inherit; font-size: 14.5px; font-weight: 700;
            border: 1.5px solid transparent;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
        }
        .btn.primary { background: var(--primary); color: #fff; box-shadow: 0 6px 18px rgba(37,99,235,0.32); }
        .btn.primary:hover { background: var(--primary-dark); }
        .btn.ghost { background: var(--bg-alt); border-color: var(--border-color); color: var(--text-secondary); }
        .btn.ghost:hover { border-color: var(--primary); color: var(--primary); }

        .step-pill {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 12px; border-radius: 50px;
            font-size: 11px; font-weight: 700;
            background: var(--primary-light); color: var(--primary);
            margin-bottom: 14px;
        }
        .student-pill {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 14px; border-radius: 50px;
            background: var(--bg-alt); border: 1px solid var(--border-color);
            font-size: 13px; font-weight: 600;
            margin-bottom: 18px;
        }
        .student-pill .av {
            width: 26px; height: 26px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), #7c3aed);
            color: #fff; display: inline-flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700;
        }

        .foot { text-align:center; margin-top:20px; font-size:13px; color:var(--text-muted); }
        .foot a { color: var(--primary); font-weight: 600; text-decoration: none; }
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
            <div class="icon"><i class="fas fa-user-graduate"></i></div>
            <h1>
                <?php
                    if ($step === 'set-password')   echo "Set Your Password";
                    elseif ($step === 'enter-password') echo "Welcome Back";
                    else echo "Student Login";
                ?>
            </h1>
            <p>
                <?php
                    if ($step === 'set-password')   echo "Create a password for your student account. You'll use it every time you sign in.";
                    elseif ($step === 'enter-password') echo "Enter your password to access your dashboard.";
                    else echo "Enter your student code to get started.";
                ?>
            </p>
        </div>

        <div class="card-body">

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle" style="margin-top:2px;"></i>
                    <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e); ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($info)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($info); ?></span>
                </div>
            <?php endif; ?>

            <!-- STEP 1 — CODE -->
            <?php if ($step === 'code'): ?>
                <form method="POST" action="student-login.php" autocomplete="off">
                    <input type="hidden" name="action" value="check_code">
                    <div class="form-group">
                        <label>Student Code</label>
                        <input type="text" name="student_code" class="code"
                               placeholder="5012342026"
                               maxlength="20" required autofocus
                               value="<?= htmlspecialchars($prefilled); ?>">
                        <div class="hint">The 10-digit code you received when you registered.</div>
                    </div>
                    <button type="submit" class="btn primary">
                        <i class="fas fa-arrow-right"></i> Continue
                    </button>
                </form>
            <?php endif; ?>

            <!-- STEP 2 — SET PASSWORD -->
            <?php if ($step === 'set-password'): ?>
                <div class="step-pill"><i class="fas fa-star"></i> First time? Set a password</div>
                <form method="POST" action="student-login.php" autocomplete="off">
                    <input type="hidden" name="action" value="set_password">
                    <input type="hidden" name="student_code" value="<?= htmlspecialchars($prefilled); ?>">

                    <?php if (!empty($student)): ?>
                        <div class="student-pill">
                            <span class="av"><?= htmlspecialchars(strtoupper(substr($student['first_name'] ?? 'S', 0, 1) . substr($student['last_name'] ?? '', 0, 1))); ?></span>
                            <?= htmlspecialchars(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')); ?>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="password" minlength="6" required autofocus>
                        <div class="hint">At least 6 characters.</div>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password</label>
                        <input type="password" name="password2" minlength="6" required>
                    </div>
                    <button type="submit" class="btn primary">
                        <i class="fas fa-check"></i> Save Password &amp; Sign In
                    </button>
                </form>
            <?php endif; ?>

            <!-- STEP 3 — ENTER PASSWORD -->
            <?php if ($step === 'enter-password'): ?>
                <form method="POST" action="student-login.php" autocomplete="off">
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="student_code" value="<?= htmlspecialchars($prefilled); ?>">

                    <?php if (!empty($student)): ?>
                        <div class="student-pill">
                            <span class="av"><?= htmlspecialchars(strtoupper(substr($student['first_name'] ?? 'S', 0, 1) . substr($student['last_name'] ?? '', 0, 1))); ?></span>
                            <?= htmlspecialchars(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')); ?>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" required autofocus>
                    </div>
                    <button type="submit" class="btn primary">
                        <i class="fas fa-sign-in-alt"></i> Sign In
                    </button>
                    <div style="margin-top:12px;text-align:center;">
                        <a href="student-login.php" style="font-size:12.5px;color:var(--text-muted);text-decoration:none;">
                            <i class="fas fa-rotate-left"></i> Use a different code
                        </a>
                    </div>
                </form>
            <?php endif; ?>

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