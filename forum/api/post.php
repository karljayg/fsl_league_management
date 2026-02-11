<?php
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once(dirname(__DIR__) . "/config.php");

$parent_id = isset($_POST['parent_id']) ? (int) $_POST['parent_id'] : 0;
$forum_id = isset($_POST['forum_id']) ? (int) $_POST['forum_id'] : 1;
if ($forum_id < 1) $forum_id = 1;
$author = isset($_POST['author']) ? trim($_POST['author']) : '';
$body = isset($_POST['body']) ? trim($_POST['body']) : '';
$subject = isset($_POST['subject']) ? trim($_POST['subject']) : '';

$site_user_id = null;
if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
    $site_user_id = (int) $_SESSION['user_id'];
    $author = trim($_SESSION['username']);
}
if ($author === '') {
    echo json_encode(['error' => 'Missing author']);
    exit;
}
$is_new_topic = ($parent_id === 0);
if ($is_new_topic && $subject === '') {
    echo json_encode(['error' => 'Missing subject for new topic']);
    exit;
}

$author = substr($author, 0, 50);
$subject = substr($subject, 0, 50);
$ip = '';
if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
} elseif (!empty($_SERVER['REMOTE_ADDR'])) {
    $ip = $_SERVER['REMOTE_ADDR'];
}
$ip = substr($ip, 0, 50);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    echo json_encode(['error' => 'Connection failed']);
    exit;
}

$now = date('Y-m-d H:i:s');
$registered = $site_user_id ? 1 : 0;

if ($is_new_topic) {
    $mainthread = 0;
    $parent_val = -1;
    $stmt = $conn->prepare("INSERT INTO forumthreads (date, mainthread, parent, author, subject, host, last, forum, NT, hits, registered, site_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?)");
    $stmt->bind_param("siissssiii", $now, $mainthread, $parent_val, $author, $subject, $ip, $now, $forum_id, $registered, $site_user_id);
    if (!$stmt->execute()) {
        echo json_encode(['error' => 'Insert failed']);
        $conn->close();
        exit;
    }
    $new_id = (int) $conn->insert_id;
    $conn->query("UPDATE forumthreads SET mainthread = " . $new_id . " WHERE id = " . $new_id);
    $body_parent = -1;
    $stmt2 = $conn->prepare("INSERT INTO forumbodies (id, body, parent) VALUES (?, ?, ?)");
    $stmt2->bind_param("isi", $new_id, $body, $body_parent);
} else {
    $stmt = $conn->prepare("SELECT mainthread, subject, forum FROM forumthreads WHERE id = ?");
    $stmt->bind_param("i", $parent_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        echo json_encode(['error' => 'Parent post not found']);
        $conn->close();
        exit;
    }
    $parent = $res->fetch_assoc();
    $mainthread = (int) ($parent['mainthread'] ?: $parent_id);
    $forum_id = (int) ($parent['forum'] ?: 1);
    if ($forum_id < 1) $forum_id = 1;
    if ($subject === '') {
        echo json_encode(['error' => 'Subject is required']);
        $conn->close();
        exit;
    }
    $stmt = $conn->prepare("INSERT INTO forumthreads (date, mainthread, parent, author, subject, host, last, forum, NT, hits, registered, site_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?)");
    $stmt->bind_param("siissssiii", $now, $mainthread, $parent_id, $author, $subject, $ip, $now, $forum_id, $registered, $site_user_id);
    if (!$stmt->execute()) {
        echo json_encode(['error' => 'Insert failed']);
        $conn->close();
        exit;
    }
    $new_id = (int) $conn->insert_id;
    $stmt2 = $conn->prepare("INSERT INTO forumbodies (id, body, parent) VALUES (?, ?, ?)");
    $stmt2->bind_param("isi", $new_id, $body, $parent_id);
}

if (!$stmt2->execute()) {
    $conn->query("DELETE FROM forumthreads WHERE id = " . $new_id);
    echo json_encode(['error' => 'Insert body failed']);
    $conn->close();
    exit;
}

$avatar_url = null;
if ($site_user_id) {
    $dbFile = dirname(dirname(__DIR__)) . '/includes/db.php';
    if (file_exists($dbFile)) {
        try {
            require_once $dbFile;
            if (isset($db) && $db instanceof PDO) {
                $st = $db->prepare("SELECT avatar_url FROM users WHERE id = ?");
                $st->execute([$site_user_id]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if ($r && !empty($r['avatar_url'])) $avatar_url = $r['avatar_url'];
            }
        } catch (Exception $e) { /* ignore */ }
    }
}

$conn->close();

$cookie_name = 'forum_author';
$cookie_value = $author;
setcookie($cookie_name, $cookie_value, time() + (365 * 24 * 3600), '/', '', false, false);

$out = ['success' => true, 'id' => $new_id, 'subject' => $subject, 'author' => $author, 'date' => $now];
if ($site_user_id) {
    $out['site_user_id'] = $site_user_id;
    if ($avatar_url) $out['avatar_url'] = $avatar_url;
}
echo json_encode($out);
