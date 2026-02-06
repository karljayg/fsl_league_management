<?php
header('Content-Type: application/json; charset=utf-8');
require_once(dirname(__DIR__) . "/config.php");

$q = isset($_GET['q']) ? trim($_GET['q']) : (isset($_GET['searchtext']) ? trim($_GET['searchtext']) : '');
$search_body = isset($_GET['body']) && $_GET['body'] === '1';
$forum_filter = isset($_GET['forum']) ? $_GET['forum'] : 'all';
$forum_filter = ($forum_filter === 'all' || $forum_filter === '') ? null : max(1, (int) $forum_filter);
$author = isset($_GET['author']) ? trim($_GET['author']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

$has_criteria = ($q !== '' || $author !== '' || $date_from !== '' || $date_to !== '');
if (!$has_criteria) {
    echo json_encode(['threads' => [], 'error' => 'Enter at least one: search text, author, or date range']);
    exit;
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    echo json_encode(['threads' => [], 'error' => 'Connection failed']);
    exit;
}
$conn->set_charset('utf8mb4');

$like = ($q !== '') ? ('%' . $conn->real_escape_string($q) . '%') : null;
$author_like = ($author !== '') ? ('%' . $conn->real_escape_string($author) . '%') : null;
$thread_ids = [];
$match_post_id = [];

$sql = "SELECT id, subject, author, date, forum FROM forumthreads WHERE parent = -1";
$params = [];
$types = '';
if ($q !== '') {
    $sql .= " AND subject LIKE ?";
    $params[] = $like;
    $types .= 's';
}
if ($author !== '') {
    $sql .= " AND author LIKE ?";
    $params[] = $author_like;
    $types .= 's';
}
if ($date_from !== '') {
    $sql .= " AND date >= ?";
    $params[] = $date_from;
    $types .= 's';
}
if ($date_to !== '') {
    $sql .= " AND DATE(date) <= ?";
    $params[] = $date_to;
    $types .= 's';
}
if ($forum_filter !== null) {
    $sql .= " AND forum = ?";
    $params[] = $forum_filter;
    $types .= 'i';
}
$sql .= " ORDER BY date DESC LIMIT 100";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $thread_ids[$row['id']] = $row;
    $match_post_id[$row['id']] = (int) $row['id'];
}

if ($q !== '') {
    $sql_any = "SELECT id, parent, mainthread, forum FROM forumthreads WHERE subject LIKE ?";
    $params_any = [$like];
    $types_any = 's';
    if ($author !== '') {
        $sql_any .= " AND author LIKE ?";
        $params_any[] = $author_like;
        $types_any .= 's';
    }
    if ($date_from !== '') {
        $sql_any .= " AND date >= ?";
        $params_any[] = $date_from;
        $types_any .= 's';
    }
    if ($date_to !== '') {
        $sql_any .= " AND DATE(date) <= ?";
        $params_any[] = $date_to;
        $types_any .= 's';
    }
    if ($forum_filter !== null) {
        $sql_any .= " AND forum = ?";
        $params_any[] = $forum_filter;
        $types_any .= 'i';
    }
    $sql_any .= " LIMIT 200";
    $stmt_any = $conn->prepare($sql_any);
    $stmt_any->bind_param($types_any, ...$params_any);
    $stmt_any->execute();
    $res_any = $stmt_any->get_result();
    while ($row = $res_any->fetch_assoc()) {
        $mid = (int) ($row['parent'] == -1 ? $row['id'] : ($row['mainthread'] ?: $row['id']));
        if ($mid === 0) $mid = (int) $row['id'];
        $match_post_id[$mid] = (int) $row['id'];
        if (!isset($thread_ids[$mid])) {
            $trow = $conn->query("SELECT id, subject, author, date, forum FROM forumthreads WHERE id = " . (int)$mid)->fetch_assoc();
            if ($trow) $thread_ids[$mid] = $trow;
        }
    }
}

if ($search_body && $q !== '') {
    $sql_body = "SELECT fb.id FROM forumbodies fb INNER JOIN forumthreads ft ON ft.id = fb.id WHERE SUBSTRING(fb.body, 1, 3000) LIKE ?";
    $body_params = [$like];
    $body_types = 's';
    if ($author !== '' && $author_like !== null) {
        $sql_body .= " AND ft.author LIKE ?";
        $body_params[] = $author_like;
        $body_types .= 's';
    }
    if ($date_from !== '') {
        $sql_body .= " AND ft.date >= ?";
        $body_params[] = $date_from;
        $body_types .= 's';
    }
    if ($date_to !== '') {
        $sql_body .= " AND DATE(ft.date) <= ?";
        $body_params[] = $date_to;
        $body_types .= 's';
    }
    if ($forum_filter !== null) {
        $sql_body .= " AND ft.forum = ?";
        $body_params[] = $forum_filter;
        $body_types .= 'i';
    }
    $sql_body .= " LIMIT 150";
    $stmt2 = $conn->prepare($sql_body);
    $stmt2->bind_param($body_types, ...$body_params);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    $post_ids = [];
    while ($r = $res2->fetch_assoc()) {
        $post_ids[] = (int) $r['id'];
    }
    if (!empty($post_ids)) {
        $ids_list = implode(',', array_map('intval', $post_ids));
        $res3 = $conn->query("SELECT id, COALESCE(NULLIF(mainthread, 0), id) AS mid FROM forumthreads WHERE id IN ($ids_list)");
        while ($r = $res3->fetch_assoc()) {
            $pid = (int) $r['id'];
            $mid = (int) $r['mid'];
            $match_post_id[$mid] = $pid;
            if (!isset($thread_ids[$mid])) {
                $row = $conn->query("SELECT id, subject, author, date, forum FROM forumthreads WHERE id = $mid")->fetch_assoc();
                if ($row) {
                    $thread_ids[$mid] = $row;
                }
            }
        }
    }
}

$threads = array_values($thread_ids);
usort($threads, function ($a, $b) {
    return strcmp($b['date'], $a['date']);
});
$threads = array_slice($threads, 0, 100);

$forum_map = [];
if ($forum_filter === null) {
    $r = $conn->query("SELECT id, title FROM forums");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $forum_map[(int) $row['id']] = $row['title'];
        }
    }
}

$limit = 30;
foreach ($threads as &$t) {
    $tid = (int) $t['id'];
    $tforum = isset($t['forum']) ? (int) $t['forum'] : 1;
    if ($tforum < 1) $tforum = 1;
    $stmt_p = $conn->prepare("SELECT COUNT(*) AS c FROM forumthreads WHERE parent = -1 AND forum = ? AND date >= (SELECT date FROM forumthreads WHERE id = ?)");
    $stmt_p->bind_param("ii", $tforum, $tid);
    $stmt_p->execute();
    $r = $stmt_p->get_result()->fetch_assoc();
    $t['page'] = max(1, (int) ceil(($r['c'] ?? 1) / $limit));
    $t['forum'] = $tforum;
    $t['postId'] = isset($match_post_id[$tid]) ? $match_post_id[$tid] : $tid;
    if ($forum_filter === null && !empty($forum_map[$tforum])) {
        $t['forum_title'] = $forum_map[$tforum];
    }
}
unset($t);

$conn->close();
echo json_encode(['threads' => $threads]);
