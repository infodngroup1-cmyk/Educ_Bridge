<?php
session_start();
require_once 'db.php';

if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_verified'])) {
    header("Location: admin-login.php");
    exit;
}

$admin_id   = (int) $_SESSION['admin_id'];
$admin_name = $_SESSION['admin_name'] ?? 'System Admin';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: admin-announcements.php");
    exit;
}

/* ---------- INPUT ---------- */
$title      = trim($_POST['title']  ?? '');
$body       = trim($_POST['body']   ?? '');
$audience   = $_POST['audience']    ?? 'schools'; // schools | parents | all
$priority   = $_POST['priority']    ?? 'normal';
$scope_type = $_POST['scope_type']  ?? 'all';     // all | specific
$scope_sch  = (int)($_POST['scope_school_id'] ?? 0);
$scope_par  = (int)($_POST['scope_parent_id'] ?? 0);

/* ---------- VALIDATE ---------- */
$errors = [];
if ($title === '' || mb_strlen($title) < 2) $errors[] = "Title is required.";
if ($body  === '' || mb_strlen($body)  < 2) $errors[] = "Message is required.";
if (!in_array($audience, ['schools','parents','all'], true)) $audience = 'schools';
if (!in_array($priority, ['normal','high','urgent'], true))  $priority = 'normal';
if (!in_array($scope_type, ['all','specific'], true))        $scope_type = 'all';

if ($scope_type === 'specific' && $scope_sch <= 0 && $scope_par <= 0) {
    $errors[] = "Please choose a specific recipient.";
}

if (!empty($errors)) {
    header("Location: admin-announcements.php?error=" . urlencode(implode(' ', $errors)));
    exit;
}

/* ---------- DETECT COLUMN NAMES ---------- */
$school_name_col = 'school_name';
foreach (['school_name','name','title','school'] as $c) {
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM schools LIKE '" . mysqli_real_escape_string($conn, $c) . "'");
    if ($r && mysqli_num_rows($r) > 0) { $school_name_col = $c; break; }
}

$school_has_status = false;
$r = @mysqli_query($conn, "SHOW COLUMNS FROM schools LIKE 'status'");
if ($r && mysqli_num_rows($r) > 0) $school_has_status = true;

$parents_exists = false;
$r = @mysqli_query($conn, "SHOW TABLES LIKE 'parents'");
if ($r && mysqli_num_rows($r) > 0) $parents_exists = true;

$parent_name_col = 'name';
if ($parents_exists) {
    foreach (['name','full_name','parent_name'] as $c) {
        $r = @mysqli_query($conn, "SHOW COLUMNS FROM parents LIKE '" . mysqli_real_escape_string($conn, $c) . "'");
        if ($r && mysqli_num_rows($r) > 0) { $parent_name_col = $c; break; }
    }
}

/* ---------- RESOLVE RECIPIENTS ---------- */
$recipients = [];

