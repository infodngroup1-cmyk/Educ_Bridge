<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (empty($_SESSION['school_id']) || empty($_SESSION['school_verified'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$school_id = (int) $_SESSION['school_id'];
$ann_id    = isset($_POST['announcement_id']) ? (int)$_POST['announcement_id'] : 0;
$origin    = $_POST['origin'] ?? 'local';

if ($ann_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid ID']);
    exit;
}

$updated = 0;
$errors  = [];

/* ---------- ADMIN (platform) announcement ---------- */
if ($origin === 'admin') {
    $chk = @mysqli_query($conn, "SHOW TABLES LIKE 'admin_announcement_recipients'");
    if ($chk && mysqli_num_rows($chk) > 0) {
        $stmt = mysqli_prepare($conn, "
            UPDATE admin_announcement_recipients
            SET is_read = 1, read_at = NOW()
            WHERE announcement_id = ?
              AND recipient_type = 'school'
              AND recipient_id = ?
              AND is_read = 0
        ");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ii", $ann_id, $school_id);
            if (mysqli_stmt_execute($stmt)) {
                $updated = mysqli_stmt_affected_rows($stmt);
            } else {
                $errors[] = mysqli_stmt_error($stmt);
            }
            mysqli_stmt_close($stmt);
        } else {
            $errors[] = mysqli_error($conn);
        }
    } else {
        $errors[] = 'admin_announcement_recipients table missing';
    }
}
/* ---------- LOCAL school announcement ---------- */
else {
    $stmt = mysqli_prepare($conn, "
        UPDATE school_announcement_recipients
        SET is_read = 1, read_at = NOW()
        WHERE announcement_id = ?
          AND school_id = ?
          AND is_read = 0
    ");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ii", $ann_id, $school_id);
        if (mysqli_stmt_execute($stmt)) {
            $updated = mysqli_stmt_affected_rows($stmt);
        } else {
            $errors[] = mysqli_stmt_error($stmt);
        }
        mysqli_stmt_close($stmt);
    } else {
        $errors[] = mysqli_error($conn);
    }
}

if (!empty($errors)) {
    echo json_encode([
        'ok'      => false,
        'error'   => implode(' | ', $errors),
        'id'      => $ann_id,
        'origin'  => $origin
    ]);
    exit;
}

if ($updated === 0) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Nothing updated — this announcement may already be read or does not belong to your school.',
        'id'    => $ann_id,
        'origin'=> $origin
    ]);
    exit;
}

echo json_encode([
    'ok'      => true,
    'updated' => $updated,
    'id'      => $ann_id,
    'origin'  => $origin
]);