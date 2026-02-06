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
$target_forum_id = isset($_POST['target_forum_id']) ? (int) $_POST['target_forum_id'] : 0;

if ($id <= 0 || $target_forum_id < 1) {
    echo json_encode(['error' => 'Invalid post id or target forum']);
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

$res = $conn->query("SELECT id FROM forums WHERE id = " . (int) $target_forum_id);
if (!$res || $res->num_rows === 0) {
    echo json_encode(['error' => 'Target forum not found']);
    $conn->close();
    exit;
}

// Collect this post and all reply descendants (recursive)
$ids = [$id];
$changed = true;
while ($changed) {
    $changed = false;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT id FROM forumthreads WHERE parent IN ($placeholders)");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $cid = (int) $row['id'];
        if (!in_array($cid, $ids, true)) {
            $ids[] = $cid;
            $changed = true;
        }
    }
}

$id_list = implode(',', array_map('intval', $ids));
$conn->query("UPDATE forumthreads SET forum = " . (int) $target_forum_id . " WHERE id IN ($id_list)");

$conn->close();
echo json_encode(['success' => true, 'moved_count' => count($ids)]);
