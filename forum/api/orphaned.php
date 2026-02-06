<?php
header('Content-Type: application/json; charset=utf-8');
require_once(dirname(__DIR__) . "/forum_auth.php");
if (!forum_has_chat_admin()) {
    echo json_encode(['posts' => [], 'error' => 'Chat admin permission required']);
    exit;
}
require_once(dirname(__DIR__) . "/config.php");

function ensure_utf8($str) {
    if ($str === '' || $str === null) return '';
    if (!mb_check_encoding($str, 'UTF-8')) {
        $str = mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
    }
    return $str;
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    echo json_encode(['posts' => [], 'error' => 'Connection failed']);
    exit;
}

$sql = "SELECT ft.id, ft.subject, ft.author, ft.date, ft.parent
  FROM forumthreads ft
  LEFT JOIN forumthreads p ON p.id = ft.parent
  WHERE ft.parent != -1 AND p.id IS NULL
  ORDER BY ft.date DESC";
$res = $conn->query($sql);
$posts = [];
while ($row = $res->fetch_assoc()) {
    $posts[] = [
        'id' => (int) $row['id'],
        'subject' => ensure_utf8($row['subject']),
        'author' => ensure_utf8($row['author']),
        'date' => $row['date'],
        'parent' => (int) $row['parent']
    ];
}
$conn->close();
echo json_encode(['posts' => $posts]);
