<?php
/*
|--------------------------------------------------------------------------
| fetch_replies.php
|--------------------------------------------------------------------------
| Fetches visitor replies from Gmail and saves them into message_replies.
|
| Trigger it manually:
|   http://localhost/school/fetch_replies.php
|
| Or automate via Windows Task Scheduler:
|   php.exe C:\xampp\htdocs\school\fetch_replies.php
*/

require_once 'db.php';

// Prevent accidental browser exposure in production — uncomment when on live server
// if (php_sapi_name() !== 'cli' && empty($_GET['manual'])) { exit('Forbidden'); }

$imap_host     = '{imap.gmail.com:993/imap/ssl}[Gmail]/All Mail';
$imap_user     = 'info.dngroup1@gmail.com';
$imap_password = 'rsxm dxju dnvp yleu';

$log = function ($msg) {
    $line = "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
    echo $line;
    file_put_contents(__DIR__ . '/fetch_replies.log', $line, FILE_APPEND);
};

$mailbox = @imap_open($imap_host, $imap_user, $imap_password);

if (!$mailbox) {
    $log("IMAP connection failed: " . imap_last_error());
    exit;
}

$log("Connected to Gmail.");

// Process both unseen AND seen-but-recent to catch missed replies
$unread = imap_search($mailbox, 'UNSEEN');

if ($unread === false || count($unread) === 0) {
    $log("No new emails.");
    imap_close($mailbox);
    exit;
}

$log("Found " . count($unread) . " new email(s).");

foreach ($unread as $uid) {

    $header = imap_headerinfo($mailbox, $uid);
    if (!$header) continue;

    $from_email = isset($header->from[0]->mailbox, $header->from[0]->host)
        ? $header->from[0]->mailbox . '@' . $header->from[0]->host
        : '';

    $subject = isset($header->subject) ? mb_decode_mimeheader($header->subject) : '';

    // Skip emails from our own account
    if (strtolower($from_email) === strtolower($imap_user)) {
        imap_setflag_full($mailbox, $uid, "\\Seen");
        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | EXTRACT BODY PROPERLY
    |--------------------------------------------------------------------------
    */

    $structure  = imap_fetchstructure($mailbox, $uid);
    $clean_body = '';

    if ($structure && isset($structure->parts)) {
        foreach ($structure->parts as $idx => $part) {
            if ($part->subtype === 'PLAIN') {
                $clean_body = imap_fetchbody($mailbox, $uid, $idx + 1);
                if (isset($part->encoding)) {
                    if ($part->encoding === 3) $clean_body = base64_decode($clean_body);
                    if ($part->encoding === 4) $clean_body = quoted_printable_decode($clean_body);
                }
                break;
            }
        }
        if ($clean_body === '') {
            foreach ($structure->parts as $idx => $part) {
                if ($part->subtype === 'HTML') {
                    $clean_body = imap_fetchbody($mailbox, $uid, $idx + 1);
                    if (isset($part->encoding)) {
                        if ($part->encoding === 3) $clean_body = base64_decode($clean_body);
                        if ($part->encoding === 4) $clean_body = quoted_printable_decode($clean_body);
                    }
                    $clean_body = strip_tags($clean_body);
                    break;
                }
            }
        }
    } else {
        $clean_body = imap_body($mailbox, $uid);
        if (isset($structure->encoding)) {
            if ($structure->encoding === 3) $clean_body = base64_decode($clean_body);
            if ($structure->encoding === 4) $clean_body = quoted_printable_decode($clean_body);
        }
    }

    $clean_body = mb_convert_encoding($clean_body, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');

    // Cut off the quoted original
    $clean_body = preg_split('/\n(On .+ wrote:|-----Original Message-----)/i', $clean_body)[0];
    $clean_body = trim($clean_body);

    if ($clean_body === '') {
        imap_setflag_full($mailbox, $uid, "\\Seen");
        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | FIND THE ORIGINAL MESSAGE — by email, then by subject
    |--------------------------------------------------------------------------
    */

    $orig_id = 0;

    $find = mysqli_prepare($conn, "
        SELECT id FROM messages
        WHERE email = ?
        ORDER BY created_at DESC LIMIT 1
    ");
    mysqli_stmt_bind_param($find, "s", $from_email);
    mysqli_stmt_execute($find);
    $res = mysqli_stmt_get_result($find);

    if (mysqli_num_rows($res) > 0) {
        $orig_id = (int) mysqli_fetch_assoc($res)['id'];
    }
    mysqli_stmt_close($find);

    // Fallback: match by cleaned subject
    if ($orig_id === 0 && $subject !== '') {
        $clean_subject = preg_replace('/^(Re|RE|re|Fwd|FWD):\s*/', '', $subject);

        $by_subject = mysqli_prepare($conn, "
            SELECT id FROM messages
            WHERE subject = ?
            ORDER BY created_at DESC LIMIT 1
        ");
        mysqli_stmt_bind_param($by_subject, "s", $clean_subject);
        mysqli_stmt_execute($by_subject);
        $sub_res = mysqli_stmt_get_result($by_subject);

        if (mysqli_num_rows($sub_res) > 0) {
            $orig_id = (int) mysqli_fetch_assoc($sub_res)['id'];
        }
        mysqli_stmt_close($by_subject);
    }

    if ($orig_id === 0) {
        $log("Skipped email from {$from_email} — no matching message found.");
        imap_setflag_full($mailbox, $uid, "\\Seen");
        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | SAVE THE REPLY
    |--------------------------------------------------------------------------
    */

    $insert = mysqli_prepare($conn, "
        INSERT INTO message_replies (message_id, sender, body, created_at)
        VALUES (?, 'visitor', ?, NOW())
    ");
    mysqli_stmt_bind_param($insert, "is", $orig_id, $clean_body);
    $ok = mysqli_stmt_execute($insert);
    mysqli_stmt_close($insert);

    if ($ok) {
        // Flag the main message as unread so the dashboard shows the new activity
        $update = mysqli_prepare($conn, "UPDATE messages SET status = 'unread' WHERE id = ?");
        mysqli_stmt_bind_param($update, "i", $orig_id);
        mysqli_stmt_execute($update);
        mysqli_stmt_close($update);

        $log("Saved reply from {$from_email} to message #{$orig_id}");
    } else {
        $log("Failed to save reply from {$from_email}");
    }

    // Only mark as read AFTER successful processing
    imap_setflag_full($mailbox, $uid, "\\Seen");
}

imap_close($mailbox);
$log("Done.");