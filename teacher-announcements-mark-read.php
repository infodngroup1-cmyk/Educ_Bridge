<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (empty($_SESSION['teacher_id']) || empty($_SESSION['teacher_verified'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$teacher_id = (int) $_SESSION['teacher_id'];
$school_id  = (int) ($_SESSION['teacher_school_id'] ?? 0);

$ann_id = isset($_POST['announcement_id']) ? (int)$_POST['announcement_id'] : 0;
$state  = isset($_POST['state']) ? (int)$_POST['state'] : 1;

if ($ann_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid announcement ID']);
    exit;
}
if ($state !== 0 && $state !== 1) $state = 1;

$stmt = mysqli_prepare($conn, "
    UPDATE school_announcement_recipients
    SET is_read = ?, read_at = " . ($state === 1 ? "NOW()" : "NULL") . "
    WHERE announcement_id = ?
      AND school_id = ?
      AND recipient_type = 'teacher'
      AND recipient_id = ?
");
if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => mysqli_error($conn)]);
    exit;
}

mysqli_stmt_bind_param($stmt, "iiii", $state, $ann_id, $school_id, $teacher_id);
$ok = mysqli_stmt_execute($stmt);
$affected = mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);

if (!$ok) {
    echo json_encode(['ok' => false, 'error' => 'Update failed']);
    exit;
}

if ($affected === 0) {
    echo json_encode([
        'ok'    => false,
        'error' => 'No matching announcement for this teacher (maybe not addressed to you).',
        'id'    => $ann_id,
        'state' => $state
    ]);
    exit;
}

echo json_encode([
    'ok'      => true,
    'state'   => $state,
    'updated' => $affected
]);