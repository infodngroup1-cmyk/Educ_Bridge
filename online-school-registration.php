<?php
require_once 'db.php';

/*
|--------------------------------------------------------------------------
| PUBLIC PAGE — no auth
|--------------------------------------------------------------------------
|   online-school-registration.php?school=CODE        → show form
|   online-school-registration.php?school=CODE (POST)  → validate + save
|--------------------------------------------------------------------------
*/

/* ---------- helpers ---------- */
function col_exists($conn, $table, $col) {
    $t = mysqli_real_escape_string($conn, $table);
    $c = mysqli_real_escape_string($conn, $col);
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    return ($r && mysqli_num_rows($r) > 0);
}
function table_exists($conn, $table) {
    $t = mysqli_real_escape_string($conn, $table);
    $r = @mysqli_query($conn, "SHOW TABLES LIKE '{$t}'");
    return ($r && mysqli_num_rows($r) > 0);
}

/* ---------------------------------------------------------------------
 | 0. AUTO-CREATE / MIGRATE students table
 | --------------------------------------------------------------------- */
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS students (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    student_code       VARCHAR(10) NOT NULL UNIQUE,
    school_id          INT NOT NULL,
    first_name         VARCHAR(80)  NOT NULL,
    last_name          VARCHAR(80)  NOT NULL,
    dob                DATE         NOT NULL,
    sex                ENUM('Male','Female') NOT NULL,
    country            VARCHAR(80)  NOT NULL DEFAULT 'Rwanda',
    province           VARCHAR(80)  DEFAULT NULL,
    district           VARCHAR(80)  DEFAULT NULL,
    sector             VARCHAR(80)  DEFAULT NULL,
    cell               VARCHAR(80)  DEFAULT NULL,
    village            VARCHAR(80)  DEFAULT NULL,
    address_line       VARCHAR(200) DEFAULT NULL,
    phone              VARCHAR(40)  DEFAULT NULL,
    email              VARCHAR(120) DEFAULT NULL,
    father_name        VARCHAR(120) DEFAULT NULL,
    father_phone       VARCHAR(40)  DEFAULT NULL,
    father_email       VARCHAR(120) DEFAULT NULL,
    mother_name        VARCHAR(120) DEFAULT NULL,
    mother_phone       VARCHAR(40)  DEFAULT NULL,
    mother_email       VARCHAR(120) DEFAULT NULL,
    guardian_name      VARCHAR(120) DEFAULT NULL,
    guardian_phone     VARCHAR(40)  DEFAULT NULL,
    guardian_email     VARCHAR(120) DEFAULT NULL,
    disability         ENUM('none','yes') NOT NULL DEFAULT 'none',
    disability_note    VARCHAR(255) DEFAULT NULL,
    previous_school    VARCHAR(160) DEFAULT NULL,
    previous_marks     VARCHAR(40)  DEFAULT NULL,
    level              VARCHAR(20)  NOT NULL,
    class_id           INT          NOT NULL,
    preferred_contact  ENUM('phone','email') NOT NULL DEFAULT 'phone',
    status             ENUM('inactive','active') NOT NULL DEFAULT 'inactive',
    activated_at       DATETIME     DEFAULT NULL,
    activated_by       INT          DEFAULT NULL,
    ip_address         VARCHAR(45)  DEFAULT NULL,
    created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_st_school  (school_id),
    KEY idx_st_class   (class_id),
    KEY idx_st_status  (status),
    KEY idx_st_code    (student_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* Ensure every column we depend on actually exists (old installs) */
if (table_exists($conn, 'students')) {
    $required_cols = [
        'student_code'      => "VARCHAR(10) DEFAULT NULL",
        'first_name'        => "VARCHAR(80) DEFAULT NULL",
        'last_name'         => "VARCHAR(80) DEFAULT NULL",
        'dob'               => "DATE DEFAULT NULL",
        'sex'               => "ENUM('Male','Female') DEFAULT NULL",
        'country'           => "VARCHAR(80) DEFAULT 'Rwanda'",
        'province'          => "VARCHAR(80) DEFAULT NULL",
        'district'          => "VARCHAR(80) DEFAULT NULL",
        'sector'            => "VARCHAR(80) DEFAULT NULL",
        'cell'              => "VARCHAR(80) DEFAULT NULL",
        'village'           => "VARCHAR(80) DEFAULT NULL",
        'address_line'      => "VARCHAR(200) DEFAULT NULL",
        'phone'             => "VARCHAR(40) DEFAULT NULL",
        'email'             => "VARCHAR(120) DEFAULT NULL",
        'father_name'       => "VARCHAR(120) DEFAULT NULL",
        'father_phone'      => "VARCHAR(40) DEFAULT NULL",
        'father_email'      => "VARCHAR(120) DEFAULT NULL",
        'mother_name'       => "VARCHAR(120) DEFAULT NULL",
        'mother_phone'      => "VARCHAR(40) DEFAULT NULL",
        'mother_email'      => "VARCHAR(120) DEFAULT NULL",
        'guardian_name'     => "VARCHAR(120) DEFAULT NULL",
        'guardian_phone'    => "VARCHAR(40) DEFAULT NULL",
        'guardian_email'    => "VARCHAR(120) DEFAULT NULL",
        'disability'        => "ENUM('none','yes') NOT NULL DEFAULT 'none'",
        'disability_note'   => "VARCHAR(255) DEFAULT NULL",
        'previous_school'   => "VARCHAR(160) DEFAULT NULL",
        'previous_marks'    => "VARCHAR(40) DEFAULT NULL",
        'level'             => "VARCHAR(20) DEFAULT NULL",
        'class_id'          => "INT DEFAULT NULL",
        'preferred_contact' => "ENUM('phone','email') NOT NULL DEFAULT 'phone'",
        'status'            => "ENUM('inactive','active') NOT NULL DEFAULT 'inactive'",
        'activated_at'      => "DATETIME DEFAULT NULL",
        'activated_by'      => "INT DEFAULT NULL",
        'ip_address'        => "VARCHAR(45) DEFAULT NULL",
        'created_at'        => "DATETIME DEFAULT CURRENT_TIMESTAMP",
        'updated_at'        => "DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    foreach ($required_cols as $col => $def) {
        if (!col_exists($conn, 'students', $col)) {
            @mysqli_query($conn, "ALTER TABLE students ADD COLUMN `{$col}` {$def}");
        }
    }
}

/* ---------------------------------------------------------------------
 | 1. VERIFY SCHOOL + CONFIG
 | --------------------------------------------------------------------- */
$school_code = strtoupper(trim($_GET['school'] ?? ''));
$school      = null;
$config      = null;
$errors      = [];
$submitted   = false;
$new_code    = '';

if ($school_code === '') {
    $errors[] = "No school code provided.";
} else {
    $name_col = 'name';
    foreach (['name','school_name','title','school'] as $cand) {
        if (col_exists($conn, 'schools', $cand)) { $name_col = $cand; break; }
    }
    $has_status = col_exists($conn, 'schools', 'status');
    $has_admin  = col_exists($conn, 'schools', 'admin_name');

    $select  = "SELECT id, `{$name_col}` AS name, school_code";
    $select .= $has_admin  ? ", admin_name" : ", '' AS admin_name";
    $select .= $has_status ? ", status"     : ", 'active' AS status";
    $select .= " FROM schools WHERE school_code = ? LIMIT 1";

    $stmt = mysqli_prepare($conn, $select);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $school_code);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if (mysqli_num_rows($res) === 0) {
            $errors[] = "School not found.";
        } else {
            $row = mysqli_fetch_assoc($res);
            if (strtolower((string)$row['status']) !== 'active') {
                $errors[] = "This school is currently unavailable.";
            } else {
                $school = $row;
                if (table_exists($conn, 'online_registration_config')) {
                    $csql = mysqli_prepare($conn, "SELECT * FROM online_registration_config WHERE school_id = ? LIMIT 1");
                    if ($csql) {
                        mysqli_stmt_bind_param($csql, "i", $school['id']);
                        mysqli_stmt_execute($csql);
                        $cres = mysqli_stmt_get_result($csql);
                        $config = mysqli_fetch_assoc($cres);
                        mysqli_stmt_close($csql);
                    }
                }
            }
        }
        mysqli_stmt_close($stmt);
    } else {
        $errors[] = "Database error: " . mysqli_error($conn);
    }
}

