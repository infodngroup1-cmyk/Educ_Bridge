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
| GET PARAMETERS
|--------------------------------------------------------------------------
*/
$id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$action = $_GET['action'] ?? '';

if ($id <= 0) {
    header("Location: school-classes.php?error=" . urlencode("Invalid class ID."));
    exit;
}

if (!in_array($action, ['activate', 'deactivate'], true)) {
    header("Location: school-classes.php?error=" . urlencode("Invalid action."));
    exit;
}

/*
|--------------------------------------------------------------------------
| VERIFY CLASS BELONGS TO THIS SCHOOL
|--------------------------------------------------------------------------
*/
$stmt = mysqli_prepare($conn, "SELECT id, class_name, status FROM classes WHERE id = ? AND school_id = ? LIMIT 1");
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
| IF ALREADY IN THE DESIRED STATE, JUST REDIRECT
|--------------------------------------------------------------------------
*/
$target_status = ($action === 'activate') ? 'active' : 'inactive';

if ($class['status'] === $target_status) {
    $msg = ($target_status === 'active')
         ? "This class is already active."
         : "This class is already inactive.";
    header("Location: school-classes.php?error=" . urlencode($msg));
    exit;
}

/*
|--------------------------------------------------------------------------
| UPDATE STATUS
|--------------------------------------------------------------------------
*/
$upd = mysqli_prepare($conn, "UPDATE classes SET status = ? WHERE id = ? AND school_id = ? LIMIT 1");
mysqli_stmt_bind_param($upd, "sii", $target_status, $id, $school_id);

if (mysqli_stmt_execute($upd)) {
    $affected = mysqli_stmt_affected_rows($upd);
    mysqli_stmt_close($upd);

    if ($affected > 0) {

        // Optional: log the change
        @file_put_contents(
            __DIR__ . '/class_toggle.log',
            "[" . date('Y-m-d H:i:s') . "] School #{$school_id} set class #{$id} ({$class['class_name']}) to {$target_status}\n",
            FILE_APPEND
        );

        $redirect_flag = ($target_status === 'active') ? 'activated=1' : 'deactivated=1';
        header("Location: school-classes.php?{$redirect_flag}");
        exit;

    } else {
        header("Location: school-classes.php?error=" . urlencode("Status could not be changed."));
        exit;
    }
} else {
    $err = mysqli_error($conn);
    mysqli_stmt_close($upd);
    header("Location: school-classes.php?error=" . urlencode("Database error: " . $err));
    exit;
}