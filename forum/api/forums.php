<?php
header('Content-Type: application/json; charset=utf-8');
require_once(dirname(__DIR__) . "/config.php");

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    echo json_encode(['forums' => [], 'error' => 'Connection failed']);
    exit;
}

$res = @$conn->query("SELECT id, title FROM forums ORDER BY id");
if ($res === false) {
    echo json_encode(['forums' => [], 'error' => 'Table forums may not exist; run schema_changes.sql']);
    $conn->close();
    exit;
}
$forums = [];
while ($row = $res->fetch_assoc()) {
    $forums[] = ['id' => (int) $row['id'], 'title' => $row['title']];
}
$conn->close();
echo json_encode(['forums' => $forums]);