/* ---------------------------------------------------------------------
 | 2. VERIFY REGISTRATION IS OPEN
 | --------------------------------------------------------------------- */
$registration_open = false;
$open_reason = '';
$levels_list = [];
if ($school) {
    if (!$config) {
        $open_reason = "This school hasn't configured online registration yet.";
    } else {
        $mode = $config['mode'] ?? 'closed';
        $now  = time();
        if ($mode === 'open') {
            $registration_open = true;
        } elseif ($mode === 'scheduled') {
            $o = !empty($config['opens_at'])  ? strtotime($config['opens_at'])  : null;
            $c = !empty($config['closes_at']) ? strtotime($config['closes_at']) : null;
            if ($o && $c && $now >= $o && $now <= $c) {
                $registration_open = true;
            } elseif ($o && $now < $o) {
                $open_reason = "Registration opens on " . date('M j, Y · H:i', $o) . ".";
            } elseif ($c && $now > $c) {
                $open_reason = "Registration closed on " . date('M j, Y · H:i', $c) . ".";
            } else {
                $open_reason = "Registration window is not properly scheduled yet.";
            }
        } else {
            $open_reason = "Online registration is closed for this school.";
        }
        $levels_list = array_filter(array_map('trim', explode(',', (string)($config['levels'] ?? ''))));
    }

    if (!$registration_open && empty($errors)) {
        $errors[] = $open_reason;
    }
}

/* ---------------------------------------------------------------------
 | 3. LOAD LEVELS + CLASSES
 | --------------------------------------------------------------------- */
