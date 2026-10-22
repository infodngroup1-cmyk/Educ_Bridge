<?php
session_start();
require_once 'db.php';

if (empty($_SESSION['school_id']) || empty($_SESSION['school_verified'])) {
    header("Location: school-login.php");
    exit;
}

$school_id    = (int) $_SESSION['school_id'];
$school_name  = $_SESSION['school_name']  ?? 'School';
$school_admin = $_SESSION['school_admin'] ?? 'Administrator';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: school-announcements.php");
    exit;
}

/* ---------- INPUT ---------- */
$title         = trim($_POST['title']    ?? '');
$body          = trim($_POST['body']     ?? '');
$audience      = $_POST['audience']      ?? 'all';
$priority      = $_POST['priority']      ?? 'normal';
$scope_type    = $_POST['scope_type']    ?? 'all';
$scope_level   = trim($_POST['scope_level']   ?? '');
$scope_class   = (int)($_POST['scope_class']  ?? 0);
$scope_indiv   = (int)($_POST['scope_individual'] ?? 0);

/* ---------- VALIDATION ---------- */
$errors = [];

if ($title === '' || mb_strlen($title) < 2) $errors[] = "Title is required (min 2 characters).";
if ($body  === '' || mb_strlen($body)  < 2) $errors[] = "Message is required (min 2 characters).";

if (!in_array($audience, ['all','teachers','parents','students','accounts'], true)) {
    $audience = 'all';
}
if (!in_array($priority, ['normal','high','urgent'], true)) $priority = 'normal';
if (!in_array($scope_type, ['all','level','class','individual'], true)) $scope_type = 'all';

if ($audience === 'all') $scope_type = 'all';

if ($scope_type === 'level' && $scope_level === '') $errors[] = "Please choose a level.";
if ($scope_type === 'class' && $scope_class <= 0)   $errors[] = "Please choose a class.";
if ($scope_type === 'individual' && $scope_indiv <= 0) $errors[] = "Please choose a person.";

if (!empty($errors)) {
    header("Location: school-announcements.php?error=" . urlencode(implode(' ', $errors)));
    exit;
}

/* ---------- RESOLVE RECIPIENTS ---------- */
/*
| recipient_type: teacher | parent | student | account
| recipient_id:   matches the respective table's PK
|
| We resolve against existing tables in your DB. For parents and students
| you'll adapt the SQL below once those tables are named.
*/
$recipients = []; // each entry: ['type'=>, 'id'=>, 'name'=>]

$resolve_teachers = function($scopeType, $scopeLevel, $scopeClass, $scopeIndiv) use ($conn, $school_id) {
    $where  = ["school_id = ?", "status = 'active'"];
    $params = [$school_id];
    $types  = "i";

    if ($scopeType === 'individual' && $scopeIndiv > 0) {
        $where[] = "id = ?";
        $params[] = $scopeIndiv; $types .= "i";
    } elseif ($scopeType === 'level' && $scopeLevel !== '') {
        // teaching_level column stores a comma list of level enums / keys
        $where[] = "teaching_level LIKE ?";
        $params[] = "%{$scopeLevel}%"; $types .= "s";
    } elseif ($scopeType === 'class' && $scopeClass > 0) {
        // Teachers who teach this class via subject_class_teachers
        $where[] = "EXISTS (
            SELECT 1 FROM subject_class_teachers sct
            WHERE sct.school_id = teachers.school_id
              AND sct.teacher_id = teachers.id
              AND sct.class_id   = ?
        )";
        $params[] = $scopeClass; $types .= "i";
    }

    $sql = "SELECT id, name FROM teachers WHERE " . implode(' AND ', $where) . " ORDER BY name ASC";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $out = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $out[] = ['type' => 'teacher', 'id' => (int)$r['id'], 'name' => $r['name']];
    }
    mysqli_stmt_close($stmt);
    return $out;
};

$resolve_students = function($scopeType, $scopeLevel, $scopeClass, $scopeIndiv) use ($conn, $school_id) {
    // Adjust column names to match your students table
    $sql_base = "SELECT id, name FROM students WHERE school_id = ? AND status = 'active'";
    $params   = [$school_id];
    $types    = "i";

    if ($scopeType === 'individual' && $scopeIndiv > 0) {
        $sql_base .= " AND id = ?";
        $params[] = $scopeIndiv; $types .= "i";
    } elseif ($scopeType === 'class' && $scopeClass > 0) {
        $sql_base .= " AND class_id = ?";
        $params[] = $scopeClass; $types .= "i";
    } elseif ($scopeType === 'level' && $scopeLevel !== '') {
        // Join classes to know level
        $sql_base = "
            SELECT s.id, s.name FROM students s
            INNER JOIN classes c ON c.id = s.class_id
            WHERE s.school_id = ? AND s.status = 'active' AND c.level = ?
        ";
        $params = [$school_id, $scopeLevel];
        $types  = "is";
    }

    $stmt = mysqli_prepare($conn, $sql_base);
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $out = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $out[] = ['type' => 'student', 'id' => (int)$r['id'], 'name' => $r['name']];
    }
    mysqli_stmt_close($stmt);
    return $out;
};

