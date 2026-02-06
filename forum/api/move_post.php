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
$target_parent = isset($_POST['target_parent']) ? (int) $_POST['target_parent'] : null;

if ($id <= 0 || $target_parent === null) {
    echo json_encode(['error' => 'Invalid id or target_parent']);
    exit;
}

if ($target_parent === $id) {
    echo json_encode(['error' => 'Cannot make a post a reply to itself']);
    exit;
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    echo json_encode(['error' => 'Connection failed']);
    exit;
}

$stmt = $conn->prepare("SELECT id FROM forumthreads WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    echo json_encode(['error' => 'Post not found']);
    $conn->close();
    exit;
}

if ($target_parent === -1) {
    $stmt = $conn->prepare("UPDATE forumthreads SET parent = -1, mainthread = ? WHERE id = ?");
    $stmt->bind_param("ii", $id, $id);
    $stmt->execute();
    $stmt = $conn->prepare("UPDATE forumbodies SET parent = -1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
} else {
    $stmt = $conn->prepare("SELECT mainthread FROM forumthreads WHERE id = ?");
    $stmt->bind_param("i", $target_parent);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        echo json_encode(['error' => 'Target post not found']);
        $conn->close();
        exit;
    }
    $row = $res->fetch_assoc();
    $mainthread = (int) ($row['mainthread'] ?: $target_parent);
    if ($mainthread === 0) {
        $mainthread = $target_parent;
    }
    $stmt = $conn->prepare("UPDATE forumthreads SET parent = ?, mainthread = ? WHERE id = ?");
    $stmt->bind_param("iii", $target_parent, $mainthread, $id);
    $stmt->execute();
    $stmt = $conn->prepare("UPDATE forumbodies SET parent = ? WHERE id = ?");
    $stmt->bind_param("ii", $target_parent, $id);
    $stmt->execute();
}

$conn->close();
echo json_encode(['success' => true]);
