<?php

session_start();

require_once 'db.php';

// Redirect if not logged in
if (empty($_SESSION["admin_id"]) || empty($_SESSION["admin_verified"])) {
    header("Location: admin-login.php");
    exit;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    header("Location: admin-schools.php");
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT * FROM schools WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($res) === 0) {
    header("Location: admin-schools.php");
    exit;
}

$s = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($s['name']); ?> — Registration Certificate</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #e2e8f0 0%, #f1f5f9 100%);
            color: #0f172a;
            padding: 40px 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* ===== CERTIFICATE ===== */
        .certificate {
            position: relative;
            width: 100%;
            max-width: 820px;
            background: #ffffff;
            border-radius: 20px;
            box-shadow:
                0 30px 80px rgba(15, 23, 42, 0.15),
                0 8px 24px rgba(15, 23, 42, 0.08);
            overflow: hidden;
            padding: 0;
        }

        /* Decorative top strip */
        .cert-strip {
            height: 8px;
            background: linear-gradient(90deg, #2563eb 0%, #7c3aed 50%, #2563eb 100%);
        }

        /* Inner border frame */
        .cert-inner {
            padding: 42px 48px 0;
            position: relative;
        }
        .cert-inner::before {
            content: '';
            position: absolute;
            top: 22px;
            left: 22px;
            right: 22px;
            bottom: 22px;
            border: 2px dashed #e2e8f0;
            border-radius: 14px;
            pointer-events: none;
        }

        /* Watermark */
        .cert-watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-25deg);
            font-family: 'Poppins', sans-serif;
            font-size: 120px;
            font-weight: 800;
            color: rgba(37, 99, 235, 0.03);
            white-space: nowrap;
            pointer-events: none;
            user-select: none;
            letter-spacing: -4px;
            z-index: 0;
        }

        /* ===== HEADER ===== */
        .cert-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            padding-bottom: 26px;
            border-bottom: 1px solid #e2e8f0;
            position: relative;
            z-index: 2;
            margin-bottom: 32px;
        }

        .cert-brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .cert-brand-logo {
            width: 62px;
            height: 62px;
            border-radius: 14px;
            padding: 6px;
            background: #ffffff;
            border: 2px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
            flex-shrink: 0;
        }
        .cert-brand-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .cert-brand-text h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.5px;
            line-height: 1.15;
        }
        .cert-brand-text h1 span {
            color: #2563eb;
        }
        .cert-brand-text p {
            font-size: 11.5px;
            color: #64748b;
            font-weight: 500;
            margin-top: 3px;
            text-transform: uppercase;
            letter-spacing: 1.2px;
        }

        .cert-ref {
            text-align: right;
            flex-shrink: 0;
        }
        .cert-ref-label {
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 1.3px;
            color: #94a3b8;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .cert-ref-value {
            font-family: 'Poppins', sans-serif;
            font-size: 15px;
            font-weight: 700;
            color: #2563eb;
            letter-spacing: 0.5px;
        }
        .cert-ref-sub {
            font-size: 11.5px;
            color: #64748b;
            margin-top: 4px;
        }

        /* ===== TITLE ===== */
        .cert-title {
            text-align: center;
            margin-bottom: 30px;
            position: relative;
            z-index: 2;
        }
        .cert-title-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 16px;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            border-radius: 50px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 14px;
            box-shadow: 0 6px 18px rgba(37, 99, 235, 0.25);
        }
        .cert-title-badge i {
            font-size: 11px;
        }
        .cert-title h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 34px;
            font-weight: 800;
            letter-spacing: -1px;
            color: #0f172a;
            line-height: 1.15;
            margin-bottom: 6px;
        }
        .cert-title h2 span {
            color: #2563eb;
        }
        .cert-title p {
            font-size: 13.5px;
            color: #64748b;
            max-width: 480px;
            margin: 0 auto;
            line-height: 1.6;
        }

        /* ===== SCHOOL NAME ===== */
        .school-name-block {
            text-align: center;
            margin-bottom: 28px;
            position: relative;
            z-index: 2;
        }
        .school-name-block .label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #94a3b8;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .school-name-block .name {
            font-family: 'Poppins', sans-serif;
            font-size: 30px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.8px;
            line-height: 1.2;
        }
        .school-name-block .underline {
            display: block;
            width: 90px;
            height: 4px;
            background: linear-gradient(90deg, #2563eb, #7c3aed);
            border-radius: 2px;
            margin: 14px auto 0;
        }

        /* ===== SCHOOL CODE HIGHLIGHT ===== */
        .school-code-block {
            max-width: 440px;
            margin: 0 auto 32px;
            padding: 22px 26px;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.06), rgba(124, 58, 237, 0.04));
            border: 2px solid #2563eb;
            border-radius: 16px;
            text-align: center;
            position: relative;
            z-index: 2;
            box-shadow: 0 8px 24px rgba(37, 99, 235, 0.12);
        }
        .school-code-block .sc-label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1.4px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .school-code-block .sc-value {
            font-family: 'Courier New', monospace;
            font-size: 34px;
            font-weight: 800;
            color: #2563eb;
            letter-spacing: 6px;
            line-height: 1;
            margin-bottom: 10px;
        }
        .school-code-block .sc-note {
            font-size: 12px;
            color: #64748b;
            line-height: 1.6;
            margin: 0;
        }

        /* ===== DETAILS GRID ===== */
        .details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px 40px;
            margin-bottom: 34px;
            position: relative;
            z-index: 2;
        }
        .detail-item {
            padding: 12px 0;
            border-bottom: 1px dashed #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .detail-item .lbl {
            font-size: 12px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            display: flex;
            align-items: center;
            gap: 7px;
            flex-shrink: 0;
        }
        .detail-item .lbl i {
            color: #2563eb;
            font-size: 12px;
            width: 14px;
            text-align: center;
        }
        .detail-item .val {
            font-size: 14px;
            color: #0f172a;
            font-weight: 600;
            text-align: right;
            word-break: break-word;
        }
        .detail-item .val.mono {
            font-family: 'Poppins', sans-serif;
            letter-spacing: 0.5px;
        }

        /* Status pill */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-pill.active {
            background: rgba(16, 185, 129, 0.12);
            color: #10b981;
        }
        .status-pill.deactivated {
            background: rgba(239, 68, 68, 0.12);
            color: #ef4444;
        }
        .status-pill i {
            font-size: 8px;
        }

        /* ===== SEAL + SIGNATURE ===== */
        .cert-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 30px;
            padding: 28px 0 32px;
            border-top: 1px solid #e2e8f0;
            position: relative;
            z-index: 2;
        }

        .signature {
            text-align: center;
            flex: 1;
        }
        .signature-line {
            height: 1.5px;
            background: #cbd5e1;
            width: 100%;
            max-width: 220px;
            margin: 0 auto 10px;
        }
        .signature-name {
            font-family: 'Poppins', sans-serif;
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 3px;
        }
        .signature-role {
            font-size: 11.5px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        /* Digital signature image (CDO.jpg) */
        .signature-image {
            display: block;
            max-width: 180px;
            max-height: 70px;
            width: auto;
            height: auto;
            object-fit: contain;
            margin: 0 auto 8px;
            filter: contrast(1.1);
            mix-blend-mode: multiply;
        }

        /* Seal — uses logo.png */
        .seal {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 8px;
            position: relative;
            flex-shrink: 0;
            background: #ffffff;
            border: 4px solid #ffffff;
            outline: 2px solid #2563eb;
            outline-offset: -8px;
            box-shadow: 0 12px 30px rgba(37, 99, 235, 0.25);
            overflow: hidden;
        }
        .seal img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 50%;
        }

        /* ===== FOOTER BAR ===== */
        .cert-bottom {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: #ffffff;
            padding: 16px 48px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            font-size: 12.5px;
        }
        .cert-bottom-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .cert-bottom-left i {
            color: #60a5fa;
            font-size: 14px;
        }
        .cert-bottom-left strong {
            color: #60a5fa;
            font-weight: 700;
        }
        .cert-bottom-right {
            font-size: 12px;
            color: #94a3b8;
        }

        /* ===== ACTION BAR ===== */
        .action-bar {
            position: fixed;
            top: 24px;
            right: 24px;
            display: flex;
            gap: 10px;
            z-index: 100;
            flex-wrap: wrap;
        }
        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 18px;
            border-radius: 10px;
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            color: #0f172a;
            text-decoration: none;
            font-family: 'Inter', sans-serif;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.06);
            transition: all 0.25s ease;
        }
        .action-btn:hover {
            border-color: #2563eb;
            color: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.15);
        }
        .action-btn.primary {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 6px 18px rgba(37, 99, 235, 0.3);
        }
        .action-btn.primary:hover {
            background: #1d4ed8;
            color: #ffffff;
        }
        .action-btn.whatsapp:hover {
            background: #25D366;
            color: #ffffff;
            border-color: #25D366;
        }
        .action-btn.email:hover {
            background: #ea4335;
            color: #ffffff;
            border-color: #ea4335;
        }
        .action-btn.copy-btn:hover {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 700px) {
            body { padding: 80px 12px 20px; }
            .cert-inner { padding: 28px 22px 0; }
            .cert-inner::before { top: 14px; left: 14px; right: 14px; bottom: 14px; }
            .cert-header { flex-direction: column; align-items: flex-start; gap: 16px; padding-bottom: 20px; }
            .cert-ref { text-align: left; }
            .cert-title h2 { font-size: 24px; }
            .school-name-block .name { font-size: 22px; }
            .school-code-block { padding: 18px 20px; }
            .school-code-block .sc-value { font-size: 26px; letter-spacing: 4px; }
            .details { grid-template-columns: 1fr; gap: 10px; }
            .detail-item { flex-direction: column; align-items: flex-start; gap: 4px; }
            .detail-item .val { text-align: left; }
            .cert-footer { flex-direction: column; padding: 22px 0 26px; }
            .seal { order: -1; }
            .signature-image { max-width: 150px; }
            .cert-bottom { padding: 14px 22px; font-size: 11.5px; flex-direction: column; text-align: center; }
            .action-bar { top: 12px; right: 12px; left: 12px; justify-content: center; }
            .action-btn { padding: 10px 14px; font-size: 12.5px; }
        }

        /* ===== PRINT STYLES ===== */
        @media print {
            @page {
                size: A4 portrait;
                margin: 10mm;
            }
            body {
                background: #ffffff;
                padding: 0;
                display: block;
            }
            .action-bar {
                display: none !important;
            }
            .certificate {
                box-shadow: none;
                border-radius: 0;
                max-width: 100%;
            }
            .cert-watermark {
                color: rgba(37, 99, 235, 0.06);
            }
            .cert-strip {
                display: none;
            }
            .signature-image {
                filter: none !important;
                mix-blend-mode: multiply !important;
            }
            .seal {
                box-shadow: none;
            }
        }
    </style>