$levels  = [];
$classes = [];
if ($school && $registration_open && empty($errors)) {
    $sid = (int)$school['id'];
    if (table_exists($conn, 'academic_levels')) {
        $lres = mysqli_query($conn, "
            SELECT id, level_key, level_name
            FROM academic_levels
            WHERE school_id = {$sid} AND is_active = 1
            ORDER BY id ASC
        ");
        if ($lres) { while ($r = mysqli_fetch_assoc($lres)) $levels[] = $r; }
    }
    if (table_exists($conn, 'classes')) {
        $cres = mysqli_query($conn, "
            SELECT id, class_name, level
            FROM classes
            WHERE school_id = {$sid} AND status = 'active'
            ORDER BY FIELD(level,'Nursery','Primary','O_Level','A_Level'), class_name ASC
        ");
        if ($cres) { while ($r = mysqli_fetch_assoc($cres)) $classes[] = $r; }
    }
}

function level_key_to_enum($key) {
    $key = strtolower(trim((string)$key));
    $map = [
        'nursery' => 'Nursery', 'primary' => 'Primary',
        'olevel'  => 'O_Level', 'o_level' => 'O_Level', 'o-level' => 'O_Level',
        'alevel'  => 'A_Level', 'a_level' => 'A_Level', 'a-level' => 'A_Level',
    ];
    return $map[$key] ?? null;
}

/* ---------------------------------------------------------------------
 | 4. STUDENT CODE GENERATOR
 | --------------------------------------------------------------------- */
function generate_student_code($conn, $school_code) {
    $digits = preg_replace('/\D/', '', (string)$school_code);
    $prefix = substr(str_pad($digits, 2, '0'), 0, 2);
    $year   = date('Y');
    $tries  = 0;
    do {
        $middle = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $code   = $prefix . $middle . $year;
        $chk = mysqli_prepare($conn, "SELECT 1 FROM students WHERE student_code = ? LIMIT 1");
        if (!$chk) return $code;
        mysqli_stmt_bind_param($chk, "s", $code);
        mysqli_stmt_execute($chk);
        $r = mysqli_stmt_get_result($chk);
        $exists = mysqli_num_rows($r) > 0;
        mysqli_stmt_close($chk);
        $tries++;
    } while ($exists && $tries < 50);
    return $code;
}

/* ---------------------------------------------------------------------
 | 5. POST HANDLER
 | --------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $school && $registration_open) {
    $sid = (int)$school['id'];
    $f = [
        'first_name'      => trim($_POST['first_name']  ?? ''),
        'last_name'       => trim($_POST['last_name']   ?? ''),
        'dob'             => trim($_POST['dob']         ?? ''),
        'sex'             => trim($_POST['sex']         ?? ''),
        'country'         => trim($_POST['country']     ?? ''),
        'province'        => trim($_POST['province']    ?? ''),
        'district'        => trim($_POST['district']    ?? ''),
        'sector'          => trim($_POST['sector']      ?? ''),
        'cell'            => trim($_POST['cell']        ?? ''),
        'village'         => trim($_POST['village']     ?? ''),
        'address_line'    => trim($_POST['address_line']?? ''),
        'phone'           => trim($_POST['phone']       ?? ''),
        'email'           => trim($_POST['email']       ?? ''),
        'father_name'     => trim($_POST['father_name'] ?? ''),
        'father_phone'    => trim($_POST['father_phone']?? ''),
        'father_email'    => trim($_POST['father_email']?? ''),
        'mother_name'     => trim($_POST['mother_name'] ?? ''),
        'mother_phone'    => trim($_POST['mother_phone']?? ''),
        'mother_email'    => trim($_POST['mother_email']?? ''),
        'guardian_name'   => trim($_POST['guardian_name']  ?? ''),
        'guardian_phone'  => trim($_POST['guardian_phone'] ?? ''),
        'guardian_email'  => trim($_POST['guardian_email'] ?? ''),
        'disability'      => trim($_POST['disability']  ?? 'none'),
        'disability_note' => trim($_POST['disability_note'] ?? ''),
        'previous_school' => trim($_POST['previous_school'] ?? ''),
        'previous_marks'  => trim($_POST['previous_marks']  ?? ''),
        'level'           => trim($_POST['level']       ?? ''),
        'class_id'        => (int)($_POST['class_id']   ?? 0),
        'preferred_contact'=> trim($_POST['preferred_contact'] ?? ''),
    ];

    if ($f['first_name'] === '')                              $errors[] = "First name is required.";
    if ($f['last_name']  === '')                              $errors[] = "Last name is required.";
    if ($f['dob']        === '')                              $errors[] = "Date of birth is required.";
    if (!in_array($f['sex'], ['Male','Female'], true))        $errors[] = "Please select the student's sex.";
    if ($f['country']    === '')                              $errors[] = "Country is required.";
    if ($f['country'] === 'Rwanda') {
        if ($f['province'] === '') $errors[] = "Province is required.";
        if ($f['district'] === '') $errors[] = "District is required.";
        if ($f['sector']   === '') $errors[] = "Sector is required.";
    }
    if ($f['phone'] === '' && $f['email'] === '')             $errors[] = "Enter at least a phone number or an email.";
    if ($f['level'] === '')                                   $errors[] = "Please select the academic level.";
    if ($f['class_id'] <= 0)                                  $errors[] = "Please select the class.";

    if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL))                 $errors[] = "Student email is not valid.";
    if ($f['father_email'] !== '' && !filter_var($f['father_email'], FILTER_VALIDATE_EMAIL))   $errors[] = "Father email is not valid.";
    if ($f['mother_email'] !== '' && !filter_var($f['mother_email'], FILTER_VALIDATE_EMAIL))   $errors[] = "Mother email is not valid.";
    if ($f['guardian_email'] !== '' && !filter_var($f['guardian_email'], FILTER_VALIDATE_EMAIL)) $errors[] = "Guardian email is not valid.";

    if (!in_array($f['preferred_contact'], ['phone','email'], true)) {
        $errors[] = "Please choose whether phone or email should be the default contact.";
    }
    if (!in_array($f['disability'], ['none','yes'], true)) $f['disability'] = 'none';

    if ($f['class_id'] > 0 && empty($errors)) {
        $chk = mysqli_prepare($conn, "
            SELECT 1 FROM classes
            WHERE id = ? AND school_id = ? AND status = 'active'
            LIMIT 1
        ");
        if ($chk) {
            mysqli_stmt_bind_param($chk, "ii", $f['class_id'], $sid);
            mysqli_stmt_execute($chk);
            $r = mysqli_stmt_get_result($chk);
            if (mysqli_num_rows($r) === 0) $errors[] = "The chosen class does not belong to this school.";
            mysqli_stmt_close($chk);
        }
    }

    if (empty($errors)) {
        $student_code = generate_student_code($conn, $school['school_code']);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        /* ---- Build column/value map — types are auto-generated, no more miscounts ---- */
        $cols = [
            'student_code'      => $student_code,        // s
            'school_id'         => $sid,                 // i
            'first_name'        => $f['first_name'],     // s
            'last_name'         => $f['last_name'],      // s
            'dob'               => $f['dob'],            // s (date)
            'sex'               => $f['sex'],            // s
            'country'           => $f['country'],        // s
            'province'          => $f['province'],       // s
            'district'          => $f['district'],       // s
            'sector'            => $f['sector'],         // s
            'cell'              => $f['cell'],           // s
            'village'           => $f['village'],        // s
            'address_line'      => $f['address_line'],   // s
            'phone'             => $f['phone'],          // s
            'email'             => $f['email'],          // s
            'father_name'       => $f['father_name'],    // s
            'father_phone'      => $f['father_phone'],   // s
            'father_email'      => $f['father_email'],   // s
            'mother_name'       => $f['mother_name'],    // s
            'mother_phone'      => $f['mother_phone'],   // s
            'mother_email'      => $f['mother_email'],   // s
            'guardian_name'     => $f['guardian_name'],  // s
            'guardian_phone'    => $f['guardian_phone'], // s
            'guardian_email'    => $f['guardian_email'], // s
            'disability'        => $f['disability'],     // s
            'disability_note'   => $f['disability_note'],// s
            'previous_school'   => $f['previous_school'],// s
            'previous_marks'    => $f['previous_marks'], // s
            'level'             => $f['level'],          // s
            'class_id'          => $f['class_id'],       // i
            'preferred_contact' => $f['preferred_contact'], // s
            'status'            => 'inactive',           // s
            'ip_address'        => $ip,                  // s
        ];

        $col_names    = array_keys($cols);
        $values       = array_values($cols);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));

        /* Auto-generate the type string — s for string, i for int, d for float */
        $types = '';
        foreach ($values as $v) {
            if (is_int($v))         $types .= 'i';
            elseif (is_float($v))   $types .= 'd';
            else                    $types .= 's';
        }

        $sql = "INSERT INTO students (" . implode(', ', $col_names) . ") VALUES ({$placeholders})";
        $ins = mysqli_prepare($conn, $sql);

        if ($ins) {
            /* Bind using the spread operator — always matches the count */
            mysqli_stmt_bind_param($ins, $types, ...$values);

            if (mysqli_stmt_execute($ins)) {
                $submitted = true;
                $new_code  = $student_code;
            } else {
                $errors[] = "Could not save registration: " . mysqli_stmt_error($ins);
            }
            mysqli_stmt_close($ins);
        } else {
            $errors[] = "Database error: " . mysqli_error($conn);
        }
    }
}

/* ---------------------------------------------------------------------
 | 6. RWANDA LOCATION DATA
 | --------------------------------------------------------------------- */
