<?php
require_once 'db.php';
session_start();

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$ref         = strtoupper(trim((string)($_GET['ref'] ?? '')));
$mail_warn   = $_SESSION['apply_mail_warning'] ?? '';
unset($_SESSION['apply_mail_warning']);

$app = null;
if ($ref !== '') {
    $s = mysqli_prepare($conn, "
        SELECT a.*, s.name AS school_name, s.school_code
        FROM applications a
        JOIN schools s ON s.id = a.school_id
        WHERE a.reference_no = ?
        LIMIT 1
    ");
    if ($s) {
        mysqli_stmt_bind_param($s, "s", $ref);
        mysqli_stmt_execute($s);
        $r = mysqli_stmt_get_result($s);
        if ($row = mysqli_fetch_assoc($r)) $app = $row;
        mysqli_stmt_close($s);
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Application Submitted | Educ Bridge</title>
<link rel="icon" type="image/png" href="logo.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root{--primary:#2563eb;--primary-dark:#1d4ed8;--primary-light:#dbeafe;--success:#10b981;--success-light:rgba(16,185,129,.1);--warning:#f59e0b;--danger:#ef4444;--bg:#f1f5f9;--card:#fff;--alt:#f8fafc;--text:#0f172a;--muted:#64748b;--soft:#475569;--border:#e2e8f0;--shadow:0 20px 45px rgba(0,0,0,.08);--t:all .3s cubic-bezier(.4,0,.2,1)}
    [data-theme="dark"]{--primary:#3b82f6;--primary-dark:#2563eb;--primary-light:#1e3a5f;--success:#34d399;--warning:#fbbf24;--danger:#f87171;--bg:#0b1120;--card:#1e293b;--alt:#1a2332;--text:#f1f5f9;--muted:#94a3b8;--soft:#cbd5e1;--border:#334155;--shadow:0 20px 45px rgba(0,0,0,.5)}
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    html,body{min-height:100vh}
    body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);display:flex;align-items:center;justify-content:center;padding:32px 16px;position:relative}
    body::before{content:'';position:fixed;inset:0;background:radial-gradient(circle at 10% 10%,rgba(16,185,129,.10),transparent 45%),radial-gradient(circle at 90% 90%,rgba(37,99,235,.10),transparent 45%);pointer-events:none;z-index:0}
    .theme-toggle{position:fixed;top:20px;right:20px;width:42px;height:42px;border-radius:50%;border:1.5px solid var(--border);background:var(--card);color:var(--soft);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:15px;z-index:20;transition:var(--t)}
    .theme-toggle:hover{color:var(--primary);border-color:var(--primary);transform:rotate(20deg)}
    .wrap{max-width:620px;width:100%;position:relative;z-index:1}
    .brand{display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:22px}
    .brand img{width:46px;height:46px;border-radius:50%;background:#fff;padding:3px;border:2px solid var(--border)}
    .brand-text{font-family:'Poppins',sans-serif;font-size:20px;font-weight:700}
    .brand-text span{color:var(--primary)}
    .card{background:var(--card);border:1px solid var(--border);border-radius:22px;box-shadow:var(--shadow);overflow:hidden;text-align:center}
    .success-head{padding:40px 30px 24px}
    .tick{width:86px;height:86px;border-radius:50%;background:var(--success-light);color:var(--success);display:inline-flex;align-items:center;justify-content:center;font-size:38px;margin-bottom:18px;box-shadow:0 0 0 8px rgba(16,185,129,.08)}
    [data-theme="dark"] .tick{color:#34d399}
    .success-head h1{font-family:'Poppins',sans-serif;font-size:24px;margin-bottom:10px;font-weight:700}
    .success-head p{color:var(--muted);line-height:1.65;font-size:14.5px;max-width:480px;margin:0 auto}
    .email-note{margin:16px 26px 0;padding:12px 16px;border-radius:10px;font-size:13px;display:flex;align-items:flex-start;gap:10px;text-align:left;line-height:1.55}
    .email-note.ok{background:rgba(37,99,235,.08);color:#1d4ed8;border:1px solid rgba(37,99,235,.2)}
    .email-note.warn{background:rgba(245,158,11,.10);color:#b45309;border:1px solid rgba(245,158,11,.3)}
    [data-theme="dark"] .email-note.ok{color:#93c5fd}
    [data-theme="dark"] .email-note.warn{color:#fbbf24}
    .ref-block{margin:22px 26px 0;padding:24px;border-radius:16px;background:linear-gradient(135deg,rgba(37,99,235,.06),rgba(124,58,237,.04));border:1px dashed var(--border)}
    .ref-block .rb-label{font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-bottom:10px}
    .ref-block .rb-value{font-family:'Poppins',sans-serif;font-size:34px;font-weight:800;letter-spacing:3px;color:var(--primary);line-height:1.1;word-break:break-all}
    .ref-block .rb-copy{display:inline-flex;align-items:center;gap:8px;margin-top:14px;padding:8px 16px;border-radius:8px;background:var(--card);border:1.5px solid var(--border);color:var(--soft);font-size:12.5px;font-weight:700;cursor:pointer;transition:var(--t)}
    .ref-block .rb-copy:hover{border-color:var(--primary);color:var(--primary)}
    .summary{margin:24px 26px 0;text-align:left;border-top:1px solid var(--border);padding-top:20px}
    .summary .sm-title{font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:1.2px;color:var(--muted);margin-bottom:12px}
    .kv-grid{display:grid;grid-template-columns:130px 1fr;gap:8px 16px;font-size:13.5px}
    .kv-grid .k{color:var(--muted);font-weight:600}
    .kv-grid .v{color:var(--text);font-weight:600;word-break:break-word}
    .actions{display:flex;gap:10px;flex-wrap:wrap;padding:24px 26px 30px}
    .actions a{flex:1 1 180px;display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:13px 18px;border-radius:10px;text-decoration:none;font-weight:700;font-size:14px;transition:var(--t)}
    .actions a.primary{background:var(--primary);color:#fff;box-shadow:0 6px 18px rgba(37,99,235,.32)}
    .actions a.primary:hover{background:var(--primary-dark)}
    .actions a.soft{background:var(--alt);color:var(--soft);border:1.5px solid var(--border)}
    .actions a.soft:hover{border-color:var(--primary);color:var(--primary)}
    .hint{font-size:12.5px;color:var(--muted);line-height:1.6;padding:0 26px 24px}
    .hint strong{color:var(--text)}
    .foot{text-align:center;margin-top:22px;font-size:12.5px;color:var(--muted)}
    .foot a{color:var(--primary);text-decoration:none;font-weight:600}
    .no-ref{max-width:480px;margin:0 auto;background:var(--card);border:1px solid var(--border);border-radius:20px;padding:36px 28px;text-align:center;box-shadow:var(--shadow)}
    .no-ref .ico{width:64px;height:64px;border-radius:50%;background:rgba(239,68,68,.1);color:#ef4444;display:inline-flex;align-items:center;justify-content:center;font-size:26px;margin-bottom:16px}
    .no-ref h1{font-family:'Poppins',sans-serif;font-size:22px;margin-bottom:10px}
    .no-ref p{color:var(--muted);line-height:1.65;font-size:14.5px}
</style>
</head>
<body>
<button class="theme-toggle" id="themeToggle" aria-label="Toggle theme"><i class="fas fa-moon"></i></button>
<div class="wrap">
    <div class="brand">
        <img src="logo.png" alt="Educ Bridge">
        <div class="brand-text">Educ<span>Bridge</span></div>
    </div>

    <?php if ($app): ?>
        <div class="card">
            <div class="success-head">
                <div class="tick"><i class="fas fa-check"></i></div>
                <h1>Application submitted</h1>
                <p>
                    Your application to <strong><?= esc($app['school_name']); ?></strong> was received successfully.
                    Save your reference number — you will need it to check your admission status.
                </p>
            </div>

            <div class="email-note ok">
                <i class="fas fa-envelope-circle-check" style="margin-top:2px;"></i>
                <span>
                    A confirmation email with your reference number was sent to
                    <strong><?= esc($app['response_email']); ?></strong>.
                </span>
            </div>

            <?php if ($mail_warn !== ''): ?>
                <div class="email-note warn">
                    <i class="fas fa-triangle-exclamation" style="margin-top:2px;"></i>
                    <span>
                        We could not send the confirmation email right now.
                        Please write down the reference number below — that's the important part.
                    </span>
                </div>
            <?php endif; ?>

            <div class="ref-block">
                <div class="rb-label">Your reference number</div>
                <div class="rb-value" id="refValue"><?= esc($app['reference_no']); ?></div>
                <button class="rb-copy" type="button" id="copyBtn">
                    <i class="fas fa-copy"></i> Copy to clipboard
                </button>
            </div>

            <div class="summary">
                <div class="sm-title">Application summary</div>
                <div class="kv-grid">
                    <div class="k">Student</div><div class="v"><?= esc($app['student_name']); ?></div>
                    <div class="k">Level</div><div class="v"><?= esc($app['level']); ?></div>
                    <div class="k">Response email</div><div class="v"><?= esc($app['response_email']); ?></div>
                    <?php if (!empty($app['phone'])): ?>
                        <div class="k">Phone</div><div class="v"><?= esc($app['phone']); ?></div>
                    <?php endif; ?>
                    <div class="k">Status</div><div class="v" style="color:var(--warning);text-transform:capitalize;"><?= esc($app['status']); ?></div>
                    <div class="k">Submitted</div><div class="v"><?= esc(date('d M Y, H:i', strtotime($app['created_at']))); ?></div>
                </div>
            </div>

            <div class="hint">
                <strong>Next step:</strong> bookmark or screenshot this page.
                You can return any time and enter this reference number on the school's
                "Check admission" page to see your decision.
            </div>

            <div class="actions">
                <a class="primary"
                   href="online-school-application.php?school=<?= urlencode($app['school_code']); ?>&ref=<?= urlencode($app['reference_no']); ?>">
                    <i class="fas fa-magnifying-glass"></i> Check admission
                </a>
                <a class="soft" href="index.php"><i class="fas fa-house"></i> Back to Home</a>
            </div>
        </div>
    <?php else: ?>
        <div class="no-ref">
            <div class="ico"><i class="fas fa-circle-exclamation"></i></div>
            <h1>Reference not found</h1>
            <p>We couldn't find an application with that reference number.</p>
            <div style="margin-top:20px;">
                <a class="btn" href="online-registration.php"
                   style="display:inline-flex;align-items:center;gap:8px;padding:12px 22px;background:var(--primary);color:#fff;text-decoration:none;border-radius:10px;font-weight:700;font-size:14px;">
                    <i class="fas fa-rotate-left"></i> Start over
                </a>
            </div>
        </div>
    <?php endif; ?>

    <div class="foot">Powered by <a href="#">Educ Bridge</a></div>
</div>
<script>
    (function(){
        const html = document.documentElement;
        const t = document.getElementById('themeToggle');
        const i = t.querySelector('i');
        function apply(theme){
            html.setAttribute('data-theme', theme);
            localStorage.setItem('educbridge-theme', theme);
            if (theme === 'dark'){ i.classList.remove('fa-moon'); i.classList.add('fa-sun'); }
            else { i.classList.remove('fa-sun'); i.classList.add('fa-moon'); }
        }
        apply(localStorage.getItem('educbridge-theme') || 'light');
        t.addEventListener('click', () => apply(html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'));
    })();
    const btn = document.getElementById('copyBtn');
    if (btn) {
        btn.addEventListener('click', function(){
            const val = document.getElementById('refValue').textContent.trim();
            navigator.clipboard.writeText(val).then(function(){
                const old = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                setTimeout(() => { btn.innerHTML = old; }, 1600);
            }).catch(function(){
                const r = document.createRange();
                r.selectNode(document.getElementById('refValue'));
                window.getSelection().removeAllRanges();
                window.getSelection().addRange(r);
                document.execCommand('copy');
                window.getSelection().removeAllRanges();
            });
        });
    }
</script>
</body>
</html>