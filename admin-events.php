<?php
session_start();
require_once 'db.php';

/* AUTH */
if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_verified'])) {
    header("Location: admin-login.php");
    exit;
}

$admin_id   = (int) $_SESSION['admin_id'];
$admin_name = $_SESSION['admin_name'] ?? 'System Admin';

$initials = "";
foreach (explode(" ", trim($admin_name)) as $part) {
    if ($part !== "") { $initials .= strtoupper($part[0]); if (strlen($initials) >= 2) break; }
}
if ($initials === "") $initials = "A";

if (isset($_GET['logout'])) {
    session_unset(); session_destroy();
    header("Location: admin-login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| AUTO-CREATE TABLES
|--------------------------------------------------------------------------
*/
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS admin_events (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    admin_id          INT DEFAULT NULL,
    title             VARCHAR(200) NOT NULL,
    description       TEXT,
    event_date        DATE NOT NULL,
    start_time        TIME DEFAULT NULL,
    end_time          TIME DEFAULT NULL,
    location          VARCHAR(200) DEFAULT NULL,
    priority          ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    status            ENUM('scheduled','in_progress','done','cancelled') NOT NULL DEFAULT 'scheduled',
    as_announcement   TINYINT(1) NOT NULL DEFAULT 0,
    announcement_id   INT DEFAULT NULL,
    audience          ENUM('schools','parents','all') DEFAULT NULL,
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ev_date (event_date),
    KEY idx_ev_admin (admin_id),
    KEY idx_ev_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* Announcement tables (mirror admin-announcements.php) */
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS admin_announcements (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    admin_id        INT DEFAULT NULL,
    sender_name     VARCHAR(150) NOT NULL DEFAULT 'System',
    title           VARCHAR(200) NOT NULL,
    body            TEXT NOT NULL,
    priority        ENUM('normal','high','urgent') NOT NULL DEFAULT 'normal',
    audience        ENUM('schools','parents','all') NOT NULL DEFAULT 'all',
    scope_type      ENUM('all','school','parent') NOT NULL DEFAULT 'all',
    scope_school_id INT DEFAULT NULL,
    scope_parent_id INT DEFAULT NULL,
    total_recipients INT NOT NULL DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS admin_announcement_recipients (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    announcement_id INT NOT NULL,
    recipient_type  ENUM('school','parent') NOT NULL,
    recipient_id    INT NOT NULL,
    recipient_name  VARCHAR(150) DEFAULT NULL,
    is_read         TINYINT(1) NOT NULL DEFAULT 0,
    read_at         DATETIME DEFAULT NULL,
    delivered_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/*
|--------------------------------------------------------------------------
| SCHOOLS NAMING
|--------------------------------------------------------------------------
*/
$school_name_col = 'school_name';
foreach (['school_name','name','title','school'] as $c) {
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM schools LIKE '" . mysqli_real_escape_string($conn, $c) . "'");
    if ($chk && mysqli_num_rows($chk) > 0) { $school_name_col = $c; break; }
}
$school_has_status = false;
$chk = @mysqli_query($conn, "SHOW COLUMNS FROM schools LIKE 'status'");
if ($chk && mysqli_num_rows($chk) > 0) $school_has_status = true;

/*
|--------------------------------------------------------------------------
| HANDLE CREATE
|--------------------------------------------------------------------------
*/
$flash = ""; $flash_type = "success";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_event') {
    $title        = trim($_POST['title'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $event_date   = trim($_POST['event_date'] ?? '');
    $start_time   = trim($_POST['start_time'] ?? '');
    $end_time     = trim($_POST['end_time'] ?? '');
    $location     = trim($_POST['location'] ?? '');
    $priority     = $_POST['priority'] ?? 'normal';
    $status       = $_POST['status']   ?? 'scheduled';
    $as_ann       = !empty($_POST['as_announcement']) ? 1 : 0;
    $audience     = $_POST['audience'] ?? 'all';

    $errors = [];
    if ($title === '' || mb_strlen($title) < 2)  $errors[] = "Title is required.";
    if ($event_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $event_date)) $errors[] = "Event date is required.";
    if (!in_array($priority, ['low','normal','high','urgent'], true)) $priority = 'normal';
    if (!in_array($status,   ['scheduled','in_progress','done','cancelled'], true)) $status = 'scheduled';
    if (!in_array($audience, ['schools','parents','all'], true)) $audience = 'all';

    if (empty($errors)) {
        mysqli_begin_transaction($conn);
        try {
            $ann_id = null;

            /* If also an announcement, create it first */
            if ($as_ann) {
                /* Map event priority to announcement priority */
                $ann_prio = 'normal';
                if ($priority === 'urgent') $ann_prio = 'urgent';
                elseif ($priority === 'high') $ann_prio = 'high';

                /* Resolve recipients */
                $recipients = [];
                if ($audience === 'schools' || $audience === 'all') {
                    $wsql = $school_has_status ? " WHERE status = 'active' " : "";
                    $rr = mysqli_query($conn, "SELECT id, `{$school_name_col}` AS name FROM schools {$wsql}");
                    if ($rr) {
                        while ($row = mysqli_fetch_assoc($rr)) {
                            $recipients[] = ['type'=>'school','id'=>(int)$row['id'],'name'=>$row['name'] ?: '(Unnamed school)'];
                        }
                    }
                }
                if ($audience === 'parents' || $audience === 'all') {
                    $has_parents = false;
                    $t = @mysqli_query($conn, "SHOW TABLES LIKE 'parents'");
                    if ($t && mysqli_num_rows($t) > 0) $has_parents = true;
                    if ($has_parents) {
                        $pname = 'name';
                        foreach (['name','full_name','parent_name'] as $c) {
                            $chk = @mysqli_query($conn, "SHOW COLUMNS FROM parents LIKE '" . mysqli_real_escape_string($conn, $c) . "'");
                            if ($chk && mysqli_num_rows($chk) > 0) { $pname = $c; break; }
                        }
                        $rr = mysqli_query($conn, "SELECT id, `{$pname}` AS name FROM parents");
                        if ($rr) {
                            while ($row = mysqli_fetch_assoc($rr)) {
                                $recipients[] = ['type'=>'parent','id'=>(int)$row['id'],'name'=>$row['name'] ?: '(Unnamed parent)'];
                            }
                        }
                    }
                }

                $total_rec = count($recipients);
                $ann_body = $description !== '' ? $description : "Scheduled event on {$event_date}";
                if ($start_time) $ann_body .= "\n\n⏰ Starts at {$start_time}" . ($end_time ? " – {$end_time}" : '');
                if ($location)   $ann_body .= "\n📍 {$location}";

                $ins = mysqli_prepare($conn, "
                    INSERT INTO admin_announcements
                        (admin_id, sender_name, title, body, priority, audience,
                         scope_type, scope_school_id, scope_parent_id, total_recipients)
                    VALUES (?, ?, ?, ?, ?, ?, 'all', NULL, NULL, ?)
                ");
                if (!$ins) throw new Exception("Announcement insert prepare failed: " . mysqli_error($conn));
                mysqli_stmt_bind_param($ins, "isssssi",
                    $admin_id, $admin_name, $title, $ann_body, $ann_prio, $audience, $total_rec);
                if (!mysqli_stmt_execute($ins)) throw new Exception("Announcement insert failed: " . mysqli_stmt_error($ins));
                $ann_id = (int) mysqli_insert_id($conn);
                mysqli_stmt_close($ins);

                if ($total_rec > 0) {
                    $ins_rec = mysqli_prepare($conn, "
                        INSERT INTO admin_announcement_recipients
                            (announcement_id, recipient_type, recipient_id, recipient_name)
                        VALUES (?, ?, ?, ?)
                    ");
                    if (!$ins_rec) throw new Exception("Recipients prepare failed: " . mysqli_error($conn));
                    foreach ($recipients as $rec) {
                        mysqli_stmt_bind_param($ins_rec, "isis", $ann_id, $rec['type'], $rec['id'], $rec['name']);
                        mysqli_stmt_execute($ins_rec);
                    }
                    mysqli_stmt_close($ins_rec);
                }
            }

            /* Insert event */
            $ins_e = mysqli_prepare($conn, "
                INSERT INTO admin_events
                    (admin_id, title, description, event_date, start_time, end_time, location,
                     priority, status, as_announcement, announcement_id, audience)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$ins_e) throw new Exception("Event insert prepare failed: " . mysqli_error($conn));

            $st_val  = $start_time !== '' ? $start_time : null;
            $en_val  = $end_time   !== '' ? $end_time   : null;
            $loc_val = $location   !== '' ? $location   : null;
            $aud_val = $as_ann ? $audience : null;

            mysqli_stmt_bind_param($ins_e, "issssssssiii",
                $admin_id, $title, $description, $event_date, $st_val, $en_val, $loc_val,
                $priority, $status, $as_ann, $ann_id, $aud_val);
            if (!mysqli_stmt_execute($ins_e)) throw new Exception("Event insert failed: " . mysqli_stmt_error($ins_e));
            mysqli_stmt_close($ins_e);

            mysqli_commit($conn);
            $q = $as_ann ? '&announced=1' : '';
            header("Location: admin-events.php?created=1{$q}");
            exit;

        } catch (Exception $e) {
            mysqli_rollback($conn);
            $flash = "Could not save: " . $e->getMessage();
            $flash_type = "error";
        }
    }
}

if (($_GET['created'] ?? '') === '1' && $flash === '') {
    $flash = "Event created." . (!empty($_GET['announced']) ? " Announcement was also sent." : "");
    $flash_type = "success";
}

/*
|--------------------------------------------------------------------------
| FILTERS + FETCH
|--------------------------------------------------------------------------
*/
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$month_start = $month . '-01';
$month_end   = date('Y-m-t', strtotime($month_start));

$status_filter = $_GET['status'] ?? 'all';
if (!in_array($status_filter, ['all','scheduled','in_progress','done','cancelled'], true)) $status_filter = 'all';

$where  = ["event_date BETWEEN ? AND ?"];
$params = [$month_start, $month_end];
$types  = "ss";

if ($status_filter !== 'all') {
    $where[] = "status = ?";
    $params[] = $status_filter;
    $types   .= "s";
}

$events = [];
$sql = "SELECT * FROM admin_events WHERE " . implode(" AND ", $where) . " ORDER BY event_date ASC, start_time ASC";
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) $events[] = $row;
    mysqli_stmt_close($stmt);
}

/* Group by date */
$events_by_date = [];
foreach ($events as $ev) {
    $events_by_date[$ev['event_date']][] = $ev;
}

/* Counters for the KPI row */
$total_month = count($events);
$total_scheduled = 0; $total_done = 0; $total_urgent = 0; $total_announced = 0;
foreach ($events as $ev) {
    if ($ev['status'] === 'scheduled') $total_scheduled++;
    if ($ev['status'] === 'done')      $total_done++;
    if ($ev['priority'] === 'urgent')  $total_urgent++;
    if ((int)$ev['as_announcement'] === 1) $total_announced++;
}

/* Upcoming (from today forward, across all months) */
$upcoming = [];
$up_res = mysqli_query($conn, "
    SELECT * FROM admin_events
    WHERE event_date >= CURDATE() AND status IN ('scheduled','in_progress')
    ORDER BY event_date ASC, start_time ASC
    LIMIT 6
");
if ($up_res) { while ($r = mysqli_fetch_assoc($up_res)) $upcoming[] = $r; }

$priority_map = [
    'low'    => ['label'=>'Low',    'class'=>'gray'],
    'normal' => ['label'=>'Normal', 'class'=>'blue'],
    'high'   => ['label'=>'High',   'class'=>'orange'],
    'urgent' => ['label'=>'Urgent', 'class'=>'red'],
];
$status_map = [
    'scheduled'   => ['label'=>'Scheduled',   'class'=>'blue'],
    'in_progress' => ['label'=>'In Progress', 'class'=>'orange'],
    'done'        => ['label'=>'Done',        'class'=>'green'],
    'cancelled'   => ['label'=>'Cancelled',   'class'=>'gray'],
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Events | Educ Bridge Admin</title>
<link rel="icon" type="image/png" href="logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root{
        --primary:#7c3aed;--primary-dark:#6d28d9;--primary-light:#ede9fe;
        --success:#10b981;--success-light:rgba(16,185,129,.1);
        --warning:#f59e0b;--warning-light:rgba(245,158,11,.12);
        --danger:#ef4444;--danger-light:rgba(239,68,68,.1);
        --info:#0ea5e9;
        --sidebar-bg:#0f172a;--sidebar-hover:rgba(255,255,255,.06);--sidebar-active:rgba(124,58,237,.22);
        --sidebar-text:#cbd5e1;--sidebar-text-active:#fff;--sidebar-border:rgba(255,255,255,.08);
        --bg-body:#f8fafc;--bg-card:#fff;--bg-alt:#f1f5f9;
        --text-primary:#0f172a;--text-secondary:#475569;--text-muted:#64748b;
        --border-color:#e2e8f0;
        --shadow-sm:0 1px 3px rgba(0,0,0,.06);--shadow-md:0 4px 12px rgba(0,0,0,.07);
        --shadow-lg:0 20px 45px rgba(0,0,0,.08);
        --transition:all .3s cubic-bezier(.4,0,.2,1);
    }
    [data-theme="dark"]{
        --primary:#a78bfa;--primary-dark:#8b5cf6;--primary-light:#2e1065;
        --success:#34d399;--success-light:rgba(16,185,129,.15);
        --warning:#fbbf24;--warning-light:rgba(245,158,11,.18);
        --danger:#f87171;--danger-light:rgba(239,68,68,.15);
        --info:#38bdf8;
        --sidebar-bg:#0b1120;--sidebar-hover:rgba(255,255,255,.06);--sidebar-active:rgba(167,139,250,.2);
        --sidebar-text:#cbd5e1;--sidebar-text-active:#fff;--sidebar-border:rgba(255,255,255,.08);
        --bg-body:#0b1120;--bg-card:#1e293b;--bg-alt:#1a2332;
        --text-primary:#f1f5f9;--text-secondary:#cbd5e1;--text-muted:#94a3b8;
        --border-color:#334155;
        --shadow-sm:0 1px 3px rgba(0,0,0,.3);--shadow-md:0 4px 12px rgba(0,0,0,.4);
        --shadow-lg:0 20px 45px rgba(0,0,0,.5);
    }
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
    html,body{min-height:100vh}
    body{font-family:'Inter',sans-serif;background:var(--bg-body);color:var(--text-primary);transition:background .4s ease,color .4s ease}
    .layout{display:flex;min-height:100vh}

    .sidebar{width:260px;background:var(--sidebar-bg);color:var(--sidebar-text);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:100;border-right:1px solid var(--sidebar-border);transition:transform .3s ease}
    .sidebar-header{padding:22px 22px 18px;border-bottom:1px solid var(--sidebar-border);display:flex;align-items:center;gap:12px}
    .sidebar-header img{width:42px;height:42px;object-fit:contain;border-radius:50%;border:2px solid rgba(255,255,255,.15);padding:2px;background:#fff}
    .sidebar-brand{font-family:'Poppins',sans-serif;font-size:18px;font-weight:700;color:#fff;line-height:1.2}
    .sidebar-brand span{color:var(--primary)}
    .sidebar-brand small{display:block;font-size:11px;color:var(--sidebar-text);letter-spacing:.4px;margin-top:2px;text-transform:uppercase}
    .sidebar-nav{flex:1;padding:18px 12px;overflow-y:auto}
    .nav-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--sidebar-text);opacity:.6;padding:12px 14px 8px}
    .nav-item{display:flex;align-items:center;gap:12px;padding:11px 14px;color:var(--sidebar-text);text-decoration:none;font-size:14px;font-weight:500;border-radius:10px;margin-bottom:4px;transition:var(--transition);position:relative}
    .nav-item i.nav-icon{width:20px;text-align:center;font-size:15px}
    .nav-item:hover{background:var(--sidebar-hover);color:#fff}
    .nav-item:hover i.nav-icon{color:var(--primary)}
    .nav-item.active{background:var(--sidebar-active);color:#fff}
    .nav-item.active i.nav-icon{color:#c4b5fd}
    .nav-item.active::before{content:'';position:absolute;left:0;top:8px;bottom:8px;width:3px;background:var(--primary);border-radius:0 4px 4px 0}
    .sidebar-profile{border-top:1px solid var(--sidebar-border);padding:16px 18px;display:flex;align-items:center;gap:12px}
    .sidebar-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#7c3aed,#a78bfa);color:#fff;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700}
    .sidebar-profile-info{flex:1;min-width:0}
    .sidebar-profile-info .p-name{font-size:13.5px;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sidebar-profile-info .p-role{font-size:11.5px;color:var(--sidebar-text);opacity:.75;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sidebar-logout{width:34px;height:34px;border-radius:8px;background:rgba(239,68,68,.15);color:#f87171;display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:13px}
    .sidebar-logout:hover{background:var(--danger);color:#fff}

    .main{flex:1;margin-left:260px;min-height:100vh;display:flex;flex-direction:column}
    .topbar{background:var(--bg-card);border-bottom:1px solid var(--border-color);padding:14px 28px;display:flex;align-items:center;justify-content:space-between;gap:16px;position:sticky;top:0;z-index:50}
    .page-title{font-family:'Poppins',sans-serif;font-size:20px;font-weight:700}
    .page-subtitle{font-size:13px;color:var(--text-muted);margin-top:2px}
    .topbar-actions{display:flex;align-items:center;gap:10px}
    .topbar-btn{width:42px;height:42px;border-radius:50%;border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-secondary);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:15px;text-decoration:none}
    .topbar-btn:hover{border-color:var(--primary);color:var(--primary)}
    .topbar-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#7c3aed,#a78bfa);color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700}
    .hamburger{display:none;width:40px;height:40px;border-radius:10px;border:1.5px solid var(--border-color);background:var(--bg-body);color:var(--text-secondary);align-items:center;justify-content:center;cursor:pointer}

    .content{padding:28px;flex:1}

    .alert{padding:13px 16px;border-radius:10px;margin-bottom:20px;font-size:14px;display:flex;align-items:flex-start;gap:10px;line-height:1.55}
    .alert-success{background:var(--success-light);color:#059669;border:1px solid rgba(16,185,129,.25)}
    [data-theme="dark"] .alert-success{color:#34d399}
    .alert-error{background:var(--danger-light);color:#dc2626;border:1px solid rgba(239,68,68,.25)}
    [data-theme="dark"] .alert-error{color:#f87171}

    .page-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px;flex-wrap:wrap}
    .page-head h1{font-family:'Poppins',sans-serif;font-size:22px;font-weight:700;display:flex;align-items:center;gap:10px}
    .page-head h1 i{color:var(--primary)}
    .page-head .sub{font-size:13.5px;color:var(--text-muted);margin-top:4px}

    /* KPI */
    .kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-bottom:22px}
    .kpi-card{background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px;padding:18px 20px;display:flex;align-items:center;gap:14px;transition:var(--transition)}
    .kpi-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);border-color:var(--primary)}
    .kpi-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;flex-shrink:0}
    .kpi-icon.violet{background:linear-gradient(135deg,#7c3aed,#a78bfa)}
    .kpi-icon.blue{background:linear-gradient(135deg,#2563eb,#3b82f6)}
    .kpi-icon.green{background:linear-gradient(135deg,#10b981,#34d399)}
    .kpi-icon.red{background:linear-gradient(135deg,#ef4444,#f87171)}
    .kpi-icon.orange{background:linear-gradient(135deg,#f59e0b,#fbbf24)}
    .kpi-info .num{font-family:'Poppins',sans-serif;font-size:24px;font-weight:700;line-height:1.15}
    .kpi-info .lbl{font-size:12.5px;color:var(--text-muted);margin-top:3px;font-weight:500}

    /* Layout */
    .ev-layout{display:grid;grid-template-columns:1fr 400px;gap:22px;align-items:start}
    @media (max-width:1200px){.ev-layout{grid-template-columns:1fr}}

    .panel{background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px;overflow:hidden}
    .panel-head{padding:18px 22px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;background:linear-gradient(135deg,rgba(124,58,237,.05),rgba(59,130,246,.02))}
    .panel-head h2{font-family:'Poppins',sans-serif;font-size:16px;font-weight:700;display:flex;align-items:center;gap:10px}
    .panel-head h2 i{color:var(--primary)}
    .panel-body{padding:20px 22px}

    /* Filters */
    .filters{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px}
    .filter-group{position:relative;display:flex;align-items:center;min-width:180px}
    .filter-group i{position:absolute;left:13px;color:var(--text-muted);font-size:13px;pointer-events:none}
    .filter-group input,.filter-group select{padding:10px 14px 10px 36px;border:1.5px solid var(--border-color);border-radius:10px;background:var(--bg-alt);color:var(--text-primary);font-family:inherit;font-size:13.5px;outline:none;transition:var(--transition)}
    .filter-group input:focus,.filter-group select:focus{border-color:var(--primary);background:var(--bg-card);box-shadow:0 0 0 3px rgba(124,58,237,.12)}
    .filter-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;font-size:13.5px;font-weight:600;cursor:pointer;border:none;background:var(--primary);color:#fff;font-family:inherit;text-decoration:none}
    .filter-btn:hover{background:var(--primary-dark)}
    .filter-reset{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;font-size:13.5px;font-weight:600;border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);text-decoration:none}

    /* Day groups */
    .days-list{display:flex;flex-direction:column;gap:18px}
    .day-group{background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px;overflow:hidden}
    .day-head{display:flex;align-items:center;gap:14px;padding:14px 20px;background:var(--bg-alt);border-bottom:1px solid var(--border-color)}
    .day-chip{width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,#7c3aed,#a78bfa);color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 6px 16px rgba(124,58,237,.3)}
    .day-chip .dc-d{font-family:'Poppins',sans-serif;font-size:20px;font-weight:800;line-height:1}
    .day-chip .dc-m{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;opacity:.9;margin-top:2px}
    .day-chip.today{background:linear-gradient(135deg,#10b981,#34d399);box-shadow:0 6px 16px rgba(16,185,129,.3)}
    .day-title{font-family:'Poppins',sans-serif;font-size:16px;font-weight:700;margin-bottom:3px}
    .day-sub{font-size:12.5px;color:var(--text-muted)}
    .day-count{margin-left:auto;background:var(--primary-light);color:var(--primary-dark);padding:5px 12px;border-radius:50px;font-size:12px;font-weight:800}
    [data-theme="dark"] .day-count{background:rgba(167,139,250,.2);color:#c4b5fd}

    /* Event cards */
    .ev-list{padding:14px 16px;display:flex;flex-direction:column;gap:10px}
    .ev-card{background:var(--bg-card);border:1px solid var(--border-color);border-radius:12px;padding:14px 16px;display:flex;align-items:flex-start;gap:14px;transition:var(--transition);position:relative;overflow:hidden}
    .ev-card::before{content:'';position:absolute;left:0;top:8px;bottom:8px;width:3px;background:var(--primary)}
    .ev-card.prio-low::before{background:#94a3b8}
    .ev-card.prio-normal::before{background:#2563eb}
    .ev-card.prio-high::before{background:#f59e0b}
    .ev-card.prio-urgent::before{background:#ef4444}
    .ev-card:hover{border-color:var(--primary);transform:translateX(3px);box-shadow:var(--shadow-sm)}
    .ev-time{width:66px;text-align:center;flex-shrink:0}
    .ev-time .et-start{font-family:'Poppins',sans-serif;font-size:15px;font-weight:800;color:var(--text-primary)}
    .ev-time .et-end{font-size:11.5px;color:var(--text-muted);margin-top:2px}
    .ev-info{flex:1;min-width:0}
    .ev-title{font-size:14.5px;font-weight:700;color:var(--text-primary);margin-bottom:4px}
    .ev-desc{font-size:13px;color:var(--text-muted);line-height:1.55;margin-bottom:8px}
    .ev-meta{display:flex;flex-wrap:wrap;gap:8px;font-size:11.5px;align-items:center}
    .pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:50px;font-size:11px;font-weight:700;line-height:1.5}
    .pill.violet{background:rgba(124,58,237,.12);color:#7c3aed}
    .pill.blue{background:rgba(37,99,235,.12);color:#2563eb}
    .pill.green{background:rgba(16,185,129,.12);color:#059669}
    .pill.orange{background:rgba(245,158,11,.14);color:#b45309}
    .pill.red{background:rgba(239,68,68,.12);color:#dc2626}
    .pill.gray{background:rgba(100,116,139,.14);color:#475569}
    [data-theme="dark"] .pill.violet{color:#c4b5fd}
    [data-theme="dark"] .pill.blue{color:#93c5fd}
    [data-theme="dark"] .pill.green{color:#34d399}
    [data-theme="dark"] .pill.orange{color:#fbbf24}
    [data-theme="dark"] .pill.red{color:#f87171}
    [data-theme="dark"] .pill.gray{color:#cbd5e1}

    /* Form */
    .compose-form .form-group{margin-bottom:16px}
    .compose-form label{display:block;font-size:12.5px;font-weight:700;color:var(--text-secondary);margin-bottom:7px;text-transform:uppercase;letter-spacing:.4px}
    .compose-form label .req{color:#ef4444}
    .compose-form input[type="text"],
    .compose-form input[type="date"],
    .compose-form input[type="time"],
    .compose-form textarea,
    .compose-form select{
        width:100%;padding:11px 14px;
        border:1.5px solid var(--border-color);border-radius:10px;
        background:var(--bg-alt);color:var(--text-primary);
        font-family:inherit;font-size:14px;outline:none;transition:var(--transition)
    }
    .compose-form textarea{resize:vertical;min-height:110px;line-height:1.55}
    .compose-form input:focus,.compose-form textarea:focus,.compose-form select:focus{
        border-color:var(--primary);background:var(--bg-card);box-shadow:0 0 0 3px rgba(124,58,237,.12)
    }
    .row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}

    /* Announcement integration block */
    .ann-block{
        margin-top:8px;padding:14px 16px;
        background:linear-gradient(135deg,rgba(124,58,237,.07),rgba(59,130,246,.04));
        border:1.5px dashed rgba(124,58,237,.35);border-radius:12px;
    }
    .ann-toggle{display:flex;align-items:flex-start;gap:12px;cursor:pointer}
    .ann-toggle input{position:absolute;opacity:0;pointer-events:none}
    .ann-switch{
        position:relative;width:44px;height:24px;border-radius:50px;background:var(--bg-alt);
        border:1.5px solid var(--border-color);flex-shrink:0;transition:var(--transition)
    }
    .ann-switch::after{
        content:'';position:absolute;top:2px;left:2px;width:18px;height:18px;
        border-radius:50%;background:#fff;box-shadow:0 2px 6px rgba(0,0,0,.2);transition:var(--transition)
    }
    .ann-toggle input:checked + .ann-switch{background:var(--primary);border-color:var(--primary)}
    .ann-toggle input:checked + .ann-switch::after{left:22px}
    .ann-text .ann-title{font-size:13.5px;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:6px}
    .ann-text .ann-title i{color:var(--primary)}
    .ann-text .ann-desc{font-size:12px;color:var(--text-muted);line-height:1.55;margin-top:3px}

    .ann-extra{display:none;margin-top:12px;padding-top:12px;border-top:1px dashed var(--border-color)}
    .ann-extra.show{display:block}

    .audience-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
    .audience-pill{
        position:relative;display:flex;flex-direction:column;align-items:center;gap:6px;
        padding:11px 6px;border:1.5px solid var(--border-color);border-radius:10px;
        background:var(--bg-card);cursor:pointer;font-size:12px;font-weight:700;color:var(--text-secondary)
    }
    .audience-pill input{position:absolute;opacity:0;pointer-events:none}
    .audience-pill i{font-size:15px;color:var(--text-muted)}
    .audience-pill.selected{border-color:var(--primary);background:var(--primary-light);color:var(--text-primary)}
    .audience-pill.selected i{color:var(--primary)}

    /* Priority pills */
    .priority-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:6px}
    .priority-pill{
        position:relative;display:flex;flex-direction:column;align-items:center;gap:4px;
        padding:9px 4px;border:1.5px solid var(--border-color);border-radius:9px;
        background:var(--bg-alt);cursor:pointer;font-size:11px;font-weight:700;color:var(--text-secondary)
    }
    .priority-pill input{position:absolute;opacity:0;pointer-events:none}
    .priority-pill i{font-size:13px}
    .priority-pill.low i{color:#94a3b8}
    .priority-pill.normal i{color:#2563eb}
    .priority-pill.high i{color:#f59e0b}
    .priority-pill.urgent i{color:#ef4444}
    .priority-pill.selected{border-color:var(--primary);background:var(--primary-light);color:var(--text-primary)}

    /* Status pills */
    .status-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:6px}
    .status-pill{
        position:relative;display:flex;flex-direction:column;align-items:center;gap:4px;
        padding:9px 4px;border:1.5px solid var(--border-color);border-radius:9px;
        background:var(--bg-alt);cursor:pointer;font-size:11px;font-weight:700;color:var(--text-secondary)
    }
    .status-pill input{position:absolute;opacity:0;pointer-events:none}
    .status-pill i{font-size:13px;color:var(--text-muted)}
    .status-pill.selected{border-color:var(--primary);background:var(--primary-light);color:var(--text-primary)}
    .status-pill.selected i{color:var(--primary)}

    .btn{display:inline-flex;align-items:center;gap:8px;padding:12px 22px;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);transition:var(--transition);font-family:inherit;text-decoration:none}
    .btn:hover{border-color:var(--primary);color:var(--primary)}
    .btn.primary{background:var(--primary);color:#fff;border-color:var(--primary);box-shadow:0 6px 18px rgba(124,58,237,.32);width:100%;justify-content:center}
    .btn.primary:hover{background:var(--primary-dark);color:#fff}
    .btn:disabled{opacity:.7;cursor:not-allowed}

    .empty-state{text-align:center;padding:50px 20px;color:var(--text-muted)}
    .empty-state i{font-size:44px;opacity:.35;margin-bottom:14px;display:block}
    .empty-state h3{font-family:'Poppins',sans-serif;font-size:15.5px;font-weight:700;color:var(--text-primary);margin-bottom:6px}
    .empty-state p{font-size:13px;line-height:1.6}

    /* Upcoming */
    .up-item{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:10px;background:var(--bg-alt);border:1px solid var(--border-color);text-decoration:none;color:inherit;margin-bottom:8px;transition:var(--transition)}
    .up-item:last-child{margin-bottom:0}
    .up-item:hover{border-color:var(--primary);transform:translateX(3px)}
    .up-date{width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#7c3aed,#a78bfa);color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0}
    .up-date .ud-d{font-family:'Poppins',sans-serif;font-size:16px;font-weight:800;line-height:1}
    .up-date .ud-m{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;opacity:.9;margin-top:1px}
    .up-info{flex:1;min-width:0}
    .up-title{font-size:13.5px;font-weight:700;color:var(--text-primary);margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .up-meta{font-size:11.5px;color:var(--text-muted)}

    @media (max-width:900px){
        .sidebar{transform:translateX(-100%);box-shadow:0 0 40px rgba(0,0,0,.2)}
        .sidebar.open{transform:translateX(0)}
        .main{margin-left:0}
        .hamburger{display:flex}
    }
    @media (max-width:600px){
        .content{padding:20px 16px}
        .topbar{padding:12px 16px}
        .page-title{font-size:17px}
        .page-head h1{font-size:18px}
        .row-2{grid-template-columns:1fr}
        .audience-grid{grid-template-columns:1fr}
    }
</style>
</head>
<body>

<div class="layout">

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="logo.png" alt="Educ Bridge">
            <div class="sidebar-brand">Educ<span>Bridge</span><small>System Admin</small></div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section-title">Main</div>
            <a href="admin-dashboard.php" class="nav-item"><i class="fas fa-th-large nav-icon"></i> Dashboard</a>
            <a href="admin-schools.php" class="nav-item"><i class="fas fa-school nav-icon"></i> Schools</a>
            <a href="admin-students.php" class="nav-item"><i class="fas fa-user-graduate nav-icon"></i> Students</a>
            <a href="admin-teachers.php" class="nav-item"><i class="fas fa-chalkboard-teacher nav-icon"></i> Teachers</a>

            <div class="nav-section-title">Management</div>
            <a href="admin-school-account.php" class="nav-item"><i class="fas fa-id-card nav-icon"></i> School Accounts</a>
            <a href="admin-events.php" class="nav-item active"><i class="fas fa-calendar-alt nav-icon"></i> Events</a>
            <a href="admin-announcements.php" class="nav-item"><i class="fas fa-bullhorn nav-icon"></i> Announcements</a>

            <div class="nav-section-title">System</div>
            <a href="admin-reports.php" class="nav-item"><i class="fas fa-chart-line nav-icon"></i> Reports</a>
            <a href="admin-settings.php" class="nav-item"><i class="fas fa-cog nav-icon"></i> Settings</a>
        </nav>
        <div class="sidebar-profile">
            <div class="sidebar-avatar"><?= htmlspecialchars($initials); ?></div>
            <div class="sidebar-profile-info">
                <div class="p-name"><?= htmlspecialchars($admin_name); ?></div>
                <div class="p-role">System Admin</div>
            </div>
            <a href="admin-dashboard.php?logout=1" class="sidebar-logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div style="display:flex;align-items:center;gap:14px">
                <button class="hamburger" id="hamburger"><i class="fas fa-bars"></i></button>
                <div>
                    <div class="page-title">Events</div>
                    <div class="page-subtitle">Day-by-day schedule · with optional announcement</div>
                </div>
            </div>
            <div class="topbar-actions">
                <button class="topbar-btn" id="themeToggle"><i class="fas fa-moon"></i></button>
                <div class="topbar-avatar"><?= htmlspecialchars($initials); ?></div>
            </div>
        </header>

        <main class="content">

            <?php if ($flash !== ''): ?>
                <div class="alert alert-<?= htmlspecialchars($flash_type); ?>">
                    <i class="fas <?= $flash_type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($flash); ?></span>
                </div>
            <?php endif; ?>

            <div class="page-head">
                <div>
                    <h1><i class="fas fa-calendar-alt"></i> Events & Schedule</h1>
                    <div class="sub">Plan tasks day by day — optionally broadcast each one as a system announcement.</div>
                </div>
            </div>

            <!-- KPI -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-icon violet"><i class="fas fa-calendar-day"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= $total_month; ?></div>
                        <div class="lbl">This Month</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon blue"><i class="fas fa-hourglass-half"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= $total_scheduled; ?></div>
                        <div class="lbl">Scheduled</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon red"><i class="fas fa-bolt"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= $total_urgent; ?></div>
                        <div class="lbl">Urgent</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon green"><i class="fas fa-circle-check"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= $total_done; ?></div>
                        <div class="lbl">Completed</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon orange"><i class="fas fa-bullhorn"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= $total_announced; ?></div>
                        <div class="lbl">Announced</div>
                    </div>
                </div>
            </div>

            <div class="ev-layout">

                <!-- LEFT: Events by day -->
                <div>
                    <div class="panel" style="margin-bottom:22px;">
                        <div class="panel-head">
                            <h2><i class="fas fa-list-check"></i> Schedule</h2>
                            <form method="GET" action="admin-events.php" class="filters" style="margin:0">
                                <div class="filter-group">
                                    <i class="fas fa-calendar"></i>
                                    <input type="month" name="month" value="<?= htmlspecialchars($month); ?>">
                                </div>
                                <div class="filter-group" style="min-width:150px">
                                    <i class="fas fa-toggle-on"></i>
                                    <select name="status">
                                        <option value="all"          <?= $status_filter==='all'?'selected':''; ?>>All Status</option>
                                        <option value="scheduled"    <?= $status_filter==='scheduled'?'selected':''; ?>>Scheduled</option>
                                        <option value="in_progress"  <?= $status_filter==='in_progress'?'selected':''; ?>>In Progress</option>
                                        <option value="done"         <?= $status_filter==='done'?'selected':''; ?>>Done</option>
                                        <option value="cancelled"    <?= $status_filter==='cancelled'?'selected':''; ?>>Cancelled</option>
                                    </select>
                                </div>
                                <button class="filter-btn" type="submit"><i class="fas fa-filter"></i> Apply</button>
                                <a class="filter-reset" href="admin-events.php"><i class="fas fa-rotate"></i> Reset</a>
                            </form>
                        </div>
                        <div class="panel-body">
                            <?php if (empty($events_by_date)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-calendar-xmark"></i>
                                    <h3>No events this month</h3>
                                    <p>Use the composer to add your first task.</p>
                                </div>
                            <?php else: ?>
                                <div class="days-list">
                                    <?php foreach ($events_by_date as $date => $list):
                                        $d_ts   = strtotime($date);
                                        $is_td  = ($date === date('Y-m-d'));
                                        $dn     = count($list);
                                    ?>
                                        <div class="day-group">
                                            <div class="day-head">
                                                <div class="day-chip <?= $is_td ? 'today' : ''; ?>">
                                                    <div class="dc-d"><?= date('d', $d_ts); ?></div>
                                                    <div class="dc-m"><?= date('M', $d_ts); ?></div>
                                                </div>
                                                <div>
                                                    <div class="day-title">
                                                        <?= $is_td ? 'Today' : date('l', $d_ts); ?>
                                                        <?php if (!$is_td): ?>
                                                            · <?= date('M j, Y', $d_ts); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="day-sub"><?= $dn; ?> event<?= $dn===1?'':'s'; ?> scheduled</div>
                                                </div>
                                                <div class="day-count"><?= $dn; ?> task<?= $dn===1?'':'s'; ?></div>
                                            </div>
                                            <div class="ev-list">
                                                <?php foreach ($list as $ev):
                                                    $prio = $priority_map[$ev['priority']] ?? $priority_map['normal'];
                                                    $stat = $status_map[$ev['status']]     ?? $status_map['scheduled'];
                                                ?>
                                                    <div class="ev-card prio-<?= htmlspecialchars($ev['priority']); ?>">
                                                        <div class="ev-time">
                                                            <div class="et-start"><?= $ev['start_time'] ? date('H:i', strtotime($ev['start_time'])) : '--:--'; ?></div>
                                                            <div class="et-end"><?= $ev['end_time'] ? 'to '.date('H:i', strtotime($ev['end_time'])) : ''; ?></div>
                                                        </div>
                                                        <div class="ev-info">
                                                            <div class="ev-title"><?= htmlspecialchars($ev['title']); ?></div>
                                                            <?php if (!empty($ev['description'])): ?>
                                                                <div class="ev-desc"><?= nl2br(htmlspecialchars(mb_strimwidth($ev['description'], 0, 220, '…'))); ?></div>
                                                            <?php endif; ?>
                                                            <div class="ev-meta">
                                                                <span class="pill <?= $prio['class']; ?>"><i class="fas fa-flag"></i> <?= $prio['label']; ?></span>
                                                                <span class="pill <?= $stat['class']; ?>"><i class="fas fa-circle"></i> <?= $stat['label']; ?></span>
                                                                <?php if (!empty($ev['location'])): ?>
                                                                    <span class="pill gray"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($ev['location']); ?></span>
                                                                <?php endif; ?>
                                                                <?php if ((int)$ev['as_announcement'] === 1): ?>
                                                                    <span class="pill violet"><i class="fas fa-bullhorn"></i> Announced</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Upcoming -->
                    <div class="panel">
                        <div class="panel-head"><h2><i class="fas fa-forward"></i> Upcoming Events</h2></div>
                        <div class="panel-body">
                            <?php if (empty($upcoming)): ?>
                                <div class="empty-state" style="padding:20px 10px;">
                                    <i class="fas fa-clock"></i>
                                    <p>No upcoming events.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($upcoming as $ev): $d = strtotime($ev['event_date']); ?>
                                    <div class="up-item">
                                        <div class="up-date">
                                            <div class="ud-d"><?= date('d', $d); ?></div>
                                            <div class="ud-m"><?= date('M', $d); ?></div>
                                        </div>
                                        <div class="up-info">
                                            <div class="up-title"><?= htmlspecialchars($ev['title']); ?></div>
                                            <div class="up-meta">
                                                <?= date('D', $d); ?>
                                                <?php if ($ev['start_time']): ?>
                                                    · <?= date('H:i', strtotime($ev['start_time'])); ?>
                                                <?php endif; ?>
                                                <?php if ($ev['location']): ?>
                                                    · <?= htmlspecialchars($ev['location']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: Compose -->
                <div class="panel">
                    <div class="panel-head"><h2><i class="fas fa-plus-circle"></i> Create Event</h2></div>
                    <div class="panel-body">
                        <form class="compose-form" method="POST" action="admin-events.php" id="eventForm">

                            <input type="hidden" name="action" value="create_event">

                            <div class="form-group">
                                <label for="ev_title">Title <span class="req">*</span></label>
                                <input type="text" id="ev_title" name="title" maxlength="200" required placeholder="e.g. Updating system">
                            </div>

                            <div class="form-group">
                                <label for="ev_desc">Description</label>
                                <textarea id="ev_desc" name="description" maxlength="2000" placeholder="Optional details about this event..."></textarea>
                            </div>

                            <div class="form-group">
                                <label for="ev_date">Date <span class="req">*</span></label>
                                <input type="date" id="ev_date" name="event_date" required value="<?= date('Y-m-d'); ?>">
                            </div>

                            <div class="row-2">
                                <div class="form-group">
                                    <label for="ev_start">Start Time</label>
                                    <input type="time" id="ev_start" name="start_time">
                                </div>
                                <div class="form-group">
                                    <label for="ev_end">End Time</label>
                                    <input type="time" id="ev_end" name="end_time">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="ev_loc">Location</label>
                                <input type="text" id="ev_loc" name="location" maxlength="200" placeholder="e.g. Main hall / Online / Server room">
                            </div>

                            <!-- Priority -->
                            <div class="form-group">
                                <label>Priority</label>
                                <div class="priority-grid">
                                    <label class="priority-pill low">
                                        <input type="radio" name="priority" value="low">
                                        <i class="fas fa-circle-chevron-down"></i> Low
                                    </label>
                                    <label class="priority-pill normal selected">
                                        <input type="radio" name="priority" value="normal" checked>
                                        <i class="fas fa-circle-info"></i> Normal
                                    </label>
                                    <label class="priority-pill high">
                                        <input type="radio" name="priority" value="high">
                                        <i class="fas fa-triangle-exclamation"></i> High
                                    </label>
                                    <label class="priority-pill urgent">
                                        <input type="radio" name="priority" value="urgent">
                                        <i class="fas fa-bell"></i> Urgent
                                    </label>
                                </div>
                            </div>

                            <!-- Status -->
                            <div class="form-group">
                                <label>Status</label>
                                <div class="status-grid">
                                    <label class="status-pill selected">
                                        <input type="radio" name="status" value="scheduled" checked>
                                        <i class="fas fa-clock"></i> Scheduled
                                    </label>
                                    <label class="status-pill">
                                        <input type="radio" name="status" value="in_progress">
                                        <i class="fas fa-spinner"></i> In&nbsp;Progress
                                    </label>
                                    <label class="status-pill">
                                        <input type="radio" name="status" value="done">
                                        <i class="fas fa-circle-check"></i> Done
                                    </label>
                                    <label class="status-pill">
                                        <input type="radio" name="status" value="cancelled">
                                        <i class="fas fa-circle-xmark"></i> Cancelled
                                    </label>
                                </div>
                            </div>

                            <!-- Announcement integration -->
                            <div class="ann-block">
                                <label class="ann-toggle">
                                    <input type="checkbox" name="as_announcement" id="asAnn" value="1">
                                    <span class="ann-switch"></span>
                                    <span class="ann-text">
                                        <span class="ann-title"><i class="fas fa-bullhorn"></i> Also send as announcement</span>
                                        <span class="ann-desc">
                                            Tick this box to also broadcast the event as a system announcement
                                            to schools and/or parents.
                                        </span>
                                    </span>
                                </label>

                                <div class="ann-extra" id="annExtra">
                                    <label style="margin-bottom:10px">Send to</label>
                                    <div class="audience-grid">
                                        <label class="audience-pill selected">
                                            <input type="radio" name="audience" value="all" checked>
                                            <i class="fas fa-globe"></i>
                                            Everyone
                                        </label>
                                        <label class="audience-pill">
                                            <input type="radio" name="audience" value="schools">
                                            <i class="fas fa-school"></i>
                                            Schools
                                        </label>
                                        <label class="audience-pill">
                                            <input type="radio" name="audience" value="parents">
                                            <i class="fas fa-user-friends"></i>
                                            Parents
                                        </label>
                                    </div>
                                    <div style="font-size:11.5px;color:var(--text-muted);margin-top:10px;line-height:1.55;">
                                        <i class="fas fa-circle-info" style="color:var(--primary);"></i>
                                        The event title, description, date, time and location will be combined
                                        into the announcement body automatically.
                                    </div>
                                </div>
                            </div>

                            <div style="margin-top:16px;">
                                <button type="submit" class="btn primary" id="saveBtn">
                                    <i class="fas fa-save"></i> Save Event
                                </button>
                            </div>

                        </form>
                    </div>
                </div>

            </div>

        </main>
    </div>
</div>

<script>
    /* THEME */
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
        t.addEventListener('click', () => apply(html.getAttribute('data-theme') === 'light' ? 'dark' : 'light'));
    })();

    /* SIDEBAR */
    (function () {
        const h = document.getElementById('hamburger');
        const s = document.getElementById('sidebar');
        if (h) h.addEventListener('click', () => s.classList.toggle('open'));
    })();

    /* Priority pills */
    document.querySelectorAll('.priority-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            document.querySelectorAll('.priority-pill').forEach(p => p.classList.remove('selected'));
            pill.classList.add('selected');
            const r = pill.querySelector('input');
            if (r) r.checked = true;
        });
    });

    /* Status pills */
    document.querySelectorAll('.status-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            document.querySelectorAll('.status-pill').forEach(p => p.classList.remove('selected'));
            pill.classList.add('selected');
            const r = pill.querySelector('input');
            if (r) r.checked = true;
        });
    });

    /* Audience pills */
    document.querySelectorAll('.audience-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            document.querySelectorAll('.audience-pill').forEach(p => p.classList.remove('selected'));
            pill.classList.add('selected');
            const r = pill.querySelector('input');
            if (r) r.checked = true;
        });
    });

    /* Announcement toggle → show audience */
    const asAnn = document.getElementById('asAnn');
    const annExtra = document.getElementById('annExtra');
    asAnn.addEventListener('change', function () {
        annExtra.classList.toggle('show', this.checked);
    });

    /* Prevent double-submit */
    document.getElementById('eventForm').addEventListener('submit', function () {
        const btn = document.getElementById('saveBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    });
</script>

</body>
</html>