$rwanda = [
    "Kigali City" => [
        "Gasabo" => ["Bumbogo","Gatsata","Gikomero","Gisozi","Jabana","Jali","Kacyiru","Kimihurura","Kimironko","Kinyinya","Ndera","Nduba","Remera","Rusororo","Rutunga"],
        "Kicukiro" => ["Gahanga","Gatenga","Gikondo","Kagarama","Kanombe","Kicukiro","Kigarama","Masaka","Niboye","Nyarugunga"],
        "Nyarugenge" => ["Gitega","Kanyinya","Kigali","Kimisagara","Mageragere","Muhima","Nyakabanda","Nyamirambo","Nyarugenge","Rwezamenyo"],
    ],
    "Northern Province" => [
        "Burera" => ["Bungwe","Butaro","Cyanika","Cyeru","Gahunga","Gatebe","Gitovu","Kagogo","Kinoni","Kinyababa","Kivuye","Nemba","Rugarama","Rugengabari","Ruhunde","Rusarabuye","Rwerere"],
        "Gakenke" => ["Busengo","Coko","Gakenke","Gashenyi","Janja","Kamubuga","Karambo","Kivuruga","Mataba","Minazi","Mugunga","Muhondo","Muyongwe","Muzo","Nemba","Ruli","Rusasa","Rushashi"],
        "Gicumbi" => ["Bukure","Bwisige","Byumba","Cyumba","Giti","Kaniga","Manyagiro","Miyove","Mukarange","Muko","Mutete","Nyamiyaga","Nyankenke","Rubaya","Rukomo","Rushaki","Rutare","Ruvune","Rwamiko","Shangasha"],
        "Musanze" => ["Busogo","Cyuve","Gacaca","Gashaki","Gataraga","Kimonyi","Kinigi","Muhoza","Muko","Musanze","Nkotsi","Nyange","Remera","Rwaza","Shingiro"],
        "Rulindo" => ["Base","Burega","Bushoki","Buyoga","Cyinzuzi","Cyungo","Kinihira","Kisaro","Masoro","Mbogo","Murambi","Ngoma","Ntarabana","Rukozo","Rusiga","Shyorongi","Tumba"],
    ],
    "Southern Province" => [
        "Gisagara" => ["Gikonko","Gishubi","Kansi","Kibilizi","Kigembe","Mamba","Muganza","Mukindo","Musha","Ndora","Nyanza","Save"],
        "Huye" => ["Gishamvu","Huye","Karama","Kigoma","Kinazi","Maraba","Mbazi","Mukura","Ngoma","Ruhashya","Rusatira","Rwaniro","Simbi","Tumba"],
        "Kamonyi" => ["Gacurabwenge","Karama","Kayenzi","Kayumbu","Mugina","Musambira","Ngamba","Nyamiyaga","Nyarubaka","Rugalika","Rukoma","Runda"],
        "Muhanga" => ["Cyeza","Kabacuzi","Kibangu","Kiyumba","Muhanga","Mushishiro","Nyabinoni","Nyamabuye","Nyarusange","Rongi","Rugendabari","Shyogwe"],
        "Nyamagabe" => ["Buruhukiro","Cyanika","Gatare","Kaduha","Kamegeri","Kibirizi","Kibumbwe","Kitabi","Mbazi","Mugano","Musange","Musebeya","Mushubi","Nkomane","Gasaka","Tare","Uwinkingi"],
        "Nyanza" => ["Busasamana","Busoro","Cyabakamyi","Kibilizi","Kigoma","Mukingo","Muyira","Ntyazo","Nyagisozi","Rwabicuma"],
        "Nyaruguru" => ["Busanze","Cyahinda","Kibeho","Kivu","Mata","Muganza","Munini","Ngera","Ngoma","Nyabimata","Nyagisozi","Ruheru","Ruramba","Rusenge"],
        "Ruhango" => ["Bweramana","Byimana","Kabagali","Kinazi","Kinihira","Mbuye","Mwendo","Ntongwe","Ruhango"],
    ],
    "Eastern Province" => [
        "Bugesera" => ["Gashora","Juru","Kamabuye","Mareba","Mayange","Musenyi","Mwogo","Ngeruka","Ntarama","Nyamata","Nyarugenge","Rilima","Ruhuha","Rweru","Shyara"],
        "Gatsibo" => ["Gasange","Gatsibo","Gitoki","Kabarore","Kageyo","Kiramuruzi","Kiziguro","Muhura","Murambi","Ngarama","Nyagihanga","Remera","Rugarama","Rwimbogo"],
        "Kayonza" => ["Gahini","Kabare","Kabarondo","Mukarange","Murama","Murundi","Mwiri","Ndego","Nyamirama","Rukara","Ruramira","Rwinkwavu"],
        "Kirehe" => ["Gahara","Gatore","Kigarama","Kigina","Kirehe","Mahama","Mpanga","Musaza","Mushikiri","Nasho","Nyamugari","Nyarubuye"],
        "Ngoma" => ["Gashanda","Jarama","Karembo","Kazo","Kibungo","Mugesera","Murama","Mutenderi","Remera","Rukira","Rukumberi","Rurenge","Sake","Zaza"],
        "Nyagatare" => ["Gatunda","Karangazi","Katabagemu","Kiyombe","Matimba","Mimuri","Mukama","Musheli","Nyagatare","Rambura","Rwimiyaga","Tabagwe"],
        "Rwamagana" => ["Fumbwe","Gahengeri","Gishari","Karenge","Kigabiro","Muhazi","Munyaga","Munyiginya","Musha","Muyumbu","Mwulire","Nyakariro","Nzige","Rubona"],
    ],
    "Western Province" => [
        "Karongi" => ["Bwishyura","Gishyita","Gitesi","Mubuga","Murambi","Murundi","Mutuntu","Rubengera","Rugabano","Ruganda","Rwankuba","Twumba"],
        "Ngororero" => ["Bwira","Gatumba","Hindiro","Kabaya","Kageyo","Kavumu","Matyazo","Muhanda","Muhororo","Ndaro","Ngororero","Nyange","Sovu"],
        "Nyabihu" => ["Bigogwe","Jenda","Jomba","Kabatwa","Karago","Kintobo","Mukamira","Muringa","Rambura","Rugera","Rurembo","Shyira"],
        "Nyamasheke" => ["Bushenge","Cyato","Gihombo","Kagano","Kanjongo","Karambi","Karengera","Kirimbi","Macuba","Nyabitekeri","Rangiro","Ruharambuga","Shangi"],
        "Rubavu" => ["Bugeshi","Busasamana","Cyanzarwe","Gisenyi","Kanama","Kanzenze","Mudende","Nyakiliba","Nyamyumba","Nyundo","Rubavu","Rugerero"],
        "Rusizi" => ["Bugarama","Butare","Bweyeye","Gashonga","Giheke","Gihundwe","Gikundamvura","Gitambi","Kamembe","Muganza","Mururu","Nkanka","Nkombo","Nkungu","Nyakabuye","Nyakarenzo","Nzahaha","Rwimbogo"],
        "Rutsiro" => ["Boneza","Gihango","Kigeyo","Kivumu","Manihira","Mukura","Murunda","Musasa","Mushonyi","Mushubati","Nyabirasi","Ruhango","Rusebeya"],
    ],
];

/* ---------------------------------------------------------------------
 | 7. FETCH the newly registered student for dashboard display
 | --------------------------------------------------------------------- */
$dash = null;
if ($submitted && $new_code !== '' && $school) {
    $stmt = mysqli_prepare($conn, "
        SELECT s.*, c.class_name
        FROM students s
        LEFT JOIN classes c ON c.id = s.class_id
        WHERE s.student_code = ? AND s.school_id = ?
        LIMIT 1
    ");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "si", $new_code, $school['id']);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        $dash = mysqli_fetch_assoc($r);
        mysqli_stmt_close($stmt);
    }
}