$resolve_parents = function($scopeType, $scopeLevel, $scopeClass, $scopeIndiv) use ($conn, $school_id) {
    // Adjust column names to match your parents table
    $sql_base = "SELECT id, name FROM parents WHERE school_id = ? AND status = 'active'";
    $params   = [$school_id];
    $types    = "i";

    if ($scopeType === 'individual' && $scopeIndiv > 0) {
        $sql_base .= " AND id = ?";
        $params[] = $scopeIndiv; $types .= "i";
    } elseif ($scopeType === 'class' && $scopeClass > 0) {
        // parents of students in this class
        $sql_base = "
            SELECT DISTINCT p.id, p.name
            FROM parents p
            INNER JOIN students s ON s.parent_id = p.id
            WHERE p.school_id = ? AND s.class_id = ?
        ";
        $params = [$school_id, $scopeClass]; $types = "ii";
    } elseif ($scopeType === 'level' && $scopeLevel !== '') {
        $sql_base = "
            SELECT DISTINCT p.id, p.name
            FROM parents p
            INNER JOIN students s ON s.parent_id = p.id
            INNER JOIN classes c ON c.id = s.class_id
            WHERE p.school_id = ? AND c.level = ?
        ";
        $params = [$school_id, $scopeLevel]; $types = "is";
    }

    $stmt = mysqli_prepare($conn, $sql_base);
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $out = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $out[] = ['type' => 'parent', 'id' => (int)$r['id'], 'name' => $r['name']];
    }
    mysqli_stmt_close($stmt);
    return $out;
};

$resolve_accounts = function($scopeType, $scopeLevel, $scopeClass, $scopeIndiv) use ($conn, $school_id) {
    // Account holders = users who can log into the school portal
    // (teachers + admin accounts typically). Adapt to your "users"/"accounts" table.
    $sql = "SELECT id, name FROM users WHERE school_id = ? AND status = 'active'";
    $params = [$school_id]; $types = "i";

    if ($scopeType === 'individual' && $scopeIndiv > 0) {
        $sql .= " AND id = ?";
        $params[] = $scopeIndiv; $types .= "i";
    }

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $out = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $out[] = ['type' => 'account', 'id' => (int)$r['id'], 'name' => $r['name']];
    }
    mysqli_stmt_close($stmt);
    return $out;
};

/* Pick the right resolver based on audience */
if ($audience === 'teachers') {
    $recipients = $resolve_teachers($scope_type, $scope_level, $scope_class, $scope_indiv);
} elseif ($audience === 'students') {
    $recipients = $resolve_students($scope_type, $scope_level, $scope_class, $scope_indiv);
} elseif ($audience === 'parents') {
    $recipients = $resolve_parents($scope_type, $scope_level, $scope_class, $scope_indiv);
} elseif ($audience === 'accounts') {
    $recipients = $resolve_accounts($scope_type, $scope_level, $scope_class, $scope_indiv);
} else {
    // audience = all → everyone in the school (teachers + students + parents + accounts)
    $recipients = array_merge(
        $resolve_teachers('all', '', 0, 0),
        $resolve_students('all', '', 0, 0),
        $resolve_parents('all', '', 0, 0),
        $resolve_accounts('all', '', 0, 0)
    );
}

/* Deduplicate by type+id */
$seen = [];
$unique = [];
foreach ($recipients as $r) {
    $k = $r['type'] . ':' . $r['id'];
    if (isset($seen[$k])) continue;
    $seen[$k] = true;
    $unique[] = $r;
}
$recipients = $unique;

if (empty($recipients)) {
    header("Location: school-announcements.php?error=" . urlencode("No recipients matched your selection."));
    exit;
}

/* ---------- INSERT ---------- */
mysqli_begin_transaction($conn);

try {
    $total = count($recipients);

    $ins = mysqli_prepare($conn, "
        INSERT INTO school_announcements
            (school_id, sender_type, sender_id, sender_name, title, body, priority,
             audience, scope_type, scope_level, scope_class, total_recipients)
        VALUES (?, 'school', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$ins) throw new Exception("Insert prepare failed: " . mysqli_error($conn));

    $scope_class_val = ($scope_class > 0) ? $scope_class : null;
    $scope_level_val = ($scope_level !== '') ? $scope_level : null;

    mysqli_stmt_bind_param(
        $ins,
        "issssssssi",
        $school_id, $school_admin, $title, $body, $priority,
        $audience, $scope_type, $scope_level_val, $scope_class_val, $total
    );
    if (!mysqli_stmt_execute($ins)) throw new Exception("Insert failed: " . mysqli_stmt_error($ins));
    $ann_id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($ins);

    $ins_rec = mysqli_prepare($conn, "
        INSERT INTO school_announcement_recipients
            (announcement_id, school_id, recipient_type, recipient_id, recipient_name)
        VALUES (?, ?, ?, ?, ?)
    ");
    if (!$ins_rec) throw new Exception("Recipient prepare failed: " . mysqli_error($conn));

    foreach ($recipients as $r) {
        mysqli_stmt_bind_param(
            $ins_rec, "iisis",
            $ann_id, $school_id, $r['type'], $r['id'], $r['name']
        );
        mysqli_stmt_execute($ins_rec);
    }
    mysqli_stmt_close($ins_rec);

    mysqli_commit($conn);

    header("Location: school-announcements.php?sent=1&n=" . $total);
    exit;

} catch (Exception $e) {
    mysqli_rollback($conn);
    header("Location: school-announcements.php?error=" . urlencode("Could not send announcement: " . $e->getMessage()));
    exit;
}