</head>
<body>

<!-- ===== ACTION BAR ===== -->
<div class="action-bar">
    <button class="action-btn primary" onclick="window.print()">
        <i class="fas fa-print"></i> Print / Save as PDF
    </button>

    <?php if (!empty($s['school_code'])): ?>
        <button class="action-btn copy-btn" onclick="copyCode('<?= htmlspecialchars($s['school_code']); ?>')">
            <i class="fas fa-copy"></i> Copy Code
        </button>
    <?php endif; ?>

    <a class="action-btn email"
       href="mailto:<?= htmlspecialchars($s['email']); ?>?subject=<?= rawurlencode('Your School Registration Certificate — ' . $s['name']); ?>&body=<?= rawurlencode(
            "Dear " . $s['admin_name'] . ",\n\n" .
            "Please find your school's official registration certificate attached.\n\n" .
            "School: " . $s['name'] . "\n" .
            "School Code: " . ($s['school_code'] ?? '—') . "\n" .
            "Reference: EB-SCH-" . str_pad($s['id'], 6, '0', STR_PAD_LEFT) . "\n\n" .
            "Save the School Code. You will need it — together with your Email and Phone Number —\n" .
            "to register your school account on Educ Bridge.\n\n" .
            "Thank you for registering with Educ Bridge.\n\n" .
            "— Educ Bridge Team"
       ); ?>">
        <i class="fas fa-envelope"></i> Send via Email
    </a>

    <a class="action-btn whatsapp"
       href="https://wa.me/?text=<?= rawurlencode(
            "*Educ Bridge — School Registration Certificate*\n\n" .
            "Hello " . $s['admin_name'] . ",\n\n" .
            "Your school has been successfully registered.\n\n" .
            "*School Information:*\n" .
            "• Name: " . $s['name'] . "\n" .
            "• *School Code: " . ($s['school_code'] ?? '—') . "*\n" .
            "• Ref: EB-SCH-" . str_pad($s['id'], 6, '0', STR_PAD_LEFT) . "\n" .
            "• Type: " . ucfirst($s['type']) . "\n" .
            "• Location: " . $s['province'] . ", " . $s['district'] . "\n" .
            "• Country: " . $s['country'] . "\n" .
            "• Email: " . $s['email'] . "\n" .
            "• Phone: " . $s['phone'] . "\n" .
            "• Status: " . ucfirst($s['status']) . "\n\n" .
            "📌 Save your *School Code* — you'll need it, along with your Email and Phone, to register your school account.\n\n" .
            "Thank you for choosing Educ Bridge.\n" .
            "— Educ Bridge Team"
       ); ?>"
       target="_blank">
        <i class="fab fa-whatsapp"></i> Share on WhatsApp
    </a>

    <a class="action-btn" href="admin-schools.php">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>

