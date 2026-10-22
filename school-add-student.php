<?php
require_once 'db.php';
require_once '_school-auth.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/*
|--------------------------------------------------------------------------
| AUTO-CREATE / MIGRATE students TABLE
|--------------------------------------------------------------------------
*/
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS students (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    school_id       INT NOT NULL,
    student_code    VARCHAR(40)  DEFAULT NULL,
    class_id        INT DEFAULT NULL,
    name            VARCHAR(150) NOT NULL,
    email           VARCHAR(150) DEFAULT NULL,
    phone           VARCHAR(30)  DEFAULT NULL,
    sex             ENUM('Male','Female') DEFAULT NULL,
    dob             DATE DEFAULT NULL,
    id_number       VARCHAR(60)  DEFAULT NULL,
    has_disability  TINYINT(1)   NOT NULL DEFAULT 0,
    disability_details TEXT      DEFAULT NULL,
    father_name     VARCHAR(150) DEFAULT NULL,
    father_email    VARCHAR(150) DEFAULT NULL,
    father_phone    VARCHAR(30)  DEFAULT NULL,
    mother_name     VARCHAR(150) DEFAULT NULL,
    mother_email    VARCHAR(150) DEFAULT NULL,
    mother_phone    VARCHAR(30)  DEFAULT NULL,
    guardian_name   VARCHAR(150) DEFAULT NULL,
    guardian_email  VARCHAR(150) DEFAULT NULL,
    guardian_phone  VARCHAR(30)  DEFAULT NULL,
    index_number    VARCHAR(60)  DEFAULT NULL,
    previous_school VARCHAR(180) DEFAULT NULL,
    last_marks_pct  DECIMAL(5,2) DEFAULT NULL,
    academic_level  ENUM('Nursery','Primary','O_Level','A_Level') DEFAULT NULL,
    academic_year   VARCHAR(20)  DEFAULT NULL,
    term            ENUM('term_1','term_2','term_3') DEFAULT NULL,
    year_of_study   VARCHAR(60)  DEFAULT NULL,
    country         VARCHAR(80)  DEFAULT 'Rwanda',
    province        VARCHAR(80)  DEFAULT NULL,
    district        VARCHAR(80)  DEFAULT NULL,
    sector          VARCHAR(80)  DEFAULT NULL,
    cell            VARCHAR(80)  DEFAULT NULL,
    village         VARCHAR(80)  DEFAULT NULL,
    address_line    VARCHAR(255) DEFAULT NULL,
    status          ENUM('active','inactive') DEFAULT 'active',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_code (school_id, student_code),
    KEY idx_students_school (school_id),
    KEY idx_students_class  (class_id),
    KEY idx_students_status (school_id, status),
    KEY idx_students_name   (school_id, name),
    KEY idx_students_index  (school_id, index_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* Safe migrations for older tables */
$alters = [
    "ALTER TABLE students ADD COLUMN student_code VARCHAR(40) DEFAULT NULL AFTER school_id",
    "ALTER TABLE students ADD COLUMN id_number VARCHAR(60) DEFAULT NULL AFTER dob",
    "ALTER TABLE students ADD COLUMN has_disability TINYINT(1) NOT NULL DEFAULT 0 AFTER id_number",
    "ALTER TABLE students ADD COLUMN disability_details TEXT DEFAULT NULL AFTER has_disability",
    "ALTER TABLE students ADD COLUMN father_name VARCHAR(150) DEFAULT NULL AFTER disability_details",
    "ALTER TABLE students ADD COLUMN father_email VARCHAR(150) DEFAULT NULL AFTER father_name",
    "ALTER TABLE students ADD COLUMN father_phone VARCHAR(30) DEFAULT NULL AFTER father_email",
    "ALTER TABLE students ADD COLUMN mother_name VARCHAR(150) DEFAULT NULL AFTER father_phone",
    "ALTER TABLE students ADD COLUMN mother_email VARCHAR(150) DEFAULT NULL AFTER mother_name",
    "ALTER TABLE students ADD COLUMN mother_phone VARCHAR(30) DEFAULT NULL AFTER mother_email",
    "ALTER TABLE students ADD COLUMN last_marks_pct DECIMAL(5,2) DEFAULT NULL AFTER previous_school",
    "ALTER TABLE students ADD COLUMN academic_level ENUM('Nursery','Primary','O_Level','A_Level') DEFAULT NULL AFTER last_marks_pct",
    "ALTER TABLE students ADD COLUMN academic_year VARCHAR(20) DEFAULT NULL AFTER academic_level",
    "ALTER TABLE students ADD COLUMN term ENUM('term_1','term_2','term_3') DEFAULT NULL AFTER academic_year",
    "ALTER TABLE students ADD COLUMN year_of_study VARCHAR(60) DEFAULT NULL AFTER term",
    "ALTER TABLE students ADD COLUMN country VARCHAR(80) DEFAULT 'Rwanda' AFTER year_of_study",
    "ALTER TABLE students ADD COLUMN province VARCHAR(80) DEFAULT NULL AFTER country",
    "ALTER TABLE students ADD COLUMN district VARCHAR(80) DEFAULT NULL AFTER province",
    "ALTER TABLE students ADD COLUMN sector VARCHAR(80) DEFAULT NULL AFTER district",
    "ALTER TABLE students ADD COLUMN cell VARCHAR(80) DEFAULT NULL AFTER sector",
    "ALTER TABLE students ADD COLUMN village VARCHAR(80) DEFAULT NULL AFTER cell",
    "ALTER TABLE students ADD COLUMN address_line VARCHAR(255) DEFAULT NULL AFTER village",
];
foreach ($alters as $sql) @mysqli_query($conn, $sql);

/*
|--------------------------------------------------------------------------
| AUTO-CREATE / MIGRATE classes TABLE (make sure it exists for saving)
|--------------------------------------------------------------------------
*/
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS classes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    school_id       INT NOT NULL,
    class_name      VARCHAR(120) NOT NULL,
    level           ENUM('Nursery','Primary','O_Level','A_Level') NOT NULL,
    year_of_study   VARCHAR(60)  DEFAULT NULL,
    academic_year   VARCHAR(20)  DEFAULT NULL,
    section         VARCHAR(20)  DEFAULT NULL,
    capacity        INT DEFAULT 0,
    status          ENUM('active','inactive') DEFAULT 'active',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_classes_school (school_id),
    KEY idx_classes_level  (school_id, level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

@mysqli_query($conn, "ALTER TABLE classes ADD COLUMN year_of_study VARCHAR(60) DEFAULT NULL AFTER level");
@mysqli_query($conn, "ALTER TABLE classes ADD COLUMN section VARCHAR(20) DEFAULT NULL AFTER academic_year");

/*
|--------------------------------------------------------------------------
| AUTO-CREATE / MIGRATE custom_year_of_study TABLE
|--------------------------------------------------------------------------
| Saves user-typed "Year of Study" values so they appear as suggestions
| next time, scoped per school + level.
*/
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS custom_year_of_study (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    school_id   INT NOT NULL,
    level       VARCHAR(20) NOT NULL,
    label       VARCHAR(80) NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_custom_yos (school_id, level, label),
    KEY idx_yos_school_level (school_id, level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/*
|--------------------------------------------------------------------------
| AUTO-CREATE / MIGRATE custom_classes TABLE
|--------------------------------------------------------------------------
| Saves user-typed "Class names" (e.g. "Primary 6 B") that are not yet in
| the classes table, scoped per school + level + year_of_study + academic_year.
| When a matching class appears in the classes table, that class is used instead.
*/
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS custom_classes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    school_id       INT NOT NULL,
    level           VARCHAR(20) NOT NULL,
    year_of_study   VARCHAR(80) DEFAULT NULL,
    academic_year   VARCHAR(20) DEFAULT NULL,
    class_label     VARCHAR(120) NOT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_custom_class (school_id, level, year_of_study, academic_year, class_label),
    KEY idx_cc_school (school_id),
    KEY idx_cc_lookup (school_id, level, year_of_study, academic_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/*
|--------------------------------------------------------------------------
| RWANDA HIERARCHY
|--------------------------------------------------------------------------
*/
$rwanda_hierarchy = [
    'Kigali City' => [
        'Gasabo'    => ['Remera','Kimironko','Kacyiru','Gisozi','Ndera','Kinyinya'],
        'Kicukiro'  => ['Kicukiro','Niboye','Gatenga','Kanombe','Gikondo','Masaka'],
        'Nyarugenge'=> ['Nyamirambo','Kimisagara','Muhima','Gitega','Nyakabanda','Kanyinya'],
    ],
    'Southern Province' => [
        'Huye'    => ['Ngoma','Tumba','Mukura','Ruhashya','Kinazi','Simbi'],
        'Muhanga' => ['Nyamabuye','Shyogwe','Kabacuzi','Kiyumba','Mushishiro'],
        'Nyanza'  => ['Busasamana','Mukingo','Ntyazo','Rwabicuma','Busoro'],
        'Gisagara'=> ['Save','Kansi','Kibilizi','Mukindo','Ndora'],
    ],
    'Western Province' => [
        'Rubavu'   => ['Gisenyi','Rubavu','Nyamyumba','Kanama','Mudende'],
        'Rusizi'   => ['Kamembe','Gihundwe','Nkombo','Mururu','Nyakabuye'],
        'Karongi'  => ['Bwishyura','Rubengera','Mubuga','Gitesi','Rugabano'],
        'Nyamasheke'=>['Kagano','Bushenge','Kanjongo','Rangiro','Shangi'],
    ],
    'Northern Province' => [
        'Musanze' => ['Muhoza','Kimonyi','Remera','Cyuve','Busogo'],
        'Gicumbi' => ['Byumba','Rubaya','Kaniga','Muko','Bukure'],
        'Burera'  => ['Butaro','Kagogo','Cyanika','Rugarama','Ruhunde'],
        'Gakenke' => ['Gakenke','Nemba','Kivuruga','Rushashi','Janja'],
    ],
    'Eastern Province' => [
        'Rwamagana' => ['Kigabiro','Muhazi','Munyaga','Fumbwe','Karenge'],
        'Kayonza'   => ['Kabarondo','Mukarange','Ndego','Rwinkwavu','Gahini'],
        'Ngoma'     => ['Kibungo','Sake','Zaza','Remera','Rukumberi'],
        'Bugesera'  => ['Nyamata','Ruhuha','Rilima','Kamabuye','Mareba'],
        'Nyagatare' => ['Nyagatare','Karangazi','Rwimiyaga','Gatunda','Matimba'],
        'Gatsibo'   => ['Kabarore','Kiziguro','Rwimbogo','Gatsibo','Kageyo'],
        'Kirehe'    => ['Kirehe','Nyakarambi','Rusumo','Mahama','Kigarama'],
    ],
];

$countries = ['Rwanda','Uganda','Tanzania','Burundi','Kenya','DR Congo','South Sudan','Other'];

$level_map = ['Nursery'=>'Nursery','Primary'=>'Primary','O_Level'=>"O' Level",'A_Level'=>"A' Level"];
$term_labels = ['term_1'=>'Term 1','term_2'=>'Term 2','term_3'=>'Term 3'];

/* Preset suggestions per level (the user can still type custom values) */
$preset_year_of_study = [
    'Nursery' => ['Nursery 1','Nursery 2','Nursery 3'],
    'Primary' => ['Primary 1','Primary 2','Primary 3','Primary 4','Primary 5','Primary 6'],
    'O_Level' => ['Senior 1','Senior 2','Senior 3'],
    'A_Level' => ['Senior 4','Senior 5','Senior 6'],
];

/* -------------------------------------------------------------------------
| Load saved custom "Year of Study" values for this school
| ------------------------------------------------------------------------- */
$saved_year_of_study = []; // ['Primary' => ['Primary 1', 'Primary 6', ...], ...]
$q_yos = mysqli_prepare($conn, "SELECT level, label FROM custom_year_of_study WHERE school_id = ? ORDER BY label ASC");
if ($q_yos) {
    mysqli_stmt_bind_param($q_yos, "i", $school_id);
    mysqli_stmt_execute($q_yos);
    $r = mysqli_stmt_get_result($q_yos);
    while ($row = mysqli_fetch_assoc($r)) {
        $saved_year_of_study[$row['level']][] = $row['label'];
    }
    mysqli_stmt_close($q_yos);
}

/* -------------------------------------------------------------------------
| Load classes for this school (used to build the Class dropdown)
| ------------------------------------------------------------------------- */
$classes_all = [];
$cstmt = mysqli_prepare($conn, "
    SELECT id, class_name, level, year_of_study, academic_year, section, capacity, status
    FROM classes
    WHERE school_id = ?
    ORDER BY level ASC, academic_year DESC, year_of_study ASC, class_name ASC
");
if ($cstmt) {
    mysqli_stmt_bind_param($cstmt, "i", $school_id);
    mysqli_stmt_execute($cstmt);
    $cres = mysqli_stmt_get_result($cstmt);
    while ($c = mysqli_fetch_assoc($cres)) $classes_all[] = $c;
    mysqli_stmt_close($cstmt);
}

/* Distinct academic years already used in the classes table */
$years_in_classes = [];
foreach ($classes_all as $c) {
    if (!empty($c['academic_year']) && !in_array($c['academic_year'], $years_in_classes, true)) {
        $years_in_classes[] = $c['academic_year'];
    }
}
rsort($years_in_classes);

/* -------------------------------------------------------------------------
| Load saved custom classes for this school
| (for dropdown building — only used where they don't collide with real classes)
| ------------------------------------------------------------------------- */
$custom_classes = [];
$q_cc = mysqli_prepare($conn, "
    SELECT level, year_of_study, academic_year, class_label
    FROM custom_classes
    WHERE school_id = ?
    ORDER BY class_label ASC
");
if ($q_cc) {
    mysqli_stmt_bind_param($q_cc, "i", $school_id);
    mysqli_stmt_execute($q_cc);
    $r = mysqli_stmt_get_result($q_cc);
    while ($row = mysqli_fetch_assoc($r)) {
        $key = $row['level'] . '|' . ($row['year_of_study'] ?? '') . '|' . ($row['academic_year'] ?? '');
        $custom_classes[$key][] = $row['class_label'];
    }
    mysqli_stmt_close($q_cc);
}

/*
|--------------------------------------------------------------------------
| VALIDATION HELPERS
|--------------------------------------------------------------------------
*/
function is_valid_rwanda_phone($p) {
    if ($p === '') return false;
    $clean = preg_replace('/[\s\-]/', '', $p);
    if (preg_match('/^07[2389]\d{7}$/', $clean)) return true;
    if (preg_match('/^\+?2507[2389]\d{7}$/', $clean)) return true;
    return false;
}
function normalize_rwanda_phone($p) {
    if ($p === '') return '';
    $clean = preg_replace('/[\s\-]/', '', $p);
    if (preg_match('/^\+?250(7[2389]\d{7})$/', $clean, $m)) return $m[1];
    return $clean;
}
function is_valid_rwanda_id($id) {
    return (bool)preg_match('/^\d{16}$/', $id);
}

/*
|--------------------------------------------------------------------------
| STUDENT CODE
|--------------------------------------------------------------------------
*/
function generate_student_code($conn, $school_id) {
    $year = date('Y');
    $stmt = mysqli_prepare($conn, "SELECT student_code FROM students WHERE school_id = ? AND student_code IS NOT NULL AND student_code <> ''");
    $max_seq = 0;
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $school_id);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($r)) {
            $code = (string)$row['student_code'];
            if (preg_match('/^(\d{5})/', $code, $m)) {
                $seq = (int)$m[1];
                if ($seq > $max_seq) $max_seq = $seq;
            }
        }
        mysqli_stmt_close($stmt);
    }
    $next = $max_seq + 1;
    return str_pad((string)$next, 5, '0', STR_PAD_LEFT) . $year;
}

/*
|--------------------------------------------------------------------------
| DUPLICATE CHECK
|--------------------------------------------------------------------------
*/
function find_duplicate($conn, $school_id, $name, $dob, $index_number, $id_number) {
    $sql = "SELECT id, student_code, name FROM students WHERE school_id = ? AND (
        (name = ? AND (dob = ? OR (dob IS NULL AND ? IS NULL)))
    ";
    $params = [$school_id, $name, $dob, $dob];
    $types  = "isss";

    if ($index_number !== '') {
        $sql .= " OR index_number = ? ";
        $params[] = $index_number; $types .= "s";
    }
    if ($id_number !== '') {
        $sql .= " OR id_number = ? ";
        $params[] = $id_number; $types .= "s";
    }
    $sql .= ") LIMIT 1";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    $dup = $r ? mysqli_fetch_assoc($r) : null;
    mysqli_stmt_close($stmt);
    return $dup;
}

/*
|--------------------------------------------------------------------------
| HELPER: Save custom "Year of Study" if new
|--------------------------------------------------------------------------
*/
function save_custom_year_of_study($conn, $school_id, $level, $label) {
    $label = trim((string)$label);
    if ($label === '' || $level === '') return;
    $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO custom_year_of_study (school_id, level, label) VALUES (?, ?, ?)");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "iss", $school_id, $level, $label);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

/*
|--------------------------------------------------------------------------
| HELPER: Find or create the class for the chosen level + year_of_study + academic_year
|--------------------------------------------------------------------------
| Returns the class_id, or null.
|
|   1. If a class already exists with the same level + year_of_study + academic_year
|      AND (optionally) same class_name, use it.
|   2. Otherwise, create a new class row and return the new id.
|
| The typed class_name is used as-is (e.g. "Primary 6 B").
*/
function find_or_create_class($conn, $school_id, $level, $year_of_study, $academic_year, $class_name) {
    $level         = trim((string)$level);
    $year_of_study = trim((string)$year_of_study);
    $academic_year = trim((string)$academic_year);
    $class_name    = trim((string)$class_name);

    if ($level === '' || $class_name === '') return null;

    // Try to find an existing class with the same combo
    $sql = "SELECT id FROM classes
            WHERE school_id = ?
              AND level = ?
              AND (year_of_study = ? OR (year_of_study IS NULL AND ? = ''))
              AND (academic_year = ? OR (academic_year IS NULL AND ? = ''))
              AND class_name = ?
            LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param(
            $stmt, "issssss",
            $school_id, $level, $year_of_study, $year_of_study,
            $academic_year, $academic_year, $class_name
        );
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($r)) {
            mysqli_stmt_close($stmt);
            return (int)$row['id'];
        }
        mysqli_stmt_close($stmt);
    }

    // Not found — create it
    $ins = mysqli_prepare($conn, "
        INSERT INTO classes (school_id, class_name, level, year_of_study, academic_year, capacity, status)
        VALUES (?, ?, ?, ?, ?, 0, 'active')
    ");
    if (!$ins) return null;
    mysqli_stmt_bind_param($ins, "issss", $school_id, $class_name, $level, $year_of_study, $academic_year);
    if (!mysqli_stmt_execute($ins)) {
        mysqli_stmt_close($ins);
        return null;
    }
    $new_id = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($ins);
    return $new_id;
}

/*
|--------------------------------------------------------------------------
| HELPER: Save a custom class label as a suggestion (for future students)
|--------------------------------------------------------------------------
*/
function save_custom_class_label($conn, $school_id, $level, $year_of_study, $academic_year, $class_name) {
    $class_name = trim((string)$class_name);
    if ($class_name === '' || $level === '') return;
    $stmt = mysqli_prepare($conn, "
        INSERT IGNORE INTO custom_classes (school_id, level, year_of_study, academic_year, class_label)
        VALUES (?, ?, ?, ?, ?)
    ");
    if ($stmt) {
        mysqli_stmt_bind_param(
            $stmt, "issss",
            $school_id, $level, $year_of_study, $academic_year, $class_name
        );
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

/*
|--------------------------------------------------------------------------
| HANDLE SUBMIT
|--------------------------------------------------------------------------
*/
$message = ""; $message_type = "";
$form = [
    'name' => '', 'dob' => '', 'id_number' => '', 'sex' => '',
    'has_disability' => 0, 'disability_details' => '',
    'father_name' => '', 'father_email' => '', 'father_phone' => '',
    'mother_name' => '', 'mother_email' => '', 'mother_phone' => '',
    'guardian_name' => '', 'guardian_email' => '', 'guardian_phone' => '',
    'index_number' => '', 'previous_school' => '',
    'last_marks_pct' => '',
    'academic_level' => '', 'academic_year' => '', 'term' => '',
    'year_of_study' => '', 'class_name' => '', 'class_id' => 0,
    'country' => 'Rwanda', 'province' => '', 'district' => '',
    'sector' => '', 'cell' => '', 'village' => '', 'address_line' => '',
    'status' => 'active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach ($form as $k => $_) {
        if (isset($_POST[$k])) $form[$k] = trim((string)$_POST[$k]);
    }
    $form['has_disability'] = isset($_POST['has_disability']) && $_POST['has_disability'] == '1' ? 1 : 0;

    $errors = [];

    /* Basic */
    if ($form['name'] === '' || mb_strlen($form['name']) < 3) $errors[] = "Student name is required (at least 3 characters).";
    if ($form['dob'] === '') $errors[] = "Date of birth is required.";
    if (!in_array($form['sex'], ['Male','Female'], true)) $errors[] = "Please select the sex.";

    /* National ID */
    if ($form['id_number'] !== '' && !is_valid_rwanda_id($form['id_number'])) {
        $errors[] = "National ID must be exactly 16 digits.";
    }

    /* Disability */
    if ($form['has_disability'] === 1 && $form['disability_details'] === '') {
        $errors[] = "Please describe the disability.";
    }

    /* Phone validations */
    $phone_fields = [
        'father_phone'   => 'Father',
        'mother_phone'   => 'Mother',
        'guardian_phone' => 'Guardian',
    ];
    $valid_phones = [];
    foreach ($phone_fields as $pk => $label) {
        if ($form[$pk] !== '') {
            if (!is_valid_rwanda_phone($form[$pk])) {
                $errors[] = "{$label}'s phone number is not a valid Rwandan number (must be like 07XXXXXXXX or +2507XXXXXXXX).";
            } else {
                $valid_phones[] = $pk;
                $form[$pk] = normalize_rwanda_phone($form[$pk]);
            }
        }
    }
    if (empty($valid_phones)) {
        $errors[] = "At least one valid phone number is required (father, mother or guardian).";
    }

    /* Email formats */
    foreach (['father_email','mother_email','guardian_email'] as $ek) {
        if ($form[$ek] !== '' && !filter_var($form[$ek], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email in " . str_replace('_',' ', $ek) . ".";
        }
    }

    /* Academic */
    if (!in_array($form['academic_level'], ['Nursery','Primary','O_Level','A_Level'], true)) {
        $errors[] = "Please select the academic level.";
    }
    if ($form['academic_year'] === '') $errors[] = "Please select or type the academic year.";
    if (!in_array($form['term'], ['term_1','term_2','term_3'], true)) $errors[] = "Please select the term.";
    if ($form['year_of_study'] === '') $errors[] = "Please select or type the year of study.";
    if ($form['class_name'] === '' && (int)$form['class_id'] === 0) {
        $errors[] = "Please select or type the class.";
    }

    /* Marks */
    if ($form['last_marks_pct'] !== '') {
        if (!is_numeric($form['last_marks_pct']) || (float)$form['last_marks_pct'] < 0 || (float)$form['last_marks_pct'] > 100) {
            $errors[] = "Last marks must be a number between 0 and 100.";
        }
    }

    /* Address */
    if (strcasecmp($form['country'], 'Rwanda') === 0) {
        if ($form['province'] === '') $errors[] = "Please select the province.";
        if ($form['district'] === '') $errors[] = "Please select the district.";
    }

    /* Duplicate check */
    if (empty($errors)) {
        $dup = find_duplicate(
            $conn, $school_id,
            $form['name'],
            ($form['dob'] !== '' ? $form['dob'] : null),
            $form['index_number'],
            $form['id_number']
        );
        if ($dup) {
            $errors[] = "This student already exists (code {$dup['student_code']} — {$dup['name']}). Duplicate registrations are not allowed.";
        }
    }

    if (!empty($errors)) {
        $message = implode(" ", $errors);
        $message_type = "error";
    } else {
        $student_code = generate_student_code($conn, $school_id);

        /*
         * Resolve the final class_id.
         *
         * Order of preference:
         *   1. If the user picked an existing class from the dropdown
         *      (class_id > 0 and that class matches the combo), use it.
         *   2. Otherwise, if the user typed a class name, find or create it.
         *   3. Otherwise leave class_id = NULL.
         */
        $final_class_id = null;

        if ((int)$form['class_id'] > 0) {
            // Verify ownership
            $chk = mysqli_prepare($conn, "SELECT id, level, year_of_study, academic_year, class_name FROM classes WHERE id = ? AND school_id = ? LIMIT 1");
            mysqli_stmt_bind_param($chk, "ii", $form['class_id'], $school_id);
            mysqli_stmt_execute($chk);
            $chk_res = mysqli_stmt_get_result($chk);
            if ($chk_row = mysqli_fetch_assoc($chk_res)) {
                $final_class_id = (int)$chk_row['id'];
                // Remember this combo for future students
                save_custom_class_label(
                    $conn, $school_id,
                    $chk_row['level'],
                    $chk_row['year_of_study'] ?? '',
                    $chk_row['academic_year'] ?? '',
                    $chk_row['class_name']
                );
            } else {
                $errors[] = "Selected class does not belong to your school.";
            }
            mysqli_stmt_close($chk);
        } elseif ($form['class_name'] !== '') {
            // User typed a class name — find or create it, and remember it
            $final_class_id = find_or_create_class(
                $conn, $school_id,
                $form['academic_level'],
                $form['year_of_study'],
                $form['academic_year'],
                $form['class_name']
            );
            if (!$final_class_id) {
                $errors[] = "Could not save the class. Please try again.";
            } else {
                save_custom_class_label(
                    $conn, $school_id,
                    $form['academic_level'],
                    $form['year_of_study'],
                    $form['academic_year'],
                    $form['class_name']
                );
            }
        }

        // Remember the "Year of Study" the user typed
        save_custom_year_of_study($conn, $school_id, $form['academic_level'], $form['year_of_study']);

        if (!empty($errors)) {
            $message = implode(" ", $errors);
            $message_type = "error";
        } else {
            $dob_val   = $form['dob'] !== '' ? $form['dob'] : null;
            $marks_val = $form['last_marks_pct'] !== '' ? (float)$form['last_marks_pct'] : null;
            $disability_details = $form['has_disability'] === 1 ? $form['disability_details'] : null;

            $ins = mysqli_prepare($conn, "
                INSERT INTO students
                  (school_id, student_code, class_id, name, email, phone, sex, dob,
                   id_number, has_disability, disability_details,
                   father_name, father_email, father_phone,
                   mother_name, mother_email, mother_phone,
                   guardian_name, guardian_email, guardian_phone,
                   index_number, previous_school, last_marks_pct,
                   academic_level, academic_year, term, year_of_study,
                   country, province, district, sector, cell, village, address_line,
                   status)
                VALUES
                  (?, ?, ?, ?, ?, ?, ?, ?,
                   ?, ?, ?,
                   ?, ?, ?,
                   ?, ?, ?,
                   ?, ?, ?,
                   ?, ?, ?,
                   ?, ?, ?, ?,
                   ?, ?, ?, ?, ?, ?, ?,
                   ?)
            ");

            if (!$ins) {
                $message = "Database error: " . mysqli_error($conn);
                $message_type = "error";
            } else {
                $email_placeholder = null;
                $phone_placeholder = null;

                $types = 'i'; // school_id
                $types .= 's'; // student_code
                $types .= 'i'; // class_id
                $types .= 's'; // name
                $types .= 's'; // email
                $types .= 's'; // phone
                $types .= 's'; // sex
                $types .= 's'; // dob
                $types .= 's'; // id_number
                $types .= 'i'; // has_disability
                $types .= 's'; // disability_details
                $types .= 's'; // father_name
                $types .= 's'; // father_email
                $types .= 's'; // father_phone
                $types .= 's'; // mother_name
                $types .= 's'; // mother_email
                $types .= 's'; // mother_phone
                $types .= 's'; // guardian_name
                $types .= 's'; // guardian_email
                $types .= 's'; // guardian_phone
                $types .= 's'; // index_number
                $types .= 's'; // previous_school
                $types .= 'd'; // last_marks_pct
                $types .= 's'; // academic_level
                $types .= 's'; // academic_year
                $types .= 's'; // term
                $types .= 's'; // year_of_study
                $types .= 's'; // country
                $types .= 's'; // province
                $types .= 's'; // district
                $types .= 's'; // sector
                $types .= 's'; // cell
                $types .= 's'; // village
                $types .= 's'; // address_line
                $types .= 's'; // status

                mysqli_stmt_bind_param(
                    $ins,
                    $types,
                    $school_id,
                    $student_code,
                    $final_class_id,
                    $form['name'],
                    $email_placeholder,
                    $phone_placeholder,
                    $form['sex'],
                    $dob_val,
                    $form['id_number'],
                    $form['has_disability'],
                    $disability_details,
                    $form['father_name'],
                    $form['father_email'],
                    $form['father_phone'],
                    $form['mother_name'],
                    $form['mother_email'],
                    $form['mother_phone'],
                    $form['guardian_name'],
                    $form['guardian_email'],
                    $form['guardian_phone'],
                    $form['index_number'],
                    $form['previous_school'],
                    $marks_val,
                    $form['academic_level'],
                    $form['academic_year'],
                    $form['term'],
                    $form['year_of_study'],
                    $form['country'],
                    $form['province'],
                    $form['district'],
                    $form['sector'],
                    $form['cell'],
                    $form['village'],
                    $form['address_line'],
                    $form['status']
                );

                if (mysqli_stmt_execute($ins)) {
                    $new_id = (int)mysqli_insert_id($conn);
                    mysqli_stmt_close($ins);
                    header("Location: school-students.php?added=1&id=" . $new_id);
                    exit;
                } else {
                    $message = "Could not save student: " . mysqli_error($conn);
                    $message_type = "error";
                    mysqli_stmt_close($ins);
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| BUILD JSON PAYLOADS FOR THE FORM JS
|--------------------------------------------------------------------------
| These drive the cascading dropdowns on the client side.
*/

/* Existing classes: indexed by level|year_of_study|academic_year */
$classes_json = [];
foreach ($classes_all as $c) {
    $key = $c['level'] . '|' . ($c['year_of_study'] ?? '') . '|' . ($c['academic_year'] ?? '');
    $label = $c['class_name'];
    if (!empty($c['section'])) $label .= ' ' . $c['section'];
    $classes_json[$key][] = [
        'id'    => (int)$c['id'],
        'label' => $label,
    ];
}

/* Saved custom year_of_study per level */
$saved_yos_json = $saved_year_of_study;

/* Saved custom classes per level|year_of_study|academic_year */
$saved_custom_classes_json = $custom_classes;

/* Preset year_of_study per level */
$preset_yos_json = $preset_year_of_study;
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Student | Educ Bridge</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8; --primary-light: #dbeafe;
            --success: #10b981; --success-light: rgba(16,185,129,0.1);
            --warning: #f59e0b; --warning-light: rgba(245,158,11,0.12);
            --danger: #ef4444;  --danger-light: rgba(239,68,68,0.1);
            --sidebar-bg: #ffffff;
            --sidebar-hover: rgba(37, 99, 235, 0.06);
            --sidebar-active: rgba(37, 99, 235, 0.10);
            --sidebar-text: #475569;
            --sidebar-text-active: #0f172a;
            --sidebar-border: #e2e8f0;
            --sidebar-title: #94a3b8;
            --bg-body: #f8fafc; --bg-card: #ffffff; --bg-alt: #f1f5f9;
            --text-primary: #0f172a; --text-secondary: #475569; --text-muted: #64748b;
            --border-color: #e2e8f0;
            --shadow-md: 0 4px 12px rgba(0,0,0,0.07);
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.08);
            --shadow-sidebar: 0 0 30px rgba(0,0,0,0.05);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        [data-theme="dark"] {
            --primary: #3b82f6; --primary-dark: #2563eb; --primary-light: #1e3a5f;
            --success: #34d399; --success-light: rgba(16,185,129,0.15);
            --warning: #fbbf24; --warning-light: rgba(245,158,11,0.18);
            --danger: #f87171;  --danger-light: rgba(239,68,68,0.15);
            --sidebar-bg: #0f172a;
            --sidebar-hover: rgba(255,255,255,0.06);
            --sidebar-active: rgba(59, 130, 246, 0.18);
            --sidebar-text: #cbd5e1;
            --sidebar-text-active: #ffffff;
            --sidebar-border: rgba(255,255,255,0.08);
            --sidebar-title: #94a3b8;
            --bg-body: #0b1120; --bg-card: #1e293b; --bg-alt: #1a2332;
            --text-primary: #f1f5f9; --text-secondary: #cbd5e1; --text-muted: #94a3b8;
            --border-color: #334155;
            --shadow-md: 0 4px 12px rgba(0,0,0,0.4);
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.5);
            --shadow-sidebar: 0 0 30px rgba(0,0,0,0.4);
        }
        *,*::before,*::after { margin:0; padding:0; box-sizing:border-box; }
        html, body { min-height: 100vh; }
        body { font-family:'Inter',sans-serif; background:var(--bg-body); color:var(--text-primary); transition:background 0.4s ease, color 0.4s ease; overflow-x:hidden; }
        .layout { display:flex; min-height:100vh; }

        .sidebar { width:260px; background:var(--sidebar-bg); color:var(--sidebar-text); display:flex; flex-direction:column; position:fixed; top:0; left:0; height:100vh; z-index:100; transition:var(--transition); border-right:1px solid var(--sidebar-border); box-shadow:var(--shadow-sidebar); }
        .sidebar-header { padding:22px 22px 18px; border-bottom:1px solid var(--sidebar-border); display:flex; align-items:center; gap:12px; }
        .sidebar-header img { width:42px; height:42px; object-fit:contain; border-radius:50%; border:2px solid var(--sidebar-border); padding:2px; background:#fff; }
        .sidebar-brand { font-family:'Poppins',sans-serif; font-size:18px; font-weight:700; color:var(--text-primary); line-height:1.2; }
        .sidebar-brand span { color:var(--primary); }
        .sidebar-brand small { display:block; font-size:11px; font-weight:500; color:var(--text-muted); letter-spacing:0.4px; margin-top:2px; text-transform:uppercase; }
        .sidebar-nav { flex:1; padding:18px 12px; overflow-y:auto; }
        .nav-section-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; color:var(--sidebar-title); padding:12px 14px 8px; }
        .nav-item { display:flex; align-items:center; gap:12px; padding:11px 14px; color:var(--sidebar-text); text-decoration:none; font-size:14px; font-weight:500; border-radius:10px; margin-bottom:4px; transition:var(--transition); position:relative; }
        .nav-item i.nav-icon { width:20px; text-align:center; font-size:15px; }
        .nav-item:hover { background:var(--sidebar-hover); color:var(--sidebar-text-active); }
        .nav-item:hover i.nav-icon { color:var(--primary); }
        .nav-item.active { background:var(--sidebar-active); color:var(--sidebar-text-active); }
        .nav-item.active i.nav-icon { color:var(--primary); }
        .nav-item.active::before { content:''; position:absolute; left:0; top:8px; bottom:8px; width:3px; background:var(--primary); border-radius:0 4px 4px 0; }
        .sidebar-profile { border-top:1px solid var(--sidebar-border); padding:16px 18px; display:flex; align-items:center; gap:12px; }
        .sidebar-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Poppins',sans-serif; font-size:15px; font-weight:700; flex-shrink:0; }
        .sidebar-profile-info { flex:1; min-width:0; }
        .sidebar-profile-info .p-name { font-size:13.5px; font-weight:600; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sidebar-profile-info .p-role { font-size:11.5px; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sidebar-logout { width:34px; height:34px; border-radius:8px; background:var(--danger-light); color:var(--danger); display:flex; align-items:center; justify-content:center; text-decoration:none; font-size:13px; flex-shrink:0; transition:var(--transition); }
        .sidebar-logout:hover { background:var(--danger); color:#fff; }

        .main { flex:1; margin-left:260px; min-height:100vh; display:flex; flex-direction:column; transition:margin-left 0.3s ease; }
        .topbar { background:var(--bg-card); border-bottom:1px solid var(--border-color); padding:14px 28px; display:flex; align-items:center; justify-content:space-between; gap:16px; position:sticky; top:0; z-index:50; }
        .topbar-left { display:flex; align-items:center; gap:14px; }
        .hamburger { display:none; width:40px; height:40px; border-radius:10px; border:1.5px solid var(--border-color); background:var(--bg-body); color:var(--text-secondary); align-items:center; justify-content:center; cursor:pointer; font-size:15px; }
        .hamburger:hover { border-color:var(--primary); color:var(--primary); }
        .page-title { font-family:'Poppins',sans-serif; font-size:20px; font-weight:700; letter-spacing:-0.4px; }
        .page-subtitle { font-size:13px; color:var(--text-muted); margin-top:2px; }
        .topbar-actions { display:flex; align-items:center; gap:10px; }
        .topbar-btn { width:42px; height:42px; border-radius:50%; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; transition:var(--transition); text-decoration:none; }
        .topbar-btn:hover { border-color:var(--primary); color:var(--primary); transform:translateY(-1px); }
        .topbar-btn.theme-btn:hover { transform:rotate(20deg); }
        .topbar-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Poppins',sans-serif; font-size:14px; font-weight:700; box-shadow:0 4px 12px rgba(59,130,246,0.3); cursor:pointer; }

        .content { padding:28px; flex:1; }

        .back-link { display:inline-flex; align-items:center; gap:8px; color:var(--primary); text-decoration:none; font-size:14px; font-weight:600; margin-bottom:18px; transition:var(--transition); }
        .back-link:hover { color:var(--primary-dark); gap:11px; }

        .alert { padding:13px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; display:flex; align-items:flex-start; gap:10px; line-height:1.5; }
        .alert-success { background: var(--success-light); color:#059669; border:1px solid rgba(16,185,129,0.25); }
        [data-theme="dark"] .alert-success { color:#34d399; }
        .alert-error { background: var(--danger-light); color:#dc2626; border:1px solid rgba(239,68,68,0.25); }
        [data-theme="dark"] .alert-error { color:#f87171; }

        .card { max-width:960px; margin:0 auto; background:var(--bg-card); border:1px solid var(--border-color); border-radius:20px; box-shadow:var(--shadow-lg); overflow:hidden; }
        .card-head { padding:26px 30px; border-bottom:1px solid var(--border-color); background:linear-gradient(135deg, rgba(37,99,235,0.06), rgba(124,58,237,0.04)); }
        .card-head h2 { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; display:flex; align-items:center; gap:10px; margin-bottom:6px; }
        .card-head h2 i { color:var(--primary); }
        .card-head p { font-size:14px; color:var(--text-muted); }
        .card-body { padding:28px 30px; }

        .section-title { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.7px; color:var(--text-muted); margin:8px 0 14px; padding-bottom:8px; border-bottom:1px dashed var(--border-color); display:flex; align-items:center; gap:8px; flex-wrap: wrap; }
        .section-title i { color:var(--primary); font-size:12px; }
        .section-title .optional { color: var(--text-muted); text-transform: none; font-weight: 500; font-size: 11.5px; letter-spacing: 0; margin-left: 6px; }

        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
        .form-row-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:18px; }
        .form-row-4 { display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:18px; }
        .form-group { margin-bottom:18px; }
        .form-group label { display:block; font-size:13.5px; font-weight:600; color:var(--text-primary); margin-bottom:8px; }
        .form-group label .required { color:#ef4444; }
        .form-group label .opt { color: var(--text-muted); font-weight: 500; font-size: 12px; margin-left: 4px; }

        .form-group input, .form-group select, .form-group textarea {
            width:100%; padding:13px 15px;
            border:1.5px solid var(--border-color); border-radius:10px;
            background:var(--bg-alt); color:var(--text-primary);
            font-family:'Inter',sans-serif; font-size:14.5px; outline:none;
            transition:var(--transition);
        }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .form-group select { cursor: pointer; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color:var(--primary); background:var(--bg-card); box-shadow:0 0 0 3px rgba(37,99,235,0.1);
        }
        .form-group input:disabled, .form-group select:disabled {
            opacity: 0.6; cursor: not-allowed;
        }
        .form-help { font-size:12.5px; color:var(--text-muted); margin-top:6px; }

        .radio-pills { display:flex; flex-wrap:wrap; gap:10px; }
        .radio-pill { position:relative; display:inline-flex; align-items:center; gap:8px; padding:11px 18px; border:1.5px solid var(--border-color); border-radius:10px; cursor:pointer; background: var(--bg-alt); font-size: 13.5px; font-weight:600; color: var(--text-primary); transition: var(--transition); }
        .radio-pill input { position:absolute; opacity:0; pointer-events:none; }
        .radio-pill .dot { width:16px; height:16px; border-radius:50%; border:2px solid var(--border-color); position:relative; transition:var(--transition); flex-shrink:0; }
        .radio-pill .dot::after { content:''; position:absolute; inset:3px; border-radius:50%; background: var(--primary); opacity:0; transform: scale(0.4); transition:var(--transition); }
        .radio-pill:hover { border-color: var(--primary); }
        .radio-pill.selected { border-color: var(--primary); background: var(--primary-light); }
        .radio-pill.selected .dot { border-color: var(--primary); }
        .radio-pill.selected .dot::after { opacity:1; transform: scale(1); }

        .switch-row {
            display:flex; align-items:center; gap:14px;
            padding: 14px 18px;
            background: var(--bg-alt);
            border: 1.5px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            transition: var(--transition);
        }
        .switch-row:hover { border-color: var(--primary); }
        .switch-row.on { border-color: var(--primary); background: var(--primary-light); }
        .switch-row .sw { position: relative; width: 46px; height: 26px; border-radius: 50px; background: var(--border-color); transition: var(--transition); flex-shrink: 0; }
        .switch-row .sw::after { content:''; position:absolute; top:3px; left:3px; width: 20px; height:20px; border-radius:50%; background:#fff; box-shadow:0 2px 6px rgba(0,0,0,0.2); transition: var(--transition); }
        .switch-row.on .sw { background: var(--primary); }
        .switch-row.on .sw::after { transform: translateX(20px); }
        .switch-row .sr-text .t { font-size: 14px; font-weight: 700; color: var(--text-primary); }
        .switch-row .sr-text .d { font-size: 12.5px; color: var(--text-muted); margin-top: 2px; }
        .switch-row .sr-state { font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 50px; background: var(--border-color); color: var(--text-secondary); transition: var(--transition); margin-left: auto; }
        .switch-row.on .sr-state { background: var(--primary); color: #fff; }

        .person-block {
            background: var(--bg-alt);
            border: 1.5px solid var(--border-color);
            border-radius: 14px;
            padding: 18px;
            margin-bottom: 14px;
        }
        .person-block .pb-head {
            display: flex; align-items: center; gap: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px; font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 14px;
        }
        .person-block .pb-head i { color: var(--primary); }
        .person-block .pb-head .opt { color: var(--text-muted); font-weight: 500; font-size: 12px; }

        .hint-box {
            display:flex; align-items:flex-start; gap:10px;
            padding: 12px 14px;
            background: var(--warning-light);
            border: 1px solid rgba(245,158,11,0.35);
            border-radius: 10px;
            color: #b45309;
            font-size: 12.5px;
            line-height: 1.55;
            margin-bottom: 14px;
        }
        [data-theme="dark"] .hint-box { color: #fbbf24; }
        .hint-box i { font-size: 14px; flex-shrink: 0; margin-top: 1px; }

        /* Cascade block */
        .cascade-block {
            background: linear-gradient(135deg, rgba(37,99,235,0.04), rgba(124,58,237,0.03));
            border: 1.5px solid rgba(37,99,235,0.20);
            border-radius: 14px;
            padding: 18px;
            margin-bottom: 18px;
        }
        .cascade-block .cb-title {
            display: flex; align-items: center; gap: 8px;
            font-size: 12px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.6px; color: var(--primary);
            margin-bottom: 14px;
        }
        .cascade-block .cb-title i { font-size: 13px; }

        .cascade-summary {
            margin-top: 12px;
            padding: 10px 14px;
            background: var(--bg-card);
            border: 1px dashed var(--border-color);
            border-radius: 10px;
            font-size: 12.5px;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .cascade-summary .step {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 3px 10px;
            background: var(--bg-alt);
            border-radius: 50px;
            font-weight: 600;
            color: var(--text-primary);
        }
        .cascade-summary .step.muted { color: var(--text-muted); font-weight: 500; }
        .cascade-summary .step .arrow { color: var(--text-muted); margin: 0 -3px; }

        .form-actions { display:flex; gap:12px; justify-content:flex-end; padding-top:20px; border-top:1px solid var(--border-color); margin-top:12px; flex-wrap:wrap; }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:13px 24px; border-radius:10px; font-size:14.5px; font-weight:600; text-decoration:none; cursor:pointer; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-primary); transition:var(--transition); font-family:'Inter',sans-serif; }
        .btn:hover { border-color:var(--primary); color:var(--primary); transform:translateY(-1px); }
        .btn.primary { background:var(--primary); color:#fff; border-color:var(--primary); box-shadow:0 6px 18px rgba(37,99,235,0.32); }
        .btn.primary:hover { background:var(--primary-dark); color:#fff; }
        .btn:disabled { opacity:0.7; cursor:not-allowed; transform:none; }

        .overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:90; backdrop-filter: blur(2px); }
        .overlay.show { display:block; }

        @media (max-width: 900px) {
            .sidebar { transform:translateX(-100%); }
            .sidebar.open { transform:translateX(0); box-shadow:0 0 40px rgba(0,0,0,0.2); }
            .main { margin-left:0; }
            .hamburger { display:flex; }
        }
        @media (max-width: 700px) {
            .form-row, .form-row-3, .form-row-4 { grid-template-columns: 1fr; }
        }
        @media (max-width: 600px) {
            .content { padding:20px 16px; }
            .topbar { padding:12px 16px; }
            .page-title { font-size:17px; }
            .card-body { padding:22px 20px; }
            .card-head { padding:22px 20px; }
        }
    </style>
</head>
<body>

<div class="layout">

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="logo.png" alt="Educ Bridge">
            <div class="sidebar-brand">Educ<span>Bridge</span><small><?= htmlspecialchars($short_school); ?></small></div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section-title">Overview</div>
            <a href="school-dashboard.php" class="nav-item"><i class="fas fa-th-large nav-icon"></i> Dashboard</a>
            <div class="nav-section-title">Academics</div>
            <a href="school-students.php" class="nav-item active"><i class="fas fa-user-graduate nav-icon"></i> Students</a>
            <a href="school-teachers.php" class="nav-item"><i class="fas fa-chalkboard-teacher nav-icon"></i> Teachers</a>
            <a href="school-classes.php" class="nav-item"><i class="fas fa-layer-group nav-icon"></i> Classes</a>
            <a href="school-subjects.php" class="nav-item"><i class="fas fa-book nav-icon"></i> Subjects</a>
            <div class="nav-section-title">Communication</div>
            <a href="school-announcements.php" class="nav-item"><i class="fas fa-bullhorn nav-icon"></i> Announcements</a>
            <a href="school-events.php" class="nav-item"><i class="fas fa-calendar-alt nav-icon"></i> Events</a>
            <div class="nav-section-title">System</div>
            <a href="school-reports.php" class="nav-item active"><i class="fas fa-chart-line nav-icon"></i> Reports</a>
            <a href="school-settings.php" class="nav-item"><i class="fas fa-cog nav-icon"></i> Settings</a>
            <a href="school-applications.php" class="nav-item"><i class="fas fa-user-plus nav-icon"></i> Applications</a>
            <a href="school-marks.php" class="nav-item"><i class="fas fa-clipboard-list nav-icon"></i> Marks</a>
        </nav>
        <div class="sidebar-profile">
            <div class="sidebar-avatar"><?= htmlspecialchars($initials); ?></div>
            <div class="sidebar-profile-info">
                <div class="p-name"><?= htmlspecialchars($school_admin); ?></div>
                <div class="p-role"><?= htmlspecialchars($short_school); ?></div>
            </div>
            <a href="school-logout.php" class="sidebar-logout" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </aside>

    <div class="overlay" id="overlay"></div>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburger" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <div>
                    <div class="page-title">Add New Student</div>
                    <div class="page-subtitle"><?= htmlspecialchars($school_name); ?></div>
                </div>
            </div>
            <div class="topbar-actions">
                <button class="topbar-btn theme-btn" id="themeToggle" aria-label="Toggle theme"><i class="fas fa-moon"></i></button>
                <a href="school-announcements.php" class="topbar-btn" title="Announcements"><i class="fas fa-bell"></i></a>
                <div class="topbar-avatar" title="<?= htmlspecialchars($school_admin); ?>"><?= htmlspecialchars($initials); ?></div>
            </div>
        </header>

        <main class="content">

            <a href="school-students.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Students</a>

            <?php if ($message !== ""): ?>
                <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                    <i class="fas <?= $message_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-head">
                    <h2><i class="fas fa-user-plus"></i> Register a Student</h2>
                    <p>Fill in the student's information. A student code will be generated automatically after saving.</p>
                </div>
                <div class="card-body">

                    <form method="POST" action="school-add-student.php" id="studentForm" autocomplete="off">

                        <!-- BASIC INFORMATION -->
                        <div class="section-title"><i class="fas fa-user"></i> Basic Information</div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="name">Full Name <span class="required">*</span></label>
                                <input type="text" id="name" name="name" maxlength="150"
                                       placeholder="e.g. Jean Bosco Habimana"
                                       value="<?= htmlspecialchars($form['name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="dob">Date of Birth <span class="required">*</span></label>
                                <input type="date" id="dob" name="dob"
                                       value="<?= htmlspecialchars($form['dob']); ?>" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="id_number">National ID <span class="opt">(optional — 16 digits)</span></label>
                                <input type="text" id="id_number" name="id_number" maxlength="16"
                                       pattern="\d{16}" inputmode="numeric"
                                       placeholder="e.g. 1199870012345678"
                                       title="National ID must be exactly 16 digits"
                                       value="<?= htmlspecialchars($form['id_number']); ?>">
                                <div class="form-help">Rwandan National ID is exactly 16 digits.</div>
                            </div>
                            <div class="form-group">
                                <label>Sex <span class="required">*</span></label>
                                <div class="radio-pills" data-group="sex">
                                    <label class="radio-pill <?= $form['sex'] === 'Male'   ? 'selected' : ''; ?>">
                                        <input type="radio" name="sex" value="Male"   <?= $form['sex'] === 'Male'   ? 'checked' : ''; ?> required>
                                        <span class="dot"></span> Male
                                    </label>
                                    <label class="radio-pill <?= $form['sex'] === 'Female' ? 'selected' : ''; ?>">
                                        <input type="radio" name="sex" value="Female" <?= $form['sex'] === 'Female' ? 'checked' : ''; ?> required>
                                        <span class="dot"></span> Female
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Disability -->
                        <div class="form-group">
                            <div class="switch-row <?= (int)$form['has_disability'] === 1 ? 'on' : ''; ?>" id="disabilityRow">
                                <span class="sw <?= (int)$form['has_disability'] === 1 ? 'on' : ''; ?>" id="disabilitySwitch"></span>
                                <div class="sr-text">
                                    <div class="t">Has a disability?</div>
                                    <div class="d">Turn on if the student has any disability, then describe it below.</div>
                                </div>
                                <span class="sr-state" id="disabilityState"><?= (int)$form['has_disability'] === 1 ? 'YES' : 'NO'; ?></span>
                            </div>
                            <input type="hidden" name="has_disability" id="has_disability" value="<?= (int)$form['has_disability']; ?>">
                        </div>

                        <div class="form-group" id="disabilityDetailsWrap" style="margin-top:-6px;<?= (int)$form['has_disability'] === 1 ? '' : 'display:none;'; ?>">
                            <label for="disability_details">Disability Details</label>
                            <textarea id="disability_details" name="disability_details" maxlength="1000"
                                      placeholder="Describe the disability, special needs or accommodations..."><?= htmlspecialchars($form['disability_details']); ?></textarea>
                            <div class="form-help">Required if “Has a disability?” is set to Yes.</div>
                        </div>

                        <!-- PARENTS & GUARDIAN -->
                        <div class="section-title" style="margin-top:20px;">
                            <i class="fas fa-people-roof"></i> Parents &amp; Guardian
                            <span class="optional">(at least one valid Rwandan phone number is required)</span>
                        </div>

                        <!-- FATHER -->
                        <div class="person-block">
                            <div class="pb-head"><i class="fas fa-user-tie"></i> Father <span class="opt">— name required, phone optional</span></div>
                            <div class="form-row-3">
                                <div class="form-group" style="margin-bottom:0;">
                                    <label for="father_name">Full Name</label>
                                    <input type="text" id="father_name" name="father_name" maxlength="150"
                                           placeholder="e.g. Habimana Jean"
                                           value="<?= htmlspecialchars($form['father_name']); ?>">
                                </div>
                                <div class="form-group" style="margin-bottom:0;">
                                    <label for="father_phone">Phone <span class="opt">(Rwandan)</span></label>
                                    <input type="tel" id="father_phone" name="father_phone" maxlength="20"
                                           placeholder="e.g. 0783 561 459"
                                           value="<?= htmlspecialchars($form['father_phone']); ?>">
                                </div>
                                <div class="form-group" style="margin-bottom:0;">
                                    <label for="father_email">Email <span class="opt">(optional)</span></label>
                                    <input type="email" id="father_email" name="father_email" maxlength="150"
                                           placeholder="father@example.com"
                                           value="<?= htmlspecialchars($form['father_email']); ?>">
                                </div>
                            </div>
                        </div>

                        <!-- MOTHER -->
                        <div class="person-block">
                            <div class="pb-head"><i class="fas fa-user-tie"></i> Mother <span class="opt">— name required, phone optional if father has one</span></div>
                            <div class="form-row-3">
                                <div class="form-group" style="margin-bottom:0;">
                                    <label for="mother_name">Full Name</label>
                                    <input type="text" id="mother_name" name="mother_name" maxlength="150"
                                           placeholder="e.g. Uwase Marie"
                                           value="<?= htmlspecialchars($form['mother_name']); ?>">
                                </div>
                                <div class="form-group" style="margin-bottom:0;">
                                    <label for="mother_phone">Phone <span class="opt">(Rwandan)</span></label>
                                    <input type="tel" id="mother_phone" name="mother_phone" maxlength="20"
                                           placeholder="e.g. 0788 123 456"
                                           value="<?= htmlspecialchars($form['mother_phone']); ?>">
                                </div>
                                <div class="form-group" style="margin-bottom:0;">
                                    <label for="mother_email">Email <span class="opt">(optional)</span></label>
                                    <input type="email" id="mother_email" name="mother_email" maxlength="150"
                                           placeholder="mother@example.com"
                                           value="<?= htmlspecialchars($form['mother_email']); ?>">
                                </div>
                            </div>
                        </div>

                        <!-- GUARDIAN -->
                        <div class="person-block">
                            <div class="pb-head"><i class="fas fa-user-shield"></i> Guardian <span class="opt">— fully optional</span></div>
                            <div class="form-row-3">
                                <div class="form-group" style="margin-bottom:0;">
                                    <label for="guardian_name">Full Name <span class="opt">(optional)</span></label>
                                    <input type="text" id="guardian_name" name="guardian_name" maxlength="150"
                                           placeholder="e.g. Mukamana Alice"
                                           value="<?= htmlspecialchars($form['guardian_name']); ?>">
                                </div>
                                <div class="form-group" style="margin-bottom:0;">
                                    <label for="guardian_phone">Phone <span class="opt">(Rwandan)</span></label>
                                    <input type="tel" id="guardian_phone" name="guardian_phone" maxlength="20"
                                           placeholder="e.g. 0788 999 000"
                                           value="<?= htmlspecialchars($form['guardian_phone']); ?>">
                                </div>
                                <div class="form-group" style="margin-bottom:0;">
                                    <label for="guardian_email">Email <span class="opt">(optional)</span></label>
                                    <input type="email" id="guardian_email" name="guardian_email" maxlength="150"
                                           placeholder="guardian@example.com"
                                           value="<?= htmlspecialchars($form['guardian_email']); ?>">
                                </div>
                            </div>
                        </div>

                        <!-- PREVIOUS SCHOOL -->
                        <div class="section-title" style="margin-top:20px;">
                            <i class="fas fa-school"></i> Previous School
                            <span class="optional">(only for students coming from another school)</span>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="index_number">Index Number <span class="opt">(from previous school)</span></label>
                                <input type="text" id="index_number" name="index_number" maxlength="60"
                                       placeholder="e.g. 2025/P1/0123"
                                       value="<?= htmlspecialchars($form['index_number']); ?>">
                            </div>
                            <div class="form-group">
                                <label for="previous_school">Previous School Name <span class="opt">(optional)</span></label>
                                <input type="text" id="previous_school" name="previous_school" maxlength="180"
                                       placeholder="e.g. Green Hills Primary School"
                                       value="<?= htmlspecialchars($form['previous_school']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="last_marks_pct">Last Marks Percentage <span class="opt">(optional, for students from other schools)</span></label>
                            <input type="number" id="last_marks_pct" name="last_marks_pct" step="0.01" min="0" max="100"
                                   placeholder="e.g. 78.50"
                                   value="<?= htmlspecialchars($form['last_marks_pct']); ?>">
                            <div class="form-help">Enter a value between 0 and 100.</div>
                        </div>

                        <!-- ACADEMIC -->
                        <div class="section-title" style="margin-top:20px;">
                            <i class="fas fa-graduation-cap"></i> Academic Information
                            <span class="optional">(choose Academic Year → Level → Year of Study → Class)</span>
                        </div>

                        <div class="cascade-block">
                            <div class="cb-title"><i class="fas fa-stream"></i> Class Assignment Cascade</div>

                            <!-- STEP 1: Academic Year -->
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="academic_year">1. Academic Year <span class="required">*</span></label>
                                    <input type="text" id="academic_year" name="academic_year" maxlength="20"
                                           list="yearSuggest"
                                           placeholder="e.g. 2025-2026"
                                           value="<?= htmlspecialchars($form['academic_year']); ?>" required>
                                    <datalist id="yearSuggest">
                                        <?php foreach ($years_in_classes as $y): ?>
                                            <option value="<?= htmlspecialchars($y); ?>"></option>
                                        <?php endforeach; ?>
                                        <?php
                                            $y = (int)date('Y');
                                            for ($i = -1; $i <= 2; $i++) {
                                                $yy = $y + $i;
                                                echo '<option value="' . $yy . '-' . ($yy + 1) . '"></option>';
                                            }
                                        ?>
                                    </datalist>
                                    <div class="form-help">Type or pick. Example: 2025-2026.</div>
                                </div>

                                <!-- STEP 2: Level -->
                                <div class="form-group">
                                    <label for="academic_level">2. Academic Level <span class="required">*</span></label>
                                    <select id="academic_level" name="academic_level" required>
                                        <option value="">— Select level —</option>
                                        <?php foreach ($level_map as $val => $label): ?>
                                            <option value="<?= htmlspecialchars($val); ?>" <?= $form['academic_level'] === $val ? 'selected' : ''; ?>>
                                                <?= htmlspecialchars($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-help">Nursery, Primary, O' Level or A' Level.</div>
                                </div>
                            </div>

                            <!-- STEP 3: Year of Study -->
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="year_of_study">3. Year of Study <span class="required">*</span></label>
                                    <input type="text" id="year_of_study" name="year_of_study" maxlength="60"
                                           list="yearOfStudyList"
                                           placeholder="e.g. Primary 6"
                                           value="<?= htmlspecialchars($form['year_of_study']); ?>" required
                                           autocomplete="off">
                                    <datalist id="yearOfStudyList"></datalist>
                                    <div class="form-help">Pick a suggestion or type your own. Typed values are saved for future students.</div>
                                </div>

                                <!-- STEP 4: Term -->
                                <div class="form-group">
                                    <label for="term">Term <span class="required">*</span></label>
                                    <select id="term" name="term" required>
                                        <option value="">— Select term —</option>
                                        <?php foreach ($term_labels as $val => $label): ?>
                                            <option value="<?= htmlspecialchars($val); ?>" <?= $form['term'] === $val ? 'selected' : ''; ?>>
                                                <?= htmlspecialchars($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- STEP 5: Class -->
                            <div class="form-group" style="margin-bottom:0;">
                                <label for="class_name">4. Class <span class="required">*</span></label>

                                <!-- The select is used when the combo has saved options -->
                                <select id="class_select" style="display:none;">
                                    <option value="">— Select class —</option>
                                </select>

                                <!-- The text input is used when no options exist, or when the user wants a custom value -->
                                <input type="text" id="class_name" name="class_name" maxlength="120"
                                       list="classSuggest"
                                       placeholder="e.g. Primary 6 B"
                                       value="<?= htmlspecialchars($form['class_name']); ?>"
                                       autocomplete="off"
                                       required>
                                <datalist id="classSuggest"></datalist>

                                <!-- Hidden field carrying the chosen class_id (if any) -->
                                <input type="hidden" id="class_id" name="class_id" value="0">

                                <div class="form-help">
                                    Choose from the suggestions (saved classes for the selected Year → Level → Year of Study) or type a new class name.
                                    Typed values are saved and become suggestions next time.
                                </div>
                            </div>

                            <!-- Live summary of the cascade -->
                            <div class="cascade-summary" id="cascadeSummary">
                                <span class="step muted"><i class="fas fa-calendar-alt"></i> <span id="sumYear">Year —</span></span>
                                <i class="fas fa-arrow-right arrow" style="color:var(--text-muted);"></i>
                                <span class="step muted"><i class="fas fa-layer-group"></i> <span id="sumLevel">Level —</span></span>
                                <i class="fas fa-arrow-right arrow" style="color:var(--text-muted);"></i>
                                <span class="step muted"><i class="fas fa-book"></i> <span id="sumYearOfStudy">Year of Study —</span></span>
                                <i class="fas fa-arrow-right arrow" style="color:var(--text-muted);"></i>
                                <span class="step muted"><i class="fas fa-chalkboard"></i> <span id="sumClass">Class —</span></span>
                            </div>
                        </div>

                        <!-- ADDRESS -->
                        <div class="section-title" style="margin-top:20px;"><i class="fas fa-map-marker-alt"></i> Address</div>

                        <div class="hint-box">
                            <i class="fas fa-info-circle"></i>
                            <div>
                                Choose the country. If you pick <strong>Rwanda</strong>, you can select Province → District → Sector and continue down to Cell and Village. If you don't know the exact cell or village, simply stop at any level — the remaining fields are optional. For other countries, you can type the province / region and district manually.
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="country">Country <span class="required">*</span></label>
                                <select id="country" name="country" required>
                                    <?php foreach ($countries as $cn): ?>
                                        <option value="<?= htmlspecialchars($cn); ?>" <?= $form['country'] === $cn ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars($cn); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group" id="provinceWrap">
                                <label for="province">Province / Region <span class="opt">(required in Rwanda)</span></label>
                                <select id="province" name="province">
                                    <option value="">— Select province —</option>
                                    <?php foreach (array_keys($rwanda_hierarchy) as $prov): ?>
                                        <option value="<?= htmlspecialchars($prov); ?>" <?= $form['province'] === $prov ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars($prov); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group" id="districtWrap">
                                <label for="district">District <span class="opt">(required in Rwanda)</span></label>
                                <select id="district" name="district">
                                    <option value="">— Select district —</option>
                                </select>
                            </div>

                            <div class="form-group" id="sectorWrap">
                                <label for="sector">Sector <span class="opt">(optional)</span></label>
                                <select id="sector" name="sector">
                                    <option value="">— Select sector —</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="cell">Cell <span class="opt">(optional — type manually if unknown)</span></label>
                                <input type="text" id="cell" name="cell" maxlength="80"
                                       placeholder="e.g. Nyabisindu"
                                       value="<?= htmlspecialchars($form['cell']); ?>">
                            </div>
                            <div class="form-group">
                                <label for="village">Village <span class="opt">(optional — type manually if unknown)</span></label>
                                <input type="text" id="village" name="village" maxlength="80"
                                       placeholder="e.g. Amahoro"
                                       value="<?= htmlspecialchars($form['village']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="address_line">Additional Address Details <span class="opt">(optional)</span></label>
                            <input type="text" id="address_line" name="address_line" maxlength="255"
                                   placeholder="e.g. Near the market, house #12"
                                   value="<?= htmlspecialchars($form['address_line']); ?>">
                        </div>

                        <!-- STATUS -->
                        <div class="section-title" style="margin-top:20px;"><i class="fas fa-toggle-on"></i> Status</div>
                        <div class="form-group">
                            <div class="radio-pills" data-group="status">
                                <label class="radio-pill <?= $form['status'] === 'active'   ? 'selected' : ''; ?>">
                                    <input type="radio" name="status" value="active"   <?= $form['status'] === 'active'   ? 'checked' : ''; ?> required>
                                    <span class="dot"></span> Active
                                </label>
                                <label class="radio-pill <?= $form['status'] === 'inactive' ? 'selected' : ''; ?>">
                                    <input type="radio" name="status" value="inactive" <?= $form['status'] === 'inactive' ? 'checked' : ''; ?> required>
                                    <span class="dot"></span> Inactive
                                </label>
                            </div>
                        </div>

                        <!-- ACTIONS -->
                        <div class="form-actions">
                            <a href="school-students.php" class="btn"><i class="fas fa-times"></i> Cancel</a>
                            <button type="submit" class="btn primary" id="saveBtn">
                                <i class="fas fa-save"></i> Register Student
                            </button>
                        </div>

                    </form>

                </div>
            </div>

        </main>
    </div>
</div>

<script>
    // THEME
    (function () {
        const html = document.documentElement;
        const toggle = document.getElementById('themeToggle');
        const icon = toggle.querySelector('i');
        function applyTheme(theme) {
            html.setAttribute('data-theme', theme);
            localStorage.setItem('educbridge-theme', theme);
            if (theme === 'dark') { icon.classList.remove('fa-moon'); icon.classList.add('fa-sun'); }
            else { icon.classList.remove('fa-sun'); icon.classList.add('fa-moon'); }
        }
        applyTheme(localStorage.getItem('educbridge-theme') || 'light');
        toggle.addEventListener('click', function () {
            applyTheme(html.getAttribute('data-theme') === 'light' ? 'dark' : 'light');
        });
    })();

    // SIDEBAR
    (function () {
        const hamburger = document.getElementById('hamburger');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');
        function openSidebar() { sidebar.classList.add('open'); overlay.classList.add('show'); }
        function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('show'); }
        hamburger.addEventListener('click', function () {
            if (sidebar.classList.contains('open')) closeSidebar(); else openSidebar();
        });
        overlay.addEventListener('click', closeSidebar);
    })();

    // RADIO PILLS
    document.querySelectorAll('.radio-pills').forEach(function (group) {
        group.querySelectorAll('.radio-pill').forEach(function (pill) {
            pill.addEventListener('click', function () {
                group.querySelectorAll('.radio-pill').forEach(p => p.classList.remove('selected'));
                pill.classList.add('selected');
                const radio = pill.querySelector('input');
                if (radio) radio.checked = true;
            });
        });
    });

    // DISABILITY SWITCH
    (function () {
        const row = document.getElementById('disabilityRow');
        const sw  = document.getElementById('disabilitySwitch');
        const state = document.getElementById('disabilityState');
        const input = document.getElementById('has_disability');
        const wrap = document.getElementById('disabilityDetailsWrap');

        row.addEventListener('click', function () {
            const next = input.value !== '1';
            input.value = next ? '1' : '0';
            row.classList.toggle('on', next);
            sw.classList.toggle('on', next);
            state.textContent = next ? 'YES' : 'NO';
            wrap.style.display = next ? '' : 'none';
            const dt = document.getElementById('disability_details');
            if (!next) dt.value = '';
            else dt.focus();
        });
    })();

    /* ---------------------------------------------------------------------
     * CASCADING CLASS SELECTION
     * ---------------------------------------------------------------------
     * Data comes from PHP:
     *   - classesByCombo:   { "level|yos|year": [ {id, label}, ... ] }
     *   - savedYOS:         { "level": ["Primary 1", "Primary 6", ...] }
     *   - presetYOS:        { "level": ["Primary 1", ...] }
     *   - savedCustomClasses: { "level|yos|year": ["Primary 6 B", ...] }
     * ------------------------------------------------------------------- */
    const CLASSES_BY_COMBO = <?= json_encode($classes_json, JSON_UNESCAPED_UNICODE); ?>;
    const SAVED_YOS        = <?= json_encode($saved_yos_json, JSON_UNESCAPED_UNICODE); ?>;
    const PRESET_YOS       = <?= json_encode($preset_yos_json, JSON_UNESCAPED_UNICODE); ?>;
    const SAVED_CUSTOM_CLASSES = <?= json_encode($saved_custom_classes_json, JSON_UNESCAPED_UNICODE); ?>;

    const academicYearEl  = document.getElementById('academic_year');
    const academicLevelEl = document.getElementById('academic_level');
    const yearOfStudyEl   = document.getElementById('year_of_study');
    const yearOfStudyList = document.getElementById('yearOfStudyList');

    const classSelect    = document.getElementById('class_select');
    const classNameInput = document.getElementById('class_name');
    const classSuggest   = document.getElementById('classSuggest');
    const classIdHidden  = document.getElementById('class_id');

    const sumYear        = document.getElementById('sumYear');
    const sumLevel       = document.getElementById('sumLevel');
    const sumYearOfStudy = document.getElementById('sumYearOfStudy');
    const sumClass       = document.getElementById('sumClass');

    function comboKey(level, yos, year) {
        return (level || '') + '|' + (yos || '') + '|' + (year || '');
    }

    function refreshYearOfStudyList() {
        const lvl = academicLevelEl.value;
        // Merge preset + saved, keeping order preset first
        const presets = PRESET_YOS[lvl] || [];
        const saved   = SAVED_YOS[lvl] || [];
        const merged  = [];
        const seen    = new Set();
        presets.concat(saved).forEach(function (v) {
            const s = String(v).trim();
            if (s !== '' && !seen.has(s)) { seen.add(s); merged.push(s); }
        });
        yearOfStudyList.innerHTML = '';
        merged.forEach(function (v) {
            const o = document.createElement('option');
            o.value = v;
            yearOfStudyList.appendChild(o);
        });
    }

    /* Refresh the class list + datalist based on the current combo */
    function refreshClassOptions() {
        const year  = academicYearEl.value.trim();
        const level = academicLevelEl.value;
        const yos   = yearOfStudyEl.value.trim();

        const key = comboKey(level, yos, year);

        // 1. Build the suggestion list (datalist) = saved custom classes + real classes
        const suggestions = [];
        const seen = new Set();

        (SAVED_CUSTOM_CLASSES[key] || []).forEach(function (v) {
            if (v && !seen.has(v)) { seen.add(v); suggestions.push(v); }
        });
        (CLASSES_BY_COMBO[key] || []).forEach(function (c) {
            if (c && c.label && !seen.has(c.label)) { seen.add(c.label); suggestions.push(c.label); }
        });

        classSuggest.innerHTML = '';
        suggestions.forEach(function (v) {
            const o = document.createElement('option');
            o.value = v;
            classSuggest.appendChild(o);
        });

        // 2. Build the select (only if we have real classes for this combo)
        const realClasses = CLASSES_BY_COMBO[key] || [];
        classSelect.innerHTML = '<option value="">— Select class —</option>';
        realClasses.forEach(function (c) {
            const o = document.createElement('option');
            o.value = c.id;
            o.textContent = c.label;
            classSelect.appendChild(o);
        });

        if (realClasses.length > 0) {
            // Show select, hide text input
            classSelect.style.display = '';
            classNameInput.style.display = 'none';
            classNameInput.required = false;
            classSelect.required = false;
        } else {
            // Show text input
            classSelect.style.display = 'none';
            classNameInput.style.display = '';
            classNameInput.required = true;
            // Reset hidden class id
            classIdHidden.value = '0';
        }

        updateSummary();
    }

    /* Called when the user picks a real class from the select */
    function syncFromClassSelect() {
        const val = classSelect.value;
        if (val && val !== '') {
            // Sync into the text input so the hidden submit path is consistent
            const opt = classSelect.options[classSelect.selectedIndex];
            if (opt) classNameInput.value = opt.textContent.trim();
            classIdHidden.value = val;
        } else {
            classIdHidden.value = '0';
        }
        updateSummary();
    }

    /* When the user types a class name manually */
    classNameInput.addEventListener('input', function () {
        // If what was typed matches a real class, capture its id
        const typed = classNameInput.value.trim();
        let matchedId = '0';
        const key = comboKey(academicLevelEl.value, yearOfStudyEl.value.trim(), academicYearEl.value.trim());
        (CLASSES_BY_COMBO[key] || []).forEach(function (c) {
            if (c.label.toLowerCase() === typed.toLowerCase()) {
                matchedId = String(c.id);
            }
        });
        classIdHidden.value = matchedId;
        updateSummary();
    });

    classSelect.addEventListener('change', syncFromClassSelect);

    function updateSummary() {
        const y = academicYearEl.value.trim();
        const l = academicLevelEl.value;
        const yos = yearOfStudyEl.value.trim();
        const cls = classNameInput.value.trim() || (classSelect.value ? classSelect.options[classSelect.selectedIndex].textContent.trim() : '');

        sumYear.textContent = y || 'Year —';
        sumLevel.textContent = l ? (l.replace('_',' ')) : 'Level —';
        sumYearOfStudy.textContent = yos || 'Year of Study —';
        sumClass.textContent = cls || 'Class —';

        // Highlight completed steps
        [ [sumYear, y], [sumLevel, l], [sumYearOfStudy, yos], [sumClass, cls] ].forEach(function (pair) {
            const el = pair[0];
            const val = pair[1];
            const step = el.closest('.step');
            if (!step) return;
            if (val) step.classList.remove('muted');
            else step.classList.add('muted');
        });
    }

    academicYearEl.addEventListener('input', refreshClassOptions);
    academicYearEl.addEventListener('change', refreshClassOptions);
    academicLevelEl.addEventListener('change', function () {
        refreshYearOfStudyList();
        refreshClassOptions();
    });
    yearOfStudyEl.addEventListener('input', refreshClassOptions);
    yearOfStudyEl.addEventListener('change', refreshClassOptions);

    // Initial refresh on page load
    refreshYearOfStudyList();
    refreshClassOptions();

    // RWANDA ADDRESS HIERARCHY
    const RWANDA = <?= json_encode($rwanda_hierarchy); ?>;
    const PRESELECT_PROVINCE = <?= json_encode($form['province']); ?>;
    const PRESELECT_DISTRICT = <?= json_encode($form['district']); ?>;
    const PRESELECT_SECTOR   = <?= json_encode($form['sector']); ?>;

    const countrySel  = document.getElementById('country');
    const provinceSel = document.getElementById('province');
    const districtSel = document.getElementById('district');
    const sectorSel   = document.getElementById('sector');
    const provinceWrap = document.getElementById('provinceWrap');
    const districtWrap = document.getElementById('districtWrap');
    const sectorWrap   = document.getElementById('sectorWrap');

    function rebuildDistricts(province, selectedDistrict) {
        districtSel.innerHTML = '<option value="">— Select district —</option>';
        if (RWANDA[province]) {
            Object.keys(RWANDA[province]).forEach(function (d) {
                const o = document.createElement('option');
                o.value = d;
                o.textContent = d;
                if (d === selectedDistrict) o.selected = true;
                districtSel.appendChild(o);
            });
        }
    }

    function rebuildSectors(province, district, selectedSector) {
        sectorSel.innerHTML = '<option value="">— Select sector —</option>';
        if (RWANDA[province] && RWANDA[province][district]) {
            RWANDA[province][district].forEach(function (s) {
                const o = document.createElement('option');
                o.value = s;
                o.textContent = s;
                if (s === selectedSector) o.selected = true;
                sectorSel.appendChild(o);
            });
        }
    }

    if (provinceSel.value) rebuildDistricts(provinceSel.value, PRESELECT_DISTRICT);
    if (provinceSel.value && districtSel.value) rebuildSectors(provinceSel.value, districtSel.value, PRESELECT_SECTOR);

    provinceSel.addEventListener('change', function () {
        rebuildDistricts(this.value, null);
        rebuildSectors(this.value, '', null);
    });
    districtSel.addEventListener('change', function () {
        rebuildSectors(provinceSel.value, this.value, null);
    });

    // Country change: if not Rwanda, swap the selects for plain text inputs
    let provinceIsText = false;

    function makeText(el, placeholder) {
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.id = el.id;
        inp.name = el.name;
        inp.placeholder = placeholder;
        inp.maxLength = 80;
        inp.value = el.value || '';
        return inp;
    }

    countrySel.addEventListener('change', function () {
        const isRwanda = this.value.trim().toLowerCase() === 'rwanda';

        if (!isRwanda && !provinceIsText) {
            const pInp = makeText(provinceSel, 'Type province / region');
            provinceSel.replaceWith(pInp);
            provinceIsText = true;

            const dInp = makeText(districtSel, 'Type district / city');
            districtSel.replaceWith(dInp);

            const sInp = makeText(sectorSel, 'Type sector (optional)');
            sectorSel.replaceWith(sInp);

            districtWrap.style.display = '';
            sectorWrap.style.display = '';
            provinceWrap.style.display = '';
        } else if (isRwanda && provinceIsText) {
            window.location = 'school-add-student.php';
        }
    });

    // Prevent double submit
    document.getElementById('studentForm').addEventListener('submit', function () {
        const btn = document.getElementById('saveBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registering...';
    });
</script>

</body>
</html>