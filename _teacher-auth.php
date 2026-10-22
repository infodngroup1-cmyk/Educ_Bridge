<?php
/*
|--------------------------------------------------------------------------
| SHARED TEACHER AUTH GUARD
|--------------------------------------------------------------------------
| Usage in any teacher page:
|
|   require_once 'db.php';
|   require_once '_teacher-auth.php';
|
| After these two lines:
|   - Session is started
|   - User is guaranteed logged in (or redirected)
|   - These variables are available:
|       $teacher_id, $teacher_name, $teacher_email, $teacher_phone,
|       $school_id, $school_name, $school_code, $initials, $short_school
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* Public auth pages — never redirect away from these */
$__public_pages = [
    'teacher-login.php',
    'teacher-register.php',
    'teacher-verify.php',
    'teacher-forgot-password.php',
    'teacher-reset-verify.php',
    'teacher-reset-password.php',
    'teacher-logout.php',
];
$__current = basename($_SERVER['PHP_SELF'] ?? '');

/* Validate: teacher_id AND teacher_verified must BOTH be set */
$__has_id       = !empty($_SESSION['teacher_id']);
$__has_verified = !empty($_SESSION['teacher_verified']);

if ($__has_id && $__has_verified) {
    /* Fully logged in */
    $teacher_id    = (int) $_SESSION['teacher_id'];
    $teacher_name  = $_SESSION['teacher_name']  ?? 'Teacher';
    $teacher_email = $_SESSION['teacher_email'] ?? '';
    $teacher_phone = $_SESSION['teacher_phone'] ?? '';
    $school_id     = (int) ($_SESSION['teacher_school_id'] ?? 0);
    $school_name   = $_SESSION['teacher_school_name'] ?? 'School';
    $school_code   = $_SESSION['teacher_school_code'] ?? '';
} else {
    /* Not fully logged in — self-heal partial sessions to avoid loops */
    if ($__has_id || $__has_verified) {
        unset(
            $_SESSION['teacher_id'],
            $_SESSION['teacher_name'],
            $_SESSION['teacher_email'],
            $_SESSION['teacher_phone'],
            $_SESSION['teacher_school_id'],
            $_SESSION['teacher_school_name'],
            $_SESSION['teacher_school_code'],
            $_SESSION['teacher_verified']
        );
    }

    /* Only redirect if this is a protected page */
    if (!in_array($__current, $__public_pages, true)) {
        header("Location: teacher-login.php");
        exit;
    }

    /* On public pages, expose safe defaults */
    $teacher_id    = 0;
    $teacher_name  = 'Teacher';
    $teacher_email = '';
    $teacher_phone = '';
    $school_id     = 0;
    $school_name   = 'School';
    $school_code   = '';
}

/* Shared initials + short school name */
$initials = "";
foreach (explode(" ", trim($teacher_name)) as $__part) {
    if ($__part !== "") {
        $initials .= strtoupper($__part[0]);
        if (strlen($initials) >= 2) break;
    }
}
if ($initials === "") $initials = "T";

$short_school = mb_strimwidth($school_name, 0, 24, '…');