$level_map = ['Nursery'=>'Nursery','Primary'=>'Primary','O_Level'=>"O' Level",'A_Level'=>"A' Level"];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Registration | <?= htmlspecialchars($school['name'] ?? 'Educ Bridge'); ?></title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary:#2563eb; --primary-dark:#1d4ed8; --primary-light:#dbeafe;
            --success:#10b981; --warning:#f59e0b; --danger:#ef4444;
            --bg-body:#f1f5f9; --bg-card:#ffffff; --bg-alt:#f8fafc;
            --text-primary:#0f172a; --text-secondary:#475569; --text-muted:#64748b;
            --border-color:#e2e8f0;
            --shadow-lg:0 20px 45px rgba(0,0,0,0.08);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        [data-theme="dark"] {
            --primary:#3b82f6; --primary-dark:#2563eb; --primary-light:#1e3a5f;
            --success:#34d399; --warning:#fbbf24; --danger:#f87171;
            --bg-body:#0b1120; --bg-card:#1e293b; --bg-alt:#1a2332;
            --text-primary:#f1f5f9; --text-secondary:#cbd5e1; --text-muted:#94a3b8;
            --border-color:#334155;
            --shadow-lg:0 20px 45px rgba(0,0,0,0.5);
        }
        *,*::before,*::after { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',sans-serif; background:var(--bg-body); color:var(--text-primary); padding:24px 16px 60px; position:relative; min-height:100vh; }
        body::before { content:''; position:fixed; inset:0; background: radial-gradient(circle at 10% 10%, rgba(37,99,235,0.08), transparent 45%), radial-gradient(circle at 90% 90%, rgba(124,58,237,0.08), transparent 45%); pointer-events:none; z-index:0; }
        .theme-toggle { position:fixed; top:20px; right:20px; width:42px; height:42px; border-radius:50%; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; z-index:20; }
        .theme-toggle:hover { color:var(--primary); border-color:var(--primary); }

        .wrap { width:100%; max-width:900px; margin:0 auto; position:relative; z-index:1; }

        .topnav { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px; flex-wrap:wrap; }
        .topnav a { display:inline-flex; align-items:center; gap:8px; padding:9px 14px; border-radius:10px; background: var(--bg-card); border: 1.5px solid var(--border-color); color: var(--text-secondary); text-decoration:none; font-size: 13px; font-weight: 600; transition: var(--transition); }
        .topnav a:hover { border-color: var(--primary); color: var(--primary); }

        .brand { display:flex; align-items:center; justify-content:center; gap:12px; margin-bottom:22px; }
        .brand img { width:48px; height:48px; border-radius:50%; background:#fff; padding:3px; border:2px solid var(--border-color); }
        .brand-text { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; }
        .brand-text span { color: var(--primary); }

        .card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:20px; box-shadow:var(--shadow-lg); overflow:hidden; }
        .card-head { padding:26px 30px 20px; border-bottom:1px solid var(--border-color); background:linear-gradient(135deg, rgba(37,99,235,0.07), rgba(124,58,237,0.05)); }
        .card-head .school-pill { display:inline-flex; align-items:center; gap:8px; background:var(--primary-light); color:var(--primary); padding:6px 14px; border-radius:50px; font-size:12.5px; font-weight:700; margin-bottom:12px; }
        .card-head h1 { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; margin-bottom:6px; }
        .card-head p { font-size:13.5px; color:var(--text-muted); line-height:1.55; }
        .card-body { padding:26px 30px 30px; }

        .alert { padding:13px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; display:flex; align-items:flex-start; gap:10px; line-height:1.55; }
        .alert-error   { background: rgba(239,68,68,0.08); color:#dc2626; border:1px solid rgba(239,68,68,0.25); }
        .alert-success { background: rgba(16,185,129,0.10); color:#047857; border:1px solid rgba(16,185,129,0.28); }
        .alert-info    { background: rgba(37,99,235,0.08); color:#1d4ed8; border:1px solid rgba(37,99,235,0.25); }
        .alert-warn    { background: rgba(245,158,11,0.10); color:#b45309; border:1px solid rgba(245,158,11,0.28); }
        [data-theme="dark"] .alert-error   { color:#f87171; }
        [data-theme="dark"] .alert-success { color:#34d399; }
        [data-theme="dark"] .alert-info    { color:#93c5fd; }
        [data-theme="dark"] .alert-warn    { color:#fbbf24; }
        .alert ul { margin-left: 18px; }
        .alert ul li { margin-top: 3px; }

        .section { margin-bottom: 26px; }
        .section-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: var(--text-muted); padding-bottom: 8px; border-bottom: 1px dashed var(--border-color); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .section-title i { color: var(--primary); }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
        @media (max-width: 640px) { .grid-2, .grid-3 { grid-template-columns: 1fr; } }

        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 12.5px; font-weight: 600; margin-bottom: 6px; color: var(--text-primary); }
        .form-group label .req { color: var(--danger); }
        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="tel"],
        .form-group input[type="date"],
        .form-group select,
        .form-group textarea {
            width: 100%; padding: 11px 13px;
            border: 1.5px solid var(--border-color); border-radius: 10px;
            background: var(--bg-alt); color: var(--text-primary);
            font-family: inherit; font-size: 14px;
            outline: none; transition: var(--transition);
        }
        .form-group select { cursor: pointer; }
        .form-group textarea { resize: vertical; min-height: 70px; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: var(--primary); background: var(--bg-card);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .form-group .hint { font-size: 11.5px; color: var(--text-muted); margin-top: 5px; }

        .pill-group { display: flex; gap: 8px; flex-wrap: wrap; }
        .pill-opt {
            position: relative; display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 14px; border: 1.5px solid var(--border-color);
            border-radius: 10px; background: var(--bg-alt);
            cursor: pointer; font-size: 13px; font-weight: 600;
            transition: var(--transition);
        }
        .pill-opt input { position: absolute; opacity: 0; pointer-events: none; }
        .pill-opt:hover { border-color: var(--primary); }
        .pill-opt.selected { border-color: var(--primary); background: var(--primary-light); color: var(--primary); }
        [data-theme="dark"] .pill-opt.selected { background: rgba(59,130,246,0.18); color:#93c5fd; }

        .rwanda-block { display: none; }
        .rwanda-block.show { display: block; }
        .foreign-block { display: none; }
        .foreign-block.show { display: block; }

        .btn-row { display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border-color); flex-wrap: wrap; }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:13px 22px; border-radius:10px; font-size:14px; font-weight:600; cursor:pointer; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-primary); text-decoration:none; transition:var(--transition); font-family:inherit; }
        .btn:hover { border-color: var(--primary); color: var(--primary); }
        .btn.primary { background:var(--primary); color:#fff; border-color:var(--primary); box-shadow:0 6px 18px rgba(37,99,235,0.32); }
        .btn.primary:hover { background: var(--primary-dark); color: #fff; }

        .dash-card { text-align: center; padding: 10px 10px 0; }
        .dash-card .icon {
            width: 82px; height: 82px; border-radius: 50%;
            background: linear-gradient(135deg, #10b981, #34d399);
            color: #fff; display: flex; align-items: center; justify-content: center;
            font-size: 38px; margin: 0 auto 20px;
            box-shadow: 0 12px 30px rgba(16,185,129,0.35);
        }
        .dash-card h2 { font-family:'Poppins',sans-serif; font-size: 24px; font-weight: 700; margin-bottom: 8px; }
        .dash-card p  { font-size: 14px; color: var(--text-muted); line-height: 1.6; margin-bottom: 20px; }

        .student-code-box {
            display: inline-block; padding: 18px 28px;
            background: linear-gradient(135deg, rgba(37,99,235,0.06), rgba(124,58,237,0.06));
            border: 2px dashed var(--primary); border-radius: 16px;
            margin-bottom: 14px;
        }
        .student-code-box .sc-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); margin-bottom: 6px; }
        .student-code-box .sc-value { font-family: 'Courier New', monospace; font-size: 32px; font-weight: 700; letter-spacing: 4px; color: var(--primary); }

        .activation-notice {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 14px 18px;
            background: rgba(245,158,11,0.10);
            border: 1px solid rgba(245,158,11,0.28);
            border-radius: 12px; margin-top: 14px; text-align: left;
        }
        [data-theme="dark"] .activation-notice { background: rgba(245,158,11,0.15); border-color: rgba(245,158,11,0.4); }
        .activation-notice i { color: var(--warning); font-size: 20px; margin-top: 1px; flex-shrink: 0; }
        .activation-notice .an-body { flex:1; }
        .activation-notice .an-title { font-size: 13.5px; font-weight: 700; color: #b45309; margin-bottom: 3px; }
        [data-theme="dark"] .activation-notice .an-title { color: #fbbf24; }
        .activation-notice .an-desc { font-size: 12.5px; color: var(--text-secondary); line-height: 1.55; }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px; margin-top: 22px; padding-top: 22px;
            border-top: 1px dashed var(--border-color);
        }
        .info-item { padding: 12px 14px; background: var(--bg-alt); border: 1px solid var(--border-color); border-radius: 10px; text-align: left; }
        .info-item .il { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); margin-bottom: 4px; }
        .info-item .iv { font-size: 13.5px; font-weight: 600; color: var(--text-primary); word-break: break-word; }

        .status-inactive { color: #b45309 !important; }
        [data-theme="dark"] .status-inactive { color: #fbbf24 !important; }

        .foot { text-align:center; margin-top:20px; font-size:13px; color:var(--text-muted); }
    </style>
</head>
<body>

<button class="theme-toggle" id="themeToggle" aria-label="Toggle theme"><i class="fas fa-moon"></i></button>

<div class="wrap">

    <div class="topnav">
        <a href="online-registration.php?school=<?= urlencode($school['school_code'] ?? ''); ?>">
            <i class="fas fa-arrow-left"></i> Back
        </a>
        <a href="index.php">
            <i class="fas fa-home"></i> Home
        </a>
    </div>

    <div class="brand">
        <img src="logo.png" alt="Educ Bridge">
        <div class="brand-text">Educ<span>Bridge</span></div>
    </div>

    <div class="card">

        <?php if ($submitted && $dash): ?>

            <div class="card-head">
                <div class="school-pill"><i class="fas fa-school"></i> <?= htmlspecialchars($school['name']); ?></div>
                <h1>Registration Complete</h1>
                <p>Save the student code below. It will be needed at the school gate and for future lookups.</p>
            </div>
            <div class="card-body">
                <div class="dash-card">
                    <div class="icon"><i class="fas fa-check"></i></div>
                    <h2>Welcome, <?= htmlspecialchars($dash['first_name']); ?>!</h2>
                    <p>Your registration at <strong><?= htmlspecialchars($school['name']); ?></strong> was received successfully.</p>

                    <div class="student-code-box">
                        <div class="sc-label">Student Code</div>
                        <div class="sc-value"><?= htmlspecialchars($dash['student_code']); ?></div>
                    </div>

                    <div class="activation-notice">
                        <i class="fas fa-circle-exclamation"></i>
                        <div class="an-body">
                            <div class="an-title">Pending Activation</div>
                            <div class="an-desc">
                                This student is currently marked as <strong>inactive</strong>.
                                Please visit the school office with the student code above to confirm enrollment.
                                The school will activate the account so the student can attend classes, appear in class lists, and receive announcements.
                            </div>
                        </div>
                    </div>

                    <div class="info-grid">
                        <div class="info-item">
                            <div class="il">Full Name</div>
                            <div class="iv"><?= htmlspecialchars($dash['first_name'] . ' ' . $dash['last_name']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="il">Date of Birth</div>
                            <div class="iv"><?= htmlspecialchars(date('M j, Y', strtotime($dash['dob']))); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="il">Sex</div>
                            <div class="iv"><?= htmlspecialchars($dash['sex']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="il">Level</div>
                            <div class="iv"><?= htmlspecialchars($level_map[$dash['level']] ?? $dash['level']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="il">Class</div>
                            <div class="iv"><?= htmlspecialchars($dash['class_name'] ?? '—'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="il">Preferred Contact</div>
                            <div class="iv"><?= $dash['preferred_contact'] === 'email' ? 'Email' : 'Phone'; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="il">Disability</div>
                            <div class="iv"><?= $dash['disability'] === 'yes' ? 'Yes' : 'No'; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="il">Status</div>
                            <div class="iv status-inactive">
                                <i class="fas fa-circle" style="font-size:7px;"></i> Inactive — pending activation at school
                            </div>
                        </div>
                    </div>

                    <div class="btn-row" style="justify-content:center;">
                        <div class="btn-row" style="justify-content:center;flex-wrap:wrap;">
    <a href="student-login.php?setup=1&code=<?= urlencode($dash['student_code']); ?>" class="btn primary">
        <i class="fas fa-key"></i> Set Up Login Password &amp; Go to Dashboard
    </a>
    <a href="online-school-registration.php?school=<?= urlencode($school['school_code']); ?>" class="btn">
        <i class="fas fa-plus"></i> Register another student
    </a>
    <a href="index.php" class="btn">
        <i class="fas fa-home"></i> Home
    </a>
</div>
                    </div>
                </div>
            </div>

        <?php elseif ($school && $registration_open): ?>

            <div class="card-head">
                <div class="school-pill"><i class="fas fa-school"></i> <?= htmlspecialchars($school['name']); ?></div>
                <h1>Online Registration</h1>
                <p>Fill in the student's details. Fields marked <span style="color:#ef4444;">*</span> are required.</p>
            </div>
            <div class="card-body">

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle" style="margin-top:2px;"></i>
                        <ul>
                            <?php foreach ($errors as $e): ?>
                                <li><?= htmlspecialchars($e); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST"
                      action="online-school-registration.php?school=<?= urlencode($school['school_code']); ?>"
                      id="regForm"
                      autocomplete="off">

                    <div class="section">
                        <div class="section-title"><i class="fas fa-user-graduate"></i> Student Details</div>
                        <div class="grid-2">
                            <div class="form-group">
                                <label for="first_name">First Name <span class="req">*</span></label>
                                <input type="text" id="first_name" name="first_name" maxlength="80" required
                                       value="<?= htmlspecialchars($_POST['first_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="last_name">Last Name <span class="req">*</span></label>
                                <input type="text" id="last_name" name="last_name" maxlength="80" required
                                       value="<?= htmlspecialchars($_POST['last_name'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label for="dob">Date of Birth <span class="req">*</span></label>
                                <input type="date" id="dob" name="dob" required max="<?= date('Y-m-d'); ?>"
                                       value="<?= htmlspecialchars($_POST['dob'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Sex <span class="req">*</span></label>
                                <div class="pill-group" data-group="sex">
                                    <label class="pill-opt <?= (($_POST['sex'] ?? '') === 'Male') ? 'selected' : ''; ?>">
                                        <input type="radio" name="sex" value="Male" <?= (($_POST['sex'] ?? '') === 'Male') ? 'checked' : ''; ?> required>
                                        <i class="fas fa-mars"></i> Male
                                    </label>
                                    <label class="pill-opt <?= (($_POST['sex'] ?? '') === 'Female') ? 'selected' : ''; ?>">
                                        <input type="radio" name="sex" value="Female" <?= (($_POST['sex'] ?? '') === 'Female') ? 'checked' : ''; ?> required>
                                        <i class="fas fa-venus"></i> Female
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <div class="section-title"><i class="fas fa-map-marker-alt"></i> Address</div>
                        <div class="form-group">
                            <label for="country">Country <span class="req">*</span></label>
                            <select id="country" name="country" required>
                                <option value="">— Select country —</option>
                                <?php
                                    $countries = ['Rwanda','Burundi','Uganda','Tanzania','Kenya','DR Congo','Other'];
                                    $sel_country = $_POST['country'] ?? 'Rwanda';
                                    foreach ($countries as $c) {
                                        $sel = ($sel_country === $c) ? 'selected' : '';
                                        echo "<option value=\"" . htmlspecialchars($c) . "\" {$sel}>" . htmlspecialchars($c) . "</option>";
                                    }
                                ?>
                            </select>
                        </div>

                        <div class="rwanda-block" id="rwandaBlock">
                            <div class="grid-3">
                                <div class="form-group">
                                    <label for="province">Province <span class="req">*</span></label>
                                    <select id="province" name="province">
                                        <option value="">— Select province —</option>
                                        <?php foreach (array_keys($rwanda) as $prov):
                                            $sel = (($_POST['province'] ?? '') === $prov) ? 'selected' : '';
                                            echo "<option value=\"" . htmlspecialchars($prov) . "\" {$sel}>" . htmlspecialchars($prov) . "</option>";
                                        endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="district">District <span class="req">*</span></label>
                                    <select id="district" name="district"><option value="">— Select district —</option></select>
                                </div>
                                <div class="form-group">
                                    <label for="sector">Sector <span class="req">*</span></label>
                                    <select id="sector" name="sector"><option value="">— Select sector —</option></select>
                                </div>
                            </div>
                            <div class="grid-2">
                                <div class="form-group">
                                    <label for="cell">Cell (optional)</label>
                                    <input type="text" id="cell" name="cell" maxlength="80"
                                           value="<?= htmlspecialchars($_POST['cell'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="village">Village (optional)</label>
                                    <input type="text" id="village" name="village" maxlength="80"
                                           value="<?= htmlspecialchars($_POST['village'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="foreign-block" id="foreignBlock">
                            <div class="form-group">
                                <label for="address_line">Full Address</label>
                                <textarea id="address_line" name="address_line" maxlength="200"
                                          placeholder="Street, city, region…"><?= htmlspecialchars($_POST['address_line'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <div class="section-title"><i class="fas fa-address-book"></i> Student Contact</div>
                        <div class="grid-2">
                            <div class="form-group">
                                <label for="phone">Phone Number</label>
                                <input type="tel" id="phone" name="phone" maxlength="40"
                                       value="<?= htmlspecialchars($_POST['phone'] ?? ''); ?>"
                                       placeholder="+250 7XX XXX XXX">
                                <div class="hint">At least one of phone or email is required.</div>
                            </div>
                            <div class="form-group">
                                <label for="email">Email Address</label>
                                <input type="email" id="email" name="email" maxlength="120"
                                       value="<?= htmlspecialchars($_POST['email'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <div class="section-title"><i class="fas fa-people-roof"></i> Parents / Guardian</div>

                        <div class="grid-3">
                            <div class="form-group">
                                <label for="father_name">Father's Name</label>
                                <input type="text" id="father_name" name="father_name" maxlength="120"
                                       value="<?= htmlspecialchars($_POST['father_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="father_phone">Father's Phone</label>
                                <input type="tel" id="father_phone" name="father_phone" maxlength="40"
                                       value="<?= htmlspecialchars($_POST['father_phone'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="father_email">Father's Email</label>
                                <input type="email" id="father_email" name="father_email" maxlength="120"
                                       value="<?= htmlspecialchars($_POST['father_email'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="grid-3">
                            <div class="form-group">
                                <label for="mother_name">Mother's Name</label>
                                <input type="text" id="mother_name" name="mother_name" maxlength="120"
                                       value="<?= htmlspecialchars($_POST['mother_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="mother_phone">Mother's Phone</label>
                                <input type="tel" id="mother_phone" name="mother_phone" maxlength="40"
                                       value="<?= htmlspecialchars($_POST['mother_phone'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="mother_email">Mother's Email</label>
                                <input type="email" id="mother_email" name="mother_email" maxlength="120"
                                       value="<?= htmlspecialchars($_POST['mother_email'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="grid-3">
                            <div class="form-group">
                                <label for="guardian_name">Guardian's Name</label>
                                <input type="text" id="guardian_name" name="guardian_name" maxlength="120"
                                       value="<?= htmlspecialchars($_POST['guardian_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="guardian_phone">Guardian's Phone</label>
                                <input type="tel" id="guardian_phone" name="guardian_phone" maxlength="40"
                                       value="<?= htmlspecialchars($_POST['guardian_phone'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="guardian_email">Guardian's Email</label>
                                <input type="email" id="guardian_email" name="guardian_email" maxlength="120"
                                       value="<?= htmlspecialchars($_POST['guardian_email'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <div class="section-title"><i class="fas fa-heart-pulse"></i> Health &amp; Special Needs</div>
                        <div class="form-group">
                            <label>Does the student have any disability?</label>
                            <div class="pill-group" data-group="disability">
                                <label class="pill-opt <?= (($_POST['disability'] ?? 'none') === 'none') ? 'selected' : ''; ?>">
                                    <input type="radio" name="disability" value="none" <?= (($_POST['disability'] ?? 'none') === 'none') ? 'checked' : ''; ?>>
                                    <i class="fas fa-check"></i> No
                                </label>
                                <label class="pill-opt <?= (($_POST['disability'] ?? '') === 'yes') ? 'selected' : ''; ?>">
                                    <input type="radio" name="disability" value="yes" <?= (($_POST['disability'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                                    <i class="fas fa-wheelchair"></i> Yes
                                </label>
                            </div>
                        </div>
                        <div class="form-group" id="disabilityNoteWrap" style="<?= (($_POST['disability'] ?? '') === 'yes') ? '' : 'display:none;'; ?>">
                            <label for="disability_note">Please describe the disability</label>
                            <textarea id="disability_note" name="disability_note" maxlength="255"
                                      placeholder="Briefly describe the student's needs..."><?= htmlspecialchars($_POST['disability_note'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="section">
                        <div class="section-title"><i class="fas fa-graduation-cap"></i> Academic Placement</div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label for="previous_school">Previous School (if any)</label>
                                <input type="text" id="previous_school" name="previous_school" maxlength="160"
                                       placeholder="Name of school or leave blank"
                                       value="<?= htmlspecialchars($_POST['previous_school'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="previous_marks">Previous Marks</label>
                                <input type="text" id="previous_marks" name="previous_marks" maxlength="40"
                                       placeholder="e.g. 78% / Division II"
                                       value="<?= htmlspecialchars($_POST['previous_marks'] ?? ''); ?>">
                                <div class="hint">Required only if transferring from another school.</div>
                            </div>
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label for="level">Academic Level <span class="req">*</span></label>
                                <select id="level" name="level" required>
                                    <option value="">— Select level —</option>
                                    <?php foreach ($levels as $lv):
                                        $enum = level_key_to_enum($lv['level_key']);
                                        if (!$enum) continue;
                                        if (!empty($levels_list) && !in_array($enum, $levels_list, true)) continue;
                                        $disp = $level_map[$enum] ?? $lv['level_name'];
                                        $sel = (($_POST['level'] ?? '') === $enum) ? 'selected' : '';
                                        echo "<option value=\"" . htmlspecialchars($enum) . "\" {$sel}>" . htmlspecialchars($disp) . "</option>";
                                    endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="class_id">Class <span class="req">*</span></label>
                                <select id="class_id" name="class_id" required>
                                    <option value="">— Choose a level first —</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <div class="section-title"><i class="fas fa-bell"></i> Default Contact</div>
                        <div class="form-group">
                            <label>Where should the school send announcements? <span class="req">*</span></label>
                            <div class="pill-group" data-group="preferred_contact">
                                <label class="pill-opt <?= (($_POST['preferred_contact'] ?? '') === 'phone') ? 'selected' : ''; ?>">
                                    <input type="radio" name="preferred_contact" value="phone" <?= (($_POST['preferred_contact'] ?? '') === 'phone') ? 'checked' : ''; ?> required>
                                    <i class="fas fa-phone"></i> Phone
                                </label>
                                <label class="pill-opt <?= (($_POST['preferred_contact'] ?? '') === 'email') ? 'selected' : ''; ?>">
                                    <input type="radio" name="preferred_contact" value="email" <?= (($_POST['preferred_contact'] ?? '') === 'email') ? 'checked' : ''; ?> required>
                                    <i class="fas fa-envelope"></i> Email
                                </label>
                            </div>
                            <div class="hint">You can change this later. We'll use it to reach you about this registration and future announcements.</div>
                        </div>
                    </div>

                    <div class="btn-row">
                        <a href="online-registration.php?school=<?= urlencode($school['school_code']); ?>" class="btn">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                        <button type="submit" class="btn primary" id="submitBtn">
                            <i class="fas fa-paper-plane"></i> Submit Registration
                        </button>
                    </div>

                </form>
            </div>

        <?php else: ?>

            <div class="card-head">
                <h1>Registration Unavailable</h1>
                <p>We can't proceed with online registration for this school.</p>
            </div>
            <div class="card-body">
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle" style="margin-top:2px;"></i>
                    <ul>
                        <?php foreach ($errors as $e): ?>
                            <li><?= htmlspecialchars($e); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="btn-row" style="justify-content:center;">
                    <a href="online-registration.php" class="btn">
                        <i class="fas fa-arrow-left"></i> Enter a different code
                    </a>
                    <a href="index.php" class="btn primary">
                        <i class="fas fa-home"></i> Back to Home
                    </a>
                </div>
            </div>

        <?php endif; ?>

    </div>

    <div class="foot">Powered by Educ Bridge</div>
</div>

<script>
/* Theme toggle */
(function () {
    const html = document.documentElement;
    const t = document.getElementById('themeToggle');
    const i = t.querySelector('i');
    function apply(theme) {
        html.setAttribute('data-theme', theme);
        localStorage.setItem('educbridge-theme', theme);
        if (theme === 'dark') { i.classList.remove('fa-moon'); i.classList.add('fa-sun'); }
        else { i.classList.remove('fa-sun'); i.classList.add('fa-moon'); }
    }
    apply(localStorage.getItem('educbridge-theme') || 'light');
    t.addEventListener('click', function () {
        apply(html.getAttribute('data-theme') === 'light' ? 'dark' : 'light');
    });
})();

/* Radio pill groups */
document.querySelectorAll('.pill-group').forEach(function (group) {
    group.querySelectorAll('.pill-opt').forEach(function (pill) {
        pill.addEventListener('click', function () {
            group.querySelectorAll('.pill-opt').forEach(p => p.classList.remove('selected'));
            pill.classList.add('selected');
            const radio = pill.querySelector('input');
            if (radio) radio.checked = true;
            if (radio && radio.name === 'disability') {
                const noteWrap = document.getElementById('disabilityNoteWrap');
                if (noteWrap) noteWrap.style.display = (radio.value === 'yes') ? 'block' : 'none';
            }
        });
    });
});

/* Rwanda cascade */
const RWANDA = <?= json_encode($rwanda); ?>;
(function () {
    const countrySelect  = document.getElementById('country');
    const rwandaBlock    = document.getElementById('rwandaBlock');
    const foreignBlock   = document.getElementById('foreignBlock');
    const provinceSelect = document.getElementById('province');
    const districtSelect = document.getElementById('district');
    const sectorSelect   = document.getElementById('sector');
    if (!countrySelect) return;

    function toggleAddress() {
        if (countrySelect.value === 'Rwanda') {
            rwandaBlock.classList.add('show');
            foreignBlock.classList.remove('show');
        } else {
            rwandaBlock.classList.remove('show');
            foreignBlock.classList.add('show');
        }
    }

    function fillDistricts(province, keep) {
        districtSelect.innerHTML = '<option value="">— Select district —</option>';
        sectorSelect.innerHTML   = '<option value="">— Select sector —</option>';
        if (!RWANDA[province]) return;
        Object.keys(RWANDA[province]).forEach(function (d) {
            const o = document.createElement('option');
            o.value = d; o.textContent = d;
            if (keep && keep === d) o.selected = true;
            districtSelect.appendChild(o);
        });
    }

    function fillSectors(province, district, keep) {
        sectorSelect.innerHTML = '<option value="">— Select sector —</option>';
        if (!RWANDA[province] || !RWANDA[province][district]) return;
        RWANDA[province][district].forEach(function (s) {
            const o = document.createElement('option');
            o.value = s; o.textContent = s;
            if (keep && keep === s) o.selected = true;
            sectorSelect.appendChild(o);
        });
    }

    const preProvince = <?= json_encode($_POST['province'] ?? ''); ?>;
    const preDistrict = <?= json_encode($_POST['district'] ?? ''); ?>;
    const preSector   = <?= json_encode($_POST['sector']   ?? ''); ?>;

    if (preProvince) {
        provinceSelect.value = preProvince;
        fillDistricts(preProvince, preDistrict);
        if (preDistrict) fillSectors(preProvince, preDistrict, preSector);
    }

    countrySelect.addEventListener('change', toggleAddress);
    provinceSelect.addEventListener('change', function () { fillDistricts(this.value); });
    districtSelect.addEventListener('change', function () { fillSectors(provinceSelect.value, this.value); });

    toggleAddress();
})();

/* Level → class filter */
const CLASSES = <?= json_encode($classes); ?>;
(function () {
    const levelSelect = document.getElementById('level');
    const classSelect = document.getElementById('class_id');
    if (!levelSelect || !classSelect) return;

    function fillClasses(level, keep) {
        classSelect.innerHTML = '<option value="">— Choose a class —</option>';
        if (!level) return;
        CLASSES
            .filter(c => c.level === level)
            .forEach(function (c) {
                const o = document.createElement('option');
                o.value = c.id;
                o.textContent = c.class_name;
                if (keep && parseInt(keep) === parseInt(c.id)) o.selected = true;
                classSelect.appendChild(o);
            });
    }

    const preLevel = <?= json_encode($_POST['level'] ?? ''); ?>;
    const preClass = <?= json_encode((string)($_POST['class_id'] ?? '')); ?>;

    if (preLevel) fillClasses(preLevel, preClass);

    levelSelect.addEventListener('change', function () { fillClasses(this.value); });
})();

/* Submit button feedback */
const _form = document.getElementById('regForm');
if (_form) {
    _form.addEventListener('submit', function () {
        const btn = document.getElementById('submitBtn');
        if (!btn || btn.disabled) return;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    });
}
</script>

</body>
</html>