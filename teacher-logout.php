<?php
session_start();

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

session_unset();
session_destroy();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

header("Location: index.php");
exit;