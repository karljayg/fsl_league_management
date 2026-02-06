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
require_once(dirname(__DIR__) . "/safe_html.php");

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$subject = isset($_POST['subject']) ? trim($_POST['subject']) : null;
$body = isset($_POST['body']) ? $_POST['body'] : null;

if ($id <= 0) {
    echo json_encode(['error' => 'Invalid id']);
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

if ($subject !== null) {
    $subject = substr($subject, 0, 50);
    $stmt = $conn->prepare("UPDATE forumthreads SET subject = ? WHERE id = ?");
    $stmt->bind_param("si", $subject, $id);
    $stmt->execute();
}

if ($body !== null) {
    $stmt = $conn->prepare("INSERT INTO forumbodies (id, body, parent) SELECT id, ?, parent FROM forumthreads WHERE id = ? ON DUPLICATE KEY UPDATE body = VALUES(body)");
    $stmt->bind_param("si", $body, $id);
    $stmt->execute();
}

$conn->close();
$out = ['success' => true];
if ($body !== null) $out['body_html'] = safe_post_html($body);
echo json_encode($out);
