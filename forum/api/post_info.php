<?php
header('Content-Type: application/json; charset=utf-8');
require_once(dirname(__DIR__) . "/config.php");

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

$stmt = $conn->prepare("SELECT id, parent, mainthread, date FROM forumthreads WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    echo json_encode(['error' => 'Not found']);
    $conn->close();
    exit;
}

$row = $res->fetch_assoc();
$thread_id = (int) ($row['mainthread'] ?: $row['id']);
$path = [];
$current = (int) $row['id'];
while ($current > 0) {
    $path[] = $current;
    $r = $conn->query("SELECT parent FROM forumthreads WHERE id = $current")->fetch_assoc();
    if (!$r || (int) $r['parent'] === -1) break;
    $current = (int) $r['parent'];
}
$path = array_reverse($path);

$topic_stmt = $conn->prepare("SELECT date, forum FROM forumthreads WHERE id = ?");
$topic_stmt->bind_param("i", $thread_id);
$topic_stmt->execute();
$topic_row = $topic_stmt->get_result()->fetch_assoc();
$topic_stmt->close();
$thread_date = $topic_row['date'] ?? null;
$forum = isset($topic_row['forum']) ? (int) $topic_row['forum'] : 1;
if ($forum < 1) $forum = 1;

$rank = 1;
if ($thread_date) {
    $rank_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM forumthreads WHERE parent = -1 AND forum = ? AND date >= ?");
    $rank_stmt->bind_param("is", $forum, $thread_date);
    $rank_stmt->execute();
    $rank = (int) $rank_stmt->get_result()->fetch_assoc()['c'];
    $rank_stmt->close();
}
$limit = 30;
$page = (int) ceil($rank / $limit);

$conn->close();
echo json_encode(['threadId' => $thread_id, 'page' => $page, 'path' => $path, 'forum' => $forum]);