<!-- ===== CERTIFICATE ===== -->
<div class="certificate">

    <!-- Top gradient strip -->
    <div class="cert-strip"></div>

    <!-- Watermark -->
    <div class="cert-watermark">EDUC BRIDGE</div>

    <div class="cert-inner">

        <!-- ===== HEADER ===== -->
        <div class="cert-header">

            <div class="cert-brand">
                <div class="cert-brand-logo">
                    <img src="logo.png" alt="Educ Bridge">
                </div>
                <div class="cert-brand-text">
                    <h1>Educ<span>Bridge</span></h1>
                    <p>School Management System</p>
                </div>
            </div>

            <div class="cert-ref">
                <div class="cert-ref-label">Reference No.</div>
                <div class="cert-ref-value">
                    EB-SCH-<?= str_pad($s['id'], 6, '0', STR_PAD_LEFT); ?>
                </div>
                <div class="cert-ref-sub">
                    Issued <?= date('F j, Y', strtotime($s['created_at'] ?? 'now')); ?>
                </div>
            </div>

        </div>

        <!-- ===== TITLE ===== -->
        <div class="cert-title">
            <div class="cert-title-badge">
                <i class="fas fa-certificate"></i>
                Official Document
            </div>
            <h2>Certificate of <span>School Registration</span></h2>
            <p>This document certifies that the following school has been successfully registered on the Educ Bridge platform.</p>
        </div>

        <!-- ===== SCHOOL NAME ===== -->
        <div class="school-name-block">
            <div class="label">Registered To</div>
            <div class="name"><?= htmlspecialchars($s['name']); ?></div>
            <span class="underline"></span>
        </div>

        <!-- ===== SCHOOL CODE HIGHLIGHT ===== -->
        <?php if (!empty($s['school_code'])): ?>
            <div class="school-code-block">
                <div class="sc-label">School Code</div>
                <div class="sc-value"><?= htmlspecialchars($s['school_code']); ?></div>
                <p class="sc-note">
                    Use this code together with your <strong>Email</strong> and <strong>Phone Number</strong>
                    to register your school account on Educ Bridge.
                </p>
            </div>
        <?php endif; ?>

        <!-- ===== DETAILS ===== -->
        <div class="details">

            <div class="detail-item">
                <span class="lbl"><i class="fas fa-user-tie"></i> Administrator</span>
                <span class="val"><?= htmlspecialchars($s['admin_name']); ?></span>
            </div>

            <div class="detail-item">
                <span class="lbl"><i class="fas fa-tag"></i> School Type</span>
                <span class="val"><?= ucfirst($s['type']); ?></span>
            </div>

            <div class="detail-item">
                <span class="lbl"><i class="fas fa-globe"></i> Country</span>
                <span class="val"><?= htmlspecialchars($s['country']); ?></span>
            </div>

            <div class="detail-item">
                <span class="lbl"><i class="fas fa-map"></i> Province / Region</span>
                <span class="val"><?= htmlspecialchars($s['province']); ?></span>
            </div>

            <div class="detail-item">
                <span class="lbl"><i class="fas fa-map-marker-alt"></i> District</span>
                <span class="val"><?= htmlspecialchars($s['district']); ?></span>
            </div>

            <div class="detail-item">
                <span class="lbl"><i class="fas fa-envelope"></i> Email</span>
                <span class="val"><?= htmlspecialchars($s['email']); ?></span>
            </div>

            <div class="detail-item">
                <span class="lbl"><i class="fas fa-phone"></i> Phone</span>
                <span class="val mono"><?= htmlspecialchars($s['phone']); ?></span>
            </div>

            <div class="detail-item">
                <span class="lbl"><i class="fas fa-info-circle"></i> Status</span>
                <span class="val">
                    <span class="status-pill <?= $s['status'] === 'active' ? 'active' : 'deactivated'; ?>">
                        <i class="fas fa-circle"></i>
                        <?= ucfirst($s['status']); ?>
                    </span>
                </span>
            </div>

        </div>

        <!-- ===== FOOTER: SIGNATURE + SEAL ===== -->
        <div class="cert-footer">

            <div class="signature">
                <img src="CDO.jpg" alt="Authorized Signature" class="signature-image">
                <div class="signature-line"></div>
                <div class="signature-name">Educ Bridge Admin</div>
                <div class="signature-role">Authorized Signature</div>
            </div>

            <div class="seal">
                <img src="logo.png" alt="Educ Bridge Official Seal">
            </div>

            <div class="signature">
                <div class="signature-line"></div>
                <div class="signature-name"><?= date('F j, Y'); ?></div>
                <div class="signature-role">Date Issued</div>
            </div>

        </div>

    </div>

    <!-- ===== BOTTOM BAR ===== -->
    <div class="cert-bottom">
        <div class="cert-bottom-left">
            <i class="fas fa-shield-alt"></i>
            <span>Powered by <strong>Educ Bridge School Management System</strong></span>
        </div>
        <div class="cert-bottom-right">
            EB-SCH-<?= str_pad($s['id'], 6, '0', STR_PAD_LEFT); ?> · Educ Bridge © <?= date('Y'); ?>
        </div>
    </div>

</div>

<script>
    function copyCode(code) {
        if (!code) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(code).then(() => {
                showCopyFeedback();
            }).catch(() => fallbackCopy(code));
        } else {
            fallbackCopy(code);
        }
    }

    function fallbackCopy(text) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); showCopyFeedback(); } catch (e) {}
        document.body.removeChild(ta);
    }

    function showCopyFeedback() {
        const btn = event.target.closest('.copy-btn');
        if (btn) {
            const original = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
            setTimeout(() => { btn.innerHTML = original; }, 1500);
        }
    }
</script>

</body>
</html>