<?php
header('Content-Type: application/json; charset=utf-8');
require_once(dirname(__DIR__) . "/forum_auth.php");
if (!forum_has_chat_admin()) {
    echo json_encode(['bodies' => [], 'error' => 'Chat admin permission required']);
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
    echo json_encode(['bodies' => [], 'error' => 'Connection failed']);
    exit;
}

$sql = "SELECT fb.id, fb.body, fb.parent
  FROM forumbodies fb
  LEFT JOIN forumthreads ft ON ft.id = fb.id
  WHERE ft.id IS NULL
  ORDER BY fb.id ASC";
$res = $conn->query($sql);
$bodies = [];
while ($row = $res->fetch_assoc()) {
    $body = ensure_utf8($row['body']);
    $bodies[] = [
        'id' => (int) $row['id'],
        'body_preview' => mb_substr($body, 0, 120) . (mb_strlen($body) > 120 ? '…' : ''),
        'parent' => (int) $row['parent']
    ];
}
$conn->close();
echo json_encode(['bodies' => $bodies]);
