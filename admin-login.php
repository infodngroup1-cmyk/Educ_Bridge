<?php

session_start();

require_once 'db.php';

// If already logged in, redirect to dashboard
if (!empty($_SESSION["admin_id"]) && !empty($_SESSION["admin_verified"])) {
    header("Location: admin-dashboard.php");
    exit;
}

$message = "";
$message_type = "";
$show_verify_button = false;   // <-- new flag

$identifier = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $identifier = trim($_POST["identifier"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($identifier === "" || $password === "") {

        $message = "Please enter your email/phone and password.";
        $message_type = "error";

    } else {

        // Accept either email or phone (10 digits)
        $sql = "
            SELECT id, name, email, phone, password, verified
            FROM admin
            WHERE email = ? OR phone = ?
            LIMIT 1
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {

            $message = "Database error. Please try again.";
            $message_type = "error";

        } else {

            mysqli_stmt_bind_param($stmt, "ss", $identifier, $identifier);
            mysqli_stmt_execute($stmt);

            $result = mysqli_stmt_get_result($stmt);

            if (mysqli_num_rows($result) === 0) {

                $message = "No account found with that email or phone number.";
                $message_type = "error";

            } else {

                $admin = mysqli_fetch_assoc($result);

                if (!password_verify($password, $admin["password"])) {

                    $message = "Incorrect password. Please try again.";
                    $message_type = "error";

                } elseif ((int) $admin["verified"] !== 1) {

                    // Account exists but not yet verified
                    $_SESSION["admin_verify_email"] = $admin["email"];

                    $message = "Your account is not verified yet. Please verify it using the code sent to our inbox.";
                    $message_type = "error";
                    $show_verify_button = true;   // <-- tell the view to show the Verify button

                } else {

                    // Successful login
                    $_SESSION["admin_id"]       = $admin["id"];
                    $_SESSION["admin_name"]     = $admin["name"];
                    $_SESSION["admin_email"]    = $admin["email"];
                    $_SESSION["admin_phone"]    = $admin["phone"];
                    $_SESSION["admin_verified"] = true;

                    header("Location: admin-dashboard.php");
                    exit;
                }
            }

            mysqli_stmt_close($stmt);
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | Educ Bridge</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8; --primary-light: #dbeafe;
            --bg-body: #f8fafc; --bg-card: #ffffff;
            --bg-alt: #f1f5f9;
            --text-primary: #0f172a; --text-secondary: #475569; --text-muted: #64748b;
            --border-color: #e2e8f0;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.08);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        [data-theme="dark"] {
            --primary: #3b82f6; --primary-dark: #2563eb; --primary-light: #1e3a5f;
            --bg-body: #0b1120; --bg-card: #1e293b;
            --bg-alt: #1a2332;
            --text-primary: #f1f5f9; --text-secondary: #cbd5e1; --text-muted: #94a3b8;
            --border-color: #334155;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.3);
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.5);
        }
        *,*::before,*::after { margin:0; padding:0; box-sizing:border-box; }
        html, body { min-height: 100vh; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            display: flex;
            flex-direction: column;
            transition: background 0.4s ease, color 0.4s ease;
            position: relative;
            overflow-x: hidden;
        }
        body::before {
            content: '';
            position: fixed;
            top: -50%; right: -20%;
            width: 600px; height: 600px;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.10) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        body::after {
            content: '';
            position: fixed;
            bottom: -30%; left: -10%;
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(124, 58, 237, 0.08) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        /* Top bar */
        .top-bar {
            width: 100%;
            padding: 16px 6%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            position: relative;
            z-index: 5;
        }
        .top-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: var(--text-primary);
        }
        .top-brand img {
            width: 40px; height: 40px;
            object-fit: contain;
            border-radius: 50%;
            border: 2px solid var(--border-color);
            padding: 2px;
            background: var(--bg-card);
            transition: var(--transition);
        }
        .top-brand:hover img {
            border-color: var(--primary);
            transform: rotate(-5deg) scale(1.05);
        }
        .top-brand-name {
            font-family: 'Poppins', sans-serif;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.4px;
        }
        .top-brand-name span { color: var(--primary); }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .top-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 10px 18px;
            border-radius: 10px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
            border: 1.5px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-secondary);
            white-space: nowrap;
        }
        .top-btn i { font-size: 13px; }
        .top-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-1px);
        }
        .top-btn.primary {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);
        }
        .top-btn.primary:hover {
            background: var(--primary-dark);
            color: #fff;
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
        }
        .top-btn.icon-only {
            width: 42px; height: 42px;
            padding: 0;
            justify-content: center;
            border-radius: 50%;
        }
        .top-btn.icon-only i { font-size: 15px; }
        .top-btn.icon-only:hover { transform: rotate(20deg); }

        /* Main */
        .main-wrap {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px 15px 50px;
            position: relative;
            z-index: 1;
        }
        .card {
            width: 100%;
            max-width: 480px;
            background: var(--bg-card);
            padding: 42px 40px;
            border-radius: 22px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border-color);
            transition: var(--transition);
        }
        .card-logo { text-align: center; margin-bottom: 18px; }
        .card-logo img {
            width: 82px; height: 82px;
            object-fit: contain;
            border-radius: 50%;
            border: 2px solid var(--border-color);
            padding: 4px;
            background: var(--bg-card);
            transition: var(--transition);
        }
        .card-logo img:hover {
            border-color: var(--primary);
            transform: rotate(-5deg) scale(1.05);
        }
        h1 {
            font-family: 'Poppins', sans-serif;
            text-align: center;
            font-size: 27px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }
        h1 span { color: var(--primary); }
        .subtitle {
            text-align: center;
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 28px;
            line-height: 1.6;
        }
        .message {
            padding: 13px 16px;
            border-radius: 10px;
            margin-bottom: 22px;
            font-size: 14px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            line-height: 1.5;
        }
        .error { background: rgba(239, 68, 68, 0.08); color: #dc2626; border: 1px solid rgba(239, 68, 68, 0.25); }
        [data-theme="dark"] .error { color: #f87171; }
        .success { background: rgba(16, 185, 129, 0.08); color: #059669; border: 1px solid rgba(16, 185, 129, 0.25); }
        [data-theme="dark"] .success { color: #34d399; }

        /* Warning message specifically for unverified accounts */
        .warning {
            background: rgba(245, 158, 11, 0.10);
            color: #b45309;
            border: 1px solid rgba(245, 158, 11, 0.35);
        }
        [data-theme="dark"] .warning { color: #fbbf24; }

        /* Verify button (blue) */
        .verify-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 14px;
            border-radius: 10px;
            background: var(--primary);
            color: #fff;
            text-decoration: none;
            font-family: 'Inter', sans-serif;
            font-size: 15px;
            font-weight: 600;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);
            transition: var(--transition);
            margin-bottom: 22px;
        }
        .verify-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
        }
        .verify-btn i { font-size: 14px; }

        .form-group { margin-bottom: 18px; }
        label {
            display: block;
            font-size: 13.5px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        label .required { color: #ef4444; }

        .input-wrapper { position: relative; }
        .input-wrapper i.input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 15px;
            pointer-events: none;
            transition: var(--transition);
        }
        .input-wrapper input {
            width: 100%;
            padding: 13px 15px 13px 44px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            font-family: 'Inter', sans-serif;
            font-size: 15px;
            color: var(--text-primary);
            background: var(--bg-body);
            outline: none;
            transition: var(--transition);
        }
        .input-wrapper input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .input-wrapper input:focus + i.input-icon,
        .input-wrapper input:not(:placeholder-shown) + i.input-icon {
            color: var(--primary);
        }
        .input-wrapper input::placeholder { color: var(--text-muted); }

        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 15px;
            padding: 4px;
            transition: var(--transition);
        }
        .toggle-password:hover { color: var(--primary); }

        .form-helper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: -4px;
            margin-bottom: 20px;
            font-size: 13.5px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .form-helper a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: var(--transition);
        }
        .form-helper a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        button[type="submit"] {
            width: 100%;
            border: none;
            border-radius: 10px;
            padding: 15px;
            background: var(--primary);
            color: #fff;
            font-family: 'Inter', sans-serif;
            font-size: 15.5px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        button[type="submit"]:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 24px 0 18px;
            color: var(--text-muted);
            font-size: 12.5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 500;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border-color);
        }

        .alt-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 14px;
            border-radius: 10px;
            background: var(--bg-alt);
            color: var(--text-primary);
            text-decoration: none;
            font-size: 15px;
            font-weight: 600;
            border: 1.5px solid var(--border-color);
            transition: var(--transition);
        }
        .alt-action:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-2px);
        }

        .card-back {
            text-align: center;
            margin-top: 22px;
            font-size: 14px;
        }
        .card-back a {
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: var(--transition);
        }
        .card-back a:hover {
            color: var(--primary);
        }

        @media (max-width: 600px) {
            .top-bar { padding: 14px 4%; }
            .top-brand-name { display: none; }
            .top-btn span.label { display: none; }
            .top-btn { padding: 10px 12px; }
        }
        @media (max-width: 480px) {
            .card { padding: 32px 24px; }
            h1 { font-size: 22px; }
        }
    </style>
