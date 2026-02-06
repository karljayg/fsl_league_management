<?php
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}
require_once(dirname(__DIR__) . "/forum_auth.php");
if (!forum_has_chat_admin()) {
    echo json_encode(['error' => 'Chat admin permission required']);
    exit;
}
require_once(dirname(__DIR__) . "/config.php");

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) {
    echo json_encode(['error' => 'Invalid id']);
    exit;
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    echo json_encode(['error' => 'Connection failed']);
    exit;
}

$stmt = $conn->prepare("SELECT id, body, parent FROM forumbodies WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) {
    echo json_encode(['error' => 'Body not found']);
    $conn->close();
    exit;
}

$stmt = $conn->prepare("SELECT id FROM forumthreads WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    echo json_encode(['error' => 'Post already exists']);
    $conn->close();
    exit;
}

$parent = (int) $row['parent'];
$mainthread = $id;
$subject = '(recovered #' . $id . ')';
$now = date('Y-m-d H:i:s');
$author = 'unknown';
$host = '';
$forum = 1;
$nt = 0;
$hits = 0;
$registered = 0;

if ($parent !== -1 && $parent !== 0) {
    $stmt = $conn->prepare("SELECT mainthread FROM forumthreads WHERE id = ?");
    $stmt->bind_param("i", $parent);
    $stmt->execute();
    $pres = $stmt->get_result();
    if ($pres->num_rows > 0) {
        $mainthread = (int) ($pres->fetch_assoc()['mainthread'] ?: $parent);
    } else {
        $parent = -1;
    }
} else {
    $parent = -1;
}

$stmt = $conn->prepare("INSERT INTO forumthreads (id, date, mainthread, parent, author, subject, host, last, forum, NT, hits, registered) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("isiiissssiii", $id, $now, $mainthread, $parent, $author, $subject, $host, $now, $forum, $nt, $hits, $registered);
if (!$stmt->execute()) {
    echo json_encode(['error' => 'Insert failed']);
    $conn->close();
    exit;
}

$conn->close();
echo json_encode(['success' => true]);
