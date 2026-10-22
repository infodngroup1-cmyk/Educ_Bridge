<?php
session_start();
require_once 'db.php';

if (empty($_SESSION['school_id']) || empty($_SESSION['school_verified'])) {
    header("Location: school-login.php");
    exit;
}

$school_id = (int) $_SESSION['school_id'];

$id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$action = $_GET['action'] ?? '';

if ($id <= 0) {
    header("Location: school-subjects.php?error=" . urlencode("Invalid subject ID."));
    exit;
}
if (!in_array($action, ['activate', 'deactivate'], true)) {
    header("Location: school-subjects.php?error=" . urlencode("Invalid action."));
    exit;
}

// Verify subject belongs to this school
$stmt = mysqli_prepare($conn, "SELECT id, subject_name, status FROM subjects WHERE id = ? AND school_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "ii", $id, $school_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($res) === 0) {
    header("Location: school-subjects.php?error=" . urlencode("Subject not found or doesn't belong to your school."));
    exit;
}

$subject = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

$target_status = ($action === 'activate') ? 'active' : 'inactive';

if ($subject['status'] === $target_status) {
    $msg = ($target_status === 'active')
         ? "This subject is already active."
         : "This subject is already inactive.";
    header("Location: school-subjects.php?error=" . urlencode($msg));
    exit;
}

$upd = mysqli_prepare($conn, "UPDATE subjects SET status = ? WHERE id = ? AND school_id = ? LIMIT 1");
mysqli_stmt_bind_param($upd, "sii", $target_status, $id, $school_id);

if (mysqli_stmt_execute($upd)) {
    $affected = mysqli_stmt_affected_rows($upd);
    mysqli_stmt_close($upd);

    if ($affected > 0) {
        @file_put_contents(
            __DIR__ . '/subject_toggle.log',
            "[" . date('Y-m-d H:i:s') . "] School #{$school_id} set subject #{$id} ({$subject['subject_name']}) to {$target_status}\n",
            FILE_APPEND
        );

        $flag = ($target_status === 'active') ? 'activated=1' : 'deactivated=1';
        header("Location: school-subjects.php?{$flag}");
        exit;
    } else {
        header("Location: school-subjects.php?error=" . urlencode("Status could not be changed."));
        exit;
    }
} else {
    $err = mysqli_error($conn);
    mysqli_stmt_close($upd);
    header("Location: school-subjects.php?error=" . urlencode("Database error: " . $err));
    exit;
}