</head>
<body>

<header class="top-bar">
    <a href="index.php" class="top-brand">
        <img src="logo.png" alt="Educ Bridge Logo">
        <span class="top-brand-name">Educ<span>Bridge</span></span>
    </a>

    <div class="top-actions">

        <a href="admin-register.php" class="top-btn primary">
            <i class="fas fa-user-plus"></i>
            <span class="label">Register</span>
        </a>

        <a href="admin-forgot-password.php" class="top-btn">
            <i class="fas fa-key"></i>
            <span class="label">Forgot Password</span>
        </a>

        <button class="top-btn icon-only" id="themeToggle" aria-label="Toggle theme" title="Toggle theme">
            <i class="fas fa-moon"></i>
        </button>

    </div>
</header>

<main class="main-wrap">
    <div class="card">

        <div class="card-logo">
            <img src="logo.png" alt="Educ Bridge Logo">
        </div>

        <h1>Admin <span>Login</span></h1>
        <p class="subtitle">Sign in to access your Educ Bridge dashboard</p>

        <?php if ($message !== ""): ?>
            <div class="message <?= $show_verify_button ? 'warning' : htmlspecialchars($message_type); ?>">
                <i class="fas <?= $show_verify_button ? 'fa-exclamation-triangle' : ($message_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'); ?>" style="margin-top:2px;"></i>
                <span><?= htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($show_verify_button): ?>
            <!-- Verify Now button -->
            <a href="admin-verify.php" class="verify-btn">
                <i class="fas fa-shield-alt"></i> Verify My Account Now
            </a>
        <?php endif; ?>

        <form method="POST" autocomplete="off">

            <div class="form-group">
                <label for="identifier">Email or Phone Number <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input
                        type="text"
                        id="identifier"
                        name="identifier"
                        placeholder="you@example.com or 0783561459"
                        value="<?= htmlspecialchars($identifier); ?>"
                        required
                        autofocus
                    >
                    <i class="fas fa-user input-icon"></i>
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                    <i class="fas fa-lock input-icon"></i>
                    <button type="button" class="toggle-password" data-target="password" aria-label="Show password">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="form-helper">
                <a href="admin-forgot-password.php">
                    <i class="fas fa-key"></i> Forgot Password?
                </a>
            </div>

            <button type="submit">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>

        </form>

        <div class="divider">don't have an account?</div>

        <a href="admin-register.php" class="alt-action">
            <i class="fas fa-user-plus"></i> Create Admin Account
        </a>

        <div class="card-back">
            <a href="index.php">
                <i class="fas fa-arrow-left"></i> Back to Educ Bridge
            </a>
        </div>

    </div>
</main>

<script>
    (function () {
        const html = document.documentElement;
        const toggle = document.getElementById('themeToggle');
        const icon = toggle.querySelector('i');
        const savedTheme = localStorage.getItem('educbridge-theme') || 'light';
        html.setAttribute('data-theme', savedTheme);
        updateIcon(savedTheme);
        toggle.addEventListener('click', function () {
            const current = html.getAttribute('data-theme');
            const next = current === 'light' ? 'dark' : 'light';
            html.setAttribute('data-theme', next);
            localStorage.setItem('educbridge-theme', next);
            updateIcon(next);
        });
        function updateIcon(theme) {
            if (theme === 'dark') { icon.classList.remove('fa-moon'); icon.classList.add('fa-sun'); }
            else { icon.classList.remove('fa-sun'); icon.classList.add('fa-moon'); }
        }

        document.querySelectorAll('.toggle-password').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const target = document.getElementById(this.dataset.target);
                const icon = this.querySelector('i');
                if (!target) return;
                if (target.type === 'password') {
                    target.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    target.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            });
        });
    })();
</script>

</body>
</html>