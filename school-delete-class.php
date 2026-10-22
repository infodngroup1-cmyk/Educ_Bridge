<?php
session_start();
require_once 'db.php';

/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['school_id']) || empty($_SESSION['school_verified'])) {
    header("Location: school-login.php");
    exit;
}

$school_id = (int) $_SESSION['school_id'];

/*
|--------------------------------------------------------------------------
| GET CLASS ID
|--------------------------------------------------------------------------
*/
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    header("Location: school-classes.php?error=" . urlencode("Invalid class ID."));
    exit;
}

/*
|--------------------------------------------------------------------------
| VERIFY CLASS BELONGS TO THIS SCHOOL
|--------------------------------------------------------------------------
*/
$stmt = mysqli_prepare($conn, "SELECT id, class_name, academic_year FROM classes WHERE id = ? AND school_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "ii", $id, $school_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($res) === 0) {
    header("Location: school-classes.php?error=" . urlencode("Class not found or doesn't belong to your school."));
    exit;
}

$class = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

/*
|--------------------------------------------------------------------------
| DELETE (school-scoped)
|--------------------------------------------------------------------------
*/
$del = mysqli_prepare($conn, "DELETE FROM classes WHERE id = ? AND school_id = ? LIMIT 1");
mysqli_stmt_bind_param($del, "ii", $id, $school_id);

if (mysqli_stmt_execute($del)) {
    $affected = mysqli_stmt_affected_rows($del);
    mysqli_stmt_close($del);

    if ($affected > 0) {
        @file_put_contents(
            __DIR__ . '/class_delete.log',
            "[" . date('Y-m-d H:i:s') . "] School #{$school_id} deleted class #{$id} ({$class['class_name']} · {$class['academic_year']})\n",
            FILE_APPEND
        );

        header("Location: school-classes.php?deleted=1");
        exit;
    } else {
        header("Location: school-classes.php?error=" . urlencode("Class could not be deleted."));
        exit;
    }
} else {
    $err = mysqli_error($conn);
    mysqli_stmt_close($del);
    header("Location: school-classes.php?error=" . urlencode("Database error: " . $err));
    exit;
}