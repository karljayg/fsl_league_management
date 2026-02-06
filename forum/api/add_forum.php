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

$title = isset($_POST['title']) ? trim($_POST['title']) : '';
$title = substr($title, 0, 100);
if ($title === '') {
    echo json_encode(['error' => 'Title required']);
    exit;
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    echo json_encode(['error' => 'Connection failed']);
    exit;
}

$stmt = $conn->prepare("INSERT INTO forums (title) VALUES (?)");
$stmt->bind_param("s", $title);
if (!$stmt->execute()) {
    echo json_encode(['error' => 'Insert failed']);
    $conn->close();
    exit;
}
$id = (int) $conn->insert_id;
$conn->close();
echo json_encode(['success' => true, 'id' => $id, 'title' => $title]);
