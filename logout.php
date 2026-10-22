<?php

session_start();

// Clear all session variables
$_SESSION = [];

// Destroy the session
session_destroy();

// Redirect to admin login page
header("Location: admin-login.php");
exit;