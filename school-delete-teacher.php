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
| GET TEACHER ID
|--------------------------------------------------------------------------
*/
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    header("Location: school-teachers.php?error=" . urlencode("Invalid teacher ID."));
    exit;
}

/*
|--------------------------------------------------------------------------
| VERIFY TEACHER BELONGS TO THIS SCHOOL + GET NAME (for the log)
|--------------------------------------------------------------------------
*/
$stmt = mysqli_prepare($conn, "SELECT id, name, email FROM teachers WHERE id = ? AND school_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "ii", $id, $school_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($res) === 0) {
    header("Location: school-teachers.php?error=" . urlencode("Teacher not found or doesn't belong to your school."));
    exit;
}

$teacher = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

/*
|--------------------------------------------------------------------------
| DELETE (school-scoped so no cross-school deletion possible)
|--------------------------------------------------------------------------
*/
$del = mysqli_prepare($conn, "DELETE FROM teachers WHERE id = ? AND school_id = ? LIMIT 1");
mysqli_stmt_bind_param($del, "ii", $id, $school_id);

if (mysqli_stmt_execute($del)) {
    $affected = mysqli_stmt_affected_rows($del);
    mysqli_stmt_close($del);

    if ($affected > 0) {
        // Optional: append to a small log file
        @file_put_contents(
            __DIR__ . '/teacher_delete.log',
            "[" . date('Y-m-d H:i:s') . "] School #{$school_id} deleted teacher #{$id} ({$teacher['name']} · {$teacher['email']})\n",
            FILE_APPEND
        );

        header("Location: school-teachers.php?deleted=1");
        exit;
    } else {
        header("Location: school-teachers.php?error=" . urlencode("Teacher could not be deleted."));
        exit;
    }
} else {
    $err = mysqli_error($conn);
    mysqli_stmt_close($del);
    header("Location: school-teachers.php?error=" . urlencode("Database error: " . $err));
    exit;
}