<?php
/*
|--------------------------------------------------------------------------
| SHARED SCHOOL AUTH GUARD
|--------------------------------------------------------------------------
| Usage at the top of any school page:
|
|   require_once 'db.php';
|   require_once '_school-auth.php';
|
| After these two lines:
|   - Session is started
|   - User is guaranteed logged in (or redirected to school-login.php)
|   - These variables are available:
|       $school_id, $school_name, $school_admin, $school_code,
|       $initials, $short_school
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* Pages that must NEVER be redirected away from (public auth pages) */
$__public_pages = [
    'school-login.php',
    'school-register.php',
    'school-verify.php',
    'school-forgot-password.php',
    'school-reset-verify.php',
    'school-reset-password.php',
    'school-logout.php',
    'online-registration.php',
    'online-registration-apply.php',
];
$__current = basename($_SERVER['PHP_SELF'] ?? '');

/* Validate: school_id AND school_verified must BOTH be set */
$__has_id       = !empty($_SESSION['school_id']);
$__has_verified = !empty($_SESSION['school_verified']);

if ($__has_id && $__has_verified) {
    /* -------- Fully logged in -------- */
    $school_id    = (int) $_SESSION['school_id'];
    $school_name  = $_SESSION['school_name']  ?? 'School';
    $school_admin = $_SESSION['school_admin'] ?? 'Administrator';
    $school_code  = $_SESSION['school_code']  ?? '';
} else {
    /* -------- Not fully logged in -------- */

    /* Self-heal: if ONE flag is set but not both, the session is stale.
       Wipe school keys so we don't bounce between login and dashboard. */
    if ($__has_id || $__has_verified) {
        unset(
            $_SESSION['school_id'],
            $_SESSION['school_name'],
            $_SESSION['school_admin'],
            $_SESSION['school_email'],
            $_SESSION['school_code'],
            $_SESSION['school_verified']
        );
    }

    /* Only redirect if this is a protected page */
    if (!in_array($__current, $__public_pages, true)) {
        header("Location: school-login.php");
        exit;
    }

    /* On public pages, expose safe defaults */
    $school_id    = 0;
    $school_name  = 'School';
    $school_admin = 'Administrator';
    $school_code  = '';
}

/* Shared initials + short school name */
$initials = "";
foreach (explode(" ", trim($school_admin)) as $__part) {
    if ($__part !== "") {
        $initials .= strtoupper($__part[0]);
        if (strlen($initials) >= 2) break;
    }
}
if ($initials === "") $initials = "S";

$short_school = mb_strimwidth($school_name, 0, 24, '…');