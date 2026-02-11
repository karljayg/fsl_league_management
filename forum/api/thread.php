<?php
header('Content-Type: application/json; charset=utf-8');
require_once(dirname(__DIR__) . "/config.php");
require_once(dirname(__DIR__) . "/safe_html.php");
require_once(dirname(__DIR__) . "/embed_helper.php");
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

function row_to_post($row, $avatars, $avatars_by_author = []) {
    $sid = isset($row['site_user_id']) && $row['site_user_id'] !== '' && $row['site_user_id'] !== null ? (int) $row['site_user_id'] : null;
    $author = ensure_utf8($row['author'] ?? '');
    $p = [
        'id' => (int) $row['id'],
        'subject' => ensure_utf8($row['subject']),
        'author' => $author,
        'date' => $row['date']
    ];
    if (isset($row['hits'])) $p['hits'] = (int) $row['hits'];
    if (!empty($row['NT'])) $p['nt'] = true;
    if ($sid !== null) {
        $p['site_user_id'] = $sid;
        $avatar_url = null;
        if ($sid === 0) {
            if ($author !== '' && isset($avatars_by_author[$author])) {
                $avatar_url = $avatars_by_author[$author];
            }
        } elseif (!empty($avatars[$sid]['avatar_url'])) {
            $avatar_url = $avatars[$sid]['avatar_url'];
        }
        if ($avatar_url) $p['avatar_url'] = $avatar_url;
    }
    return $p;
}

function fetch_reply_tree($conn, $parent_id, $has_site_user_id, &$user_ids, &$author_by_id, &$avatars_by_author) {
    $r = $conn->prepare($has_site_user_id ? "SELECT id, subject, author, date, site_user_id, hits, NT FROM forumthreads WHERE parent = ? ORDER BY date ASC" : "SELECT id, subject, author, date, hits, NT FROM forumthreads WHERE parent = ? ORDER BY date ASC");
    if (!$r) {
        $r = $conn->prepare("SELECT id, subject, author, date, NT FROM forumthreads WHERE parent = ? ORDER BY date ASC");
    }
    $r->bind_param("i", $parent_id);
    $r->execute();
    $rres = $r->get_result();
    $list = [];
    while ($reply = $rres->fetch_assoc()) {
        if (!$has_site_user_id) $reply['site_user_id'] = null;
        if (!array_key_exists('hits', $reply)) $reply['hits'] = 0;
        $rsid = isset($reply['site_user_id']) && $reply['site_user_id'] !== '' && $reply['site_user_id'] !== null ? (int) $reply['site_user_id'] : null;
        if ($rsid === null && !empty(trim($reply['author'] ?? ''))) {
            $rlookup = forum_lookup_user_by_author($reply['author']);
            if ($rlookup !== null) {
                $rsid = $rlookup['id'];
                $reply['site_user_id'] = $rsid;
                if ($rsid === 0 && isset($rlookup['avatar_url'])) {
                    $avatars_by_author[trim($reply['author'])] = $rlookup['avatar_url'];
                }
            }
        } elseif ($rsid === 0 && !empty(trim($reply['author'] ?? ''))) {
            $rlookup = forum_lookup_user_by_author($reply['author']);
            if ($rlookup !== null && isset($rlookup['avatar_url'])) {
                $avatars_by_author[trim($reply['author'])] = $rlookup['avatar_url'];
            }
        }
        if ($rsid !== null) {
            $user_ids[] = $rsid;
            $author_by_id[$rsid] = isset($reply['author']) ? $reply['author'] : '';
        }
        $reply['replies'] = fetch_reply_tree($conn, (int) $reply['id'], $has_site_user_id, $user_ids, $author_by_id, $avatars_by_author);
        $list[] = $reply;
    }
    return $list;
}

function tree_to_output($tree, $avatars, $avatars_by_author) {
    $out = [];
    foreach ($tree as $reply) {
        $node = row_to_post($reply, $avatars, $avatars_by_author);
        $node['replies'] = tree_to_output($reply['replies'], $avatars, $avatars_by_author);
        $out[] = $node;
    }
    return $out;
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

$stmt = $conn->prepare("SELECT ft.id, ft.subject, ft.author, ft.date, ft.site_user_id, ft.hits, ft.parent, ft.NT, fb.body FROM forumthreads ft LEFT JOIN forumbodies fb ON ft.id = fb.id WHERE ft.id = ?");
if (!$stmt) {
    $stmt = $conn->prepare("SELECT ft.id, ft.subject, ft.author, ft.date, ft.hits, ft.parent, ft.NT, fb.body FROM forumthreads ft LEFT JOIN forumbodies fb ON ft.id = fb.id WHERE ft.id = ?");
    if (!$stmt) {
        $stmt = $conn->prepare("SELECT ft.id, ft.subject, ft.author, ft.date, ft.parent, ft.NT, fb.body FROM forumthreads ft LEFT JOIN forumbodies fb ON ft.id = fb.id WHERE ft.id = ?");
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
if (!array_key_exists('parent', $row)) $row['parent'] = null;
$parent_val = isset($row['parent']) ? $row['parent'] : null;
$is_topic = ($parent_val === null || $parent_val === -1 || $parent_val === '-1' || $parent_val === 0 || $parent_val === '0');

$user_ids = [];
$author_by_id = [];
$avatars_by_author = [];
$main_post_lookup = null;
$sid0 = isset($row['site_user_id']) && $row['site_user_id'] !== '' && $row['site_user_id'] !== null ? (int) $row['site_user_id'] : null;
if (!empty(trim($row['author'] ?? ''))) {
    $main_post_lookup = forum_lookup_user_by_author($row['author']);
    if ($main_post_lookup !== null) {
        if ($sid0 === null) {
            $sid0 = $main_post_lookup['id'];
            $row['site_user_id'] = $sid0;
        }
        if ($sid0 === 0 && !empty($main_post_lookup['avatar_url'])) {
            $avatars_by_author[trim($row['author'])] = $main_post_lookup['avatar_url'];
        }
    }
}
if ($sid0 !== null) {
    $user_ids[] = $sid0;
    $author_by_id[$sid0] = isset($row['author']) ? $row['author'] : '';
}

$replies = [];
$reply_lookups = [];
if ($is_topic) {
    $replies = fetch_reply_tree($conn, $id, $has_site_user_id, $user_ids, $author_by_id, $avatars_by_author);
} else {
    $r = $conn->prepare($has_site_user_id ? "SELECT id, subject, author, date, site_user_id, hits FROM forumthreads WHERE parent = ? ORDER BY date ASC" : "SELECT id, subject, author, date, hits FROM forumthreads WHERE parent = ? ORDER BY date ASC");
    if (!$r) {
        $r = $conn->prepare("SELECT id, subject, author, date FROM forumthreads WHERE parent = ? ORDER BY date ASC");
    }
    $r->bind_param("i", $id);
    $r->execute();
    $rres = $r->get_result();
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
                if ($rsid === 0 && isset($rlookup['avatar_url'])) {
                    $avatars_by_author[trim($reply['author'])] = $rlookup['avatar_url'];
                }
            }
        } elseif ($rsid === 0 && !empty(trim($reply['author'] ?? ''))) {
            $rlookup = forum_lookup_user_by_author($reply['author']);
            if ($rlookup !== null && isset($rlookup['avatar_url'])) {
                $avatars_by_author[trim($reply['author'])] = $rlookup['avatar_url'];
            }
        }
        if ($rsid !== null) {
            $user_ids[] = $rsid;
            $author_by_id[$rsid] = isset($reply['author']) ? $reply['author'] : '';
        }
        $replies[] = $reply;
    }
}

$conn->query("UPDATE forumthreads SET hits = hits + 1 WHERE id = " . (int) $id);

$avatars = forum_get_user_avatars(array_unique($user_ids), $author_by_id);
if ($main_post_lookup !== null && isset($main_post_lookup['avatar_url']) && $main_post_lookup['avatar_url'] !== null) {
    $main_author = trim($row['author'] ?? '');
    if ($main_author !== '') $avatars_by_author[$main_author] = $main_post_lookup['avatar_url'];
    if ($sid0 !== null) {
        if (!isset($avatars[$sid0])) $avatars[$sid0] = ['avatar_url' => null];
        $avatars[$sid0]['avatar_url'] = $main_post_lookup['avatar_url'];
    }
}
if (!$is_topic) {
    foreach ($reply_lookups as $idx => $rlookup) {
        $rid = isset($replies[$idx]['site_user_id']) ? (int) $replies[$idx]['site_user_id'] : null;
        if ($rid !== null && isset($rlookup['avatar_url']) && $rlookup['avatar_url'] !== null) {
            if (!isset($avatars[$rid])) $avatars[$rid] = ['avatar_url' => null];
            $avatars[$rid]['avatar_url'] = $rlookup['avatar_url'];
        }
    }
}

$out = row_to_post($row, $avatars, $avatars_by_author);
$out['body'] = '';
$out['replies'] = [];
$out['nt'] = !empty($row['NT']) || (trim($row['body'] ?? '') === '');
if (!empty($row['body'])) {
    $raw = ensure_utf8($row['body']);
    $out['body'] = $raw;
    $out['body_html'] = post_body_with_embeds($raw);
}
if ($is_topic && !empty($replies)) {
    $out['replies'] = tree_to_output($replies, $avatars, $avatars_by_author);
} else {
    foreach ($replies as $reply) {
        $out['replies'][] = row_to_post($reply, $avatars, $avatars_by_author);
    }
}

$conn->close();
echo json_encode($out);
