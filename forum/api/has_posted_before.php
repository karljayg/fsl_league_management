<?php
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config.php';

$ip = '';
if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
} elseif (!empty($_SERVER['REMOTE_ADDR'])) {
    $ip = $_SERVER['REMOTE_ADDR'];
}
$ip = substr($ip, 0, 50);

$has_posted = false;
if ($ip !== '') {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if (!$conn->connect_error) {
        $stmt = $conn->prepare("SELECT 1 FROM forumthreads WHERE host = ? LIMIT 1");
        $stmt->bind_param("s", $ip);
        $stmt->execute();
        $has_posted = $stmt->get_result()->num_rows > 0;
        $conn->close();
    }
}

echo json_encode(['has_posted' => $has_posted]);
