<?php
session_start();

unset(
    $_SESSION['school_id'],
    $_SESSION['school_name'],
    $_SESSION['school_admin'],
    $_SESSION['school_email'],
    $_SESSION['school_code'],
    $_SESSION['school_verified']
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

header("Location: school-login.php");
exit;