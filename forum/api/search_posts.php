<?php
header('Content-Type: application/json; charset=utf-8');
require_once(dirname(__DIR__) . "/config.php");

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$search_body = isset($_GET['body']) && $_GET['body'] === '1';

if ($q === '') {
    echo json_encode(['posts' => [], 'error' => 'Empty query']);
    exit;
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    echo json_encode(['posts' => [], 'error' => 'Connection failed']);
    exit;
}

function ensure_utf8($str) {
    if ($str === '' || $str === null) return '';
    if (!mb_check_encoding($str, 'UTF-8')) {
        $str = mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
    }
    return $str;
}

$like = '%' . $conn->real_escape_string($q) . '%';
$posts_by_id = [];

$sql = "SELECT ft.id, ft.subject, ft.author, ft.date, ft.parent,
  COALESCE(NULLIF(ft.mainthread, 0), ft.id) AS thread_id,
  tp.subject AS thread_subject
  FROM forumthreads ft
  LEFT JOIN forumthreads tp ON tp.id = COALESCE(NULLIF(ft.mainthread, 0), ft.id)
  WHERE ft.subject LIKE ?
  ORDER BY ft.date DESC LIMIT 80";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $like);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $posts_by_id[(int)$row['id']] = [
        'id' => (int) $row['id'],
        'subject' => ensure_utf8($row['subject']),
        'author' => ensure_utf8($row['author']),
        'date' => $row['date'],
        'thread_id' => (int) $row['thread_id'],
        'thread_subject' => ensure_utf8($row['thread_subject'] ?: $row['subject'])
    ];
}

if ($search_body) {
    $sql_body = "SELECT id FROM forumbodies WHERE SUBSTRING(body, 1, 3000) LIKE ? LIMIT 100";
    $stmt2 = $conn->prepare($sql_body);
    $stmt2->bind_param("s", $like);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    $body_ids = [];
    while ($r = $res2->fetch_assoc()) {
        $body_ids[] = (int) $r['id'];
    }
    if (!empty($body_ids)) {
        $ids_list = implode(',', array_map('intval', $body_ids));
        $sql_posts = "SELECT ft.id, ft.subject, ft.author, ft.date,
          COALESCE(NULLIF(ft.mainthread, 0), ft.id) AS thread_id,
          tp.subject AS thread_subject
          FROM forumthreads ft
          LEFT JOIN forumthreads tp ON tp.id = COALESCE(NULLIF(ft.mainthread, 0), ft.id)
          WHERE ft.id IN ($ids_list)";
        $res3 = $conn->query($sql_posts);
        while ($row = $res3->fetch_assoc()) {
            $pid = (int) $row['id'];
            if (!isset($posts_by_id[$pid])) {
                $posts_by_id[$pid] = [
                    'id' => $pid,
                    'subject' => ensure_utf8($row['subject']),
                    'author' => ensure_utf8($row['author']),
                    'date' => $row['date'],
                    'thread_id' => (int) $row['thread_id'],
                    'thread_subject' => ensure_utf8($row['thread_subject'] ?: $row['subject'])
                ];
            }
        }
    }
}

$posts = array_values($posts_by_id);
usort($posts, function ($a, $b) {
    return strcmp($b['date'], $a['date']);
});
$posts = array_slice($posts, 0, 80);

$conn->close();
echo json_encode(['posts' => $posts]);
