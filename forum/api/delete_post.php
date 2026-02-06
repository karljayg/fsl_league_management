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

$stmt = $conn->prepare("DELETE FROM forumbodies WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt = $conn->prepare("DELETE FROM forumthreads WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$conn->close();

echo json_encode(['success' => true]);
