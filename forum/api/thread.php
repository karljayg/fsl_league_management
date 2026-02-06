<?php
header('Content-Type: application/json; charset=utf-8');
require_once(dirname(__DIR__) . "/config.php");
require_once(dirname(__DIR__) . "/safe_html.php");
$root = dirname(dirname(__DIR__));
if (!isset($GLOBALS['db'])) {
    $mainDbFile = $root . '/includes/db.php';
    if (file_exists($mainDbFile)) {
        require_once $mainDbFile;
        if (isset($db) && $db instanceof PDO) {
            $GLOBALS['db'] = $db;
        }
    }
    if (!isset($GLOBALS['db']) && file_exists($root . '/config.php')) {
        $config = [];
        require $root . '/config.php';
        if (!empty($config['db_host']) && !empty($config['db_name'])) {
            try {
                $GLOBALS['db'] = new PDO(
                    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
                    $config['db_user'],
                    $config['db_pass']
                );
                $GLOBALS['db']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (Exception $e) { /* ignore */ }
        }
    }
}
require_once(dirname(__DIR__) . "/forum_user_lookup.php");

function ensure_utf8($str) {
    if ($str === '' || $str === null) return '';
    if (!mb_check_encoding($str, 'UTF-8')) {
        $str = mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
    }
    if (!mb_check_encoding($str, 'UTF-8')) {
        $str = mb_convert_encoding($str, 'UTF-8', 'UTF-8');
    }
    return $str;
}

function row_to_post($row, $avatars) {
    $sid = isset($row['site_user_id']) && $row['site_user_id'] !== '' && $row['site_user_id'] !== null ? (int) $row['site_user_id'] : null;
    $p = [
        'id' => (int) $row['id'],
        'subject' => ensure_utf8($row['subject']),
        'author' => ensure_utf8($row['author']),
        'date' => $row['date']
    ];
    if (isset($row['hits'])) $p['hits'] = (int) $row['hits'];
    if ($sid !== null) {
        $p['site_user_id'] = $sid;
        if (!empty($avatars[$sid]['avatar_url'])) $p['avatar_url'] = $avatars[$sid]['avatar_url'];
    }
    return $p;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    echo json_encode(['error' => 'Invalid id']);
    exit;
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    echo json_encode(['error' => 'Connection failed']);
    exit;
}

$stmt = $conn->prepare("SELECT id, subject, author, date, site_user_id, hits FROM forumthreads WHERE id = ?");
if (!$stmt) {
    $stmt = $conn->prepare("SELECT id, subject, author, date, hits FROM forumthreads WHERE id = ?");
    if (!$stmt) {
        $stmt = $conn->prepare("SELECT id, subject, author, date FROM forumthreads WHERE id = ?");
        $has_site_user_id = false;
    } else {
        $has_site_user_id = false;
    }
} else {
    $has_site_user_id = true;
}
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo json_encode(['error' => 'Not found']);
    $conn->close();
    exit;
}

$row = $result->fetch_assoc();
if (!$has_site_user_id) $row['site_user_id'] = null;
if (!array_key_exists('hits', $row)) $row['hits'] = 0;

$user_ids = [];
$author_by_id = [];
$main_post_lookup = null;
$sid0 = isset($row['site_user_id']) && $row['site_user_id'] !== '' && $row['site_user_id'] !== null ? (int) $row['site_user_id'] : null;
if ($sid0 === null && !empty(trim($row['author'] ?? ''))) {
    $main_post_lookup = forum_lookup_user_by_author($row['author']);
    if ($main_post_lookup !== null) {
        $sid0 = $main_post_lookup['id'];
        $row['site_user_id'] = $sid0;
    }
}
if ($sid0 !== null) {
    $user_ids[] = $sid0;
    $author_by_id[$sid0] = isset($row['author']) ? $row['author'] : '';
}

$b = $conn->prepare("SELECT body FROM forumbodies WHERE id = ?");
$b->bind_param("i", $id);
$b->execute();
$br = $b->get_result();

$r = $conn->prepare($has_site_user_id ? "SELECT id, subject, author, date, site_user_id, hits FROM forumthreads WHERE parent = ? ORDER BY date ASC" : "SELECT id, subject, author, date, hits FROM forumthreads WHERE parent = ? ORDER BY date ASC");
if (!$r) {
    $r = $conn->prepare("SELECT id, subject, author, date FROM forumthreads WHERE parent = ? ORDER BY date ASC");
}
$r->bind_param("i", $id);
$r->execute();
$rres = $r->get_result();
$replies = [];
$reply_lookups = []; // reply index => ['id' => x, 'avatar_url' => y] from author lookup
while ($reply = $rres->fetch_assoc()) {
    if (!$has_site_user_id) $reply['site_user_id'] = null;
    if (!array_key_exists('hits', $reply)) $reply['hits'] = 0;
    $rsid = isset($reply['site_user_id']) && $reply['site_user_id'] !== '' && $reply['site_user_id'] !== null ? (int) $reply['site_user_id'] : null;
    if ($rsid === null && !empty(trim($reply['author'] ?? ''))) {
        $rlookup = forum_lookup_user_by_author($reply['author']);
        if ($rlookup !== null) {
            $rsid = $rlookup['id'];
            $reply['site_user_id'] = $rsid;
            $reply_lookups[count($replies)] = $rlookup;
        }
    }
    if ($rsid !== null) {
        $user_ids[] = $rsid;
        $author_by_id[$rsid] = isset($reply['author']) ? $reply['author'] : '';
    }
    $replies[] = $reply;
}

$conn->query("UPDATE forumthreads SET hits = hits + 1 WHERE id = " . (int) $id);

$avatars = forum_get_user_avatars(array_unique($user_ids), $author_by_id);
if ($main_post_lookup !== null && isset($main_post_lookup['avatar_url']) && $main_post_lookup['avatar_url'] !== null && $sid0 !== null) {
    if (!isset($avatars[$sid0])) $avatars[$sid0] = ['avatar_url' => null];
    $avatars[$sid0]['avatar_url'] = $main_post_lookup['avatar_url'];
}
foreach ($reply_lookups as $idx => $rlookup) {
    $rid = isset($replies[$idx]['site_user_id']) ? (int) $replies[$idx]['site_user_id'] : null;
    if ($rid !== null && isset($rlookup['avatar_url']) && $rlookup['avatar_url'] !== null) {
        if (!isset($avatars[$rid])) $avatars[$rid] = ['avatar_url' => null];
        $avatars[$rid]['avatar_url'] = $rlookup['avatar_url'];
    }
}

$out = row_to_post($row, $avatars);
$out['body'] = '';
$out['replies'] = [];
if ($br->num_rows > 0) {
    $raw = ensure_utf8($br->fetch_assoc()['body']);
    $out['body'] = $raw;
    $out['body_html'] = safe_post_html($raw);
}
foreach ($replies as $reply) {
    $out['replies'][] = row_to_post($reply, $avatars);
}

$conn->close();
echo json_encode($out);