/* Schools */
if ($audience === 'schools' || $audience === 'all') {
    if ($scope_type === 'specific' && $scope_sch > 0) {
        $stmt = mysqli_prepare($conn, "SELECT id, `{$school_name_col}` AS name FROM schools WHERE id = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $scope_sch);
            mysqli_stmt_execute($stmt);
            $r = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($r)) {
                $recipients[] = [
                    'type' => 'school',
                    'id'   => (int)$row['id'],
                    'name' => $row['name'] ?: '(Unnamed school)'
                ];
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        $status_sql = $school_has_status ? " WHERE status = 'active' " : "";
        $r = mysqli_query($conn, "SELECT id, `{$school_name_col}` AS name FROM schools {$status_sql} ORDER BY `{$school_name_col}` ASC");
        if ($r) {
            while ($row = mysqli_fetch_assoc($r)) {
                $recipients[] = [
                    'type' => 'school',
                    'id'   => (int)$row['id'],
                    'name' => $row['name'] ?: '(Unnamed school)'
                ];
            }
        }
    }
}

/* Parents */
if ($parents_exists && ($audience === 'parents' || $audience === 'all')) {
    if ($scope_type === 'specific' && $scope_par > 0) {
        $stmt = mysqli_prepare($conn, "SELECT id, `{$parent_name_col}` AS name FROM parents WHERE id = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $scope_par);
            mysqli_stmt_execute($stmt);
            $r = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($r)) {
                $recipients[] = [
                    'type' => 'parent',
                    'id'   => (int)$row['id'],
                    'name' => $row['name'] ?: '(Unnamed parent)'
                ];
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        $r = mysqli_query($conn, "SELECT id, `{$parent_name_col}` AS name FROM parents ORDER BY `{$parent_name_col}` ASC");
        if ($r) {
            while ($row = mysqli_fetch_assoc($r)) {
                $recipients[] = [
                    'type' => 'parent',
                    'id'   => (int)$row['id'],
                    'name' => $row['name'] ?: '(Unnamed parent)'
                ];
            }
        }
    }
}

/* If scope=specific and a school was chosen but audience was parents/all,
   constrain to just that school (audience='schools'). Same for parent. */
if ($scope_type === 'specific') {
    if ($scope_sch > 0)      $audience = 'schools';
    elseif ($scope_par > 0)  $audience = 'parents';
}

/* Deduplicate by type:id */
$seen = [];
$unique = [];
foreach ($recipients as $rec) {
    $k = $rec['type'] . ':' . $rec['id'];
    if (isset($seen[$k])) continue;
    $seen[$k] = true;
    $unique[] = $rec;
}
$recipients = $unique;

if (empty($recipients)) {
    header("Location: admin-announcements.php?error=" . urlencode("No recipients matched your selection."));
    exit;
}

/* ---------- INSERT ---------- */
mysqli_begin_transaction($conn);
try {
    $total = count($recipients);

    $scope_school_val = ($scope_type === 'specific' && $scope_sch > 0) ? $scope_sch : null;
    $scope_parent_val = ($scope_type === 'specific' && $scope_par > 0) ? $scope_par : null;

    /* Map JS values to DB ENUM */
    $scope_type_db = 'all';
    if ($scope_type === 'specific') {
        if ($scope_school_val)      $scope_type_db = 'school';
        elseif ($scope_parent_val)  $scope_type_db = 'parent';
    }

    $ins = mysqli_prepare($conn, "
        INSERT INTO admin_announcements
            (admin_id, sender_name, title, body, priority,
             audience, scope_type, scope_school_id, scope_parent_id, total_recipients)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$ins) throw new Exception("Insert prepare failed: " . mysqli_error($conn));

    mysqli_stmt_bind_param(
        $ins, "isssssssii",
        $admin_id, $admin_name, $title, $body, $priority,
        $audience, $scope_type_db, $scope_school_val, $scope_parent_val, $total
    );
    if (!mysqli_stmt_execute($ins)) throw new Exception("Insert failed: " . mysqli_stmt_error($ins));
    $ann_id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($ins);

    /* Recipients */
    $ins_rec = mysqli_prepare($conn, "
        INSERT INTO admin_announcement_recipients
            (announcement_id, recipient_type, recipient_id, recipient_name)
        VALUES (?, ?, ?, ?)
    ");
    if (!$ins_rec) throw new Exception("Recipient prepare failed: " . mysqli_error($conn));

    foreach ($recipients as $rec) {
        mysqli_stmt_bind_param(
            $ins_rec, "isis",
            $ann_id, $rec['type'], $rec['id'], $rec['name']
        );
        mysqli_stmt_execute($ins_rec);
    }
    mysqli_stmt_close($ins_rec);

    mysqli_commit($conn);

    header("Location: admin-announcements.php?sent=1&n=" . $total);
    exit;

} catch (Exception $e) {
    mysqli_rollback($conn);
    header("Location: admin-announcements.php?error=" . urlencode("Could not send: " . $e->getMessage()));
    exit;
}