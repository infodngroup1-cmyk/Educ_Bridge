<?php
session_start();
require_once 'db.php';

if (empty($_SESSION['school_id']) || empty($_SESSION['school_verified'])) {
    header("Location: school-login.php");
    exit;
}

$school_id = (int) $_SESSION['school_id'];

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    header("Location: school-subjects.php?error=" . urlencode("Invalid subject ID."));
    exit;
}

// Verify subject belongs to this school
$stmt = mysqli_prepare($conn, "SELECT id, subject_name, level FROM subjects WHERE id = ? AND school_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "ii", $id, $school_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($res) === 0) {
    header("Location: school-subjects.php?error=" . urlencode("Subject not found or doesn't belong to your school."));
    exit;
}

$subject = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

// DELETE
$del = mysqli_prepare($conn, "DELETE FROM subjects WHERE id = ? AND school_id = ? LIMIT 1");
mysqli_stmt_bind_param($del, "ii", $id, $school_id);

if (mysqli_stmt_execute($del)) {
    $affected = mysqli_stmt_affected_rows($del);
    mysqli_stmt_close($del);

    if ($affected > 0) {
        @file_put_contents(
            __DIR__ . '/subject_delete.log',
            "[" . date('Y-m-d H:i:s') . "] School #{$school_id} deleted subject #{$id} ({$subject['subject_name']} · {$subject['level']})\n",
            FILE_APPEND
        );
        header("Location: school-subjects.php?deleted=1");
        exit;
    } else {
        header("Location: school-subjects.php?error=" . urlencode("Subject could not be deleted."));
        exit;
    }
} else {
    $err = mysqli_error($conn);
    mysqli_stmt_close($del);
    header("Location: school-subjects.php?error=" . urlencode("Database error: " . $err));
    exit;
}