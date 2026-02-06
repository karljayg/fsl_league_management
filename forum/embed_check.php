<?php
/**
 * One-off server diagnostic for embeds. Run on server then delete.
 * URL: https://psistorm.com/fsl/forum/embed_check.php?id=2037406476
 */
$postId = isset($_GET['id']) ? (int)$_GET['id'] : 2037406476;
header('Content-Type: text/plain; charset=utf-8');

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

echo "Root (parent of forum/): " . $root . "\n";
echo "Looking for: " . $autoload . "\n";
echo "vendor/autoload.php exists: " . (is_file($autoload) ? 'YES' : 'NO') . "\n";
echo "vendor/autoload.php readable: " . (is_readable($autoload) ? 'YES' : 'NO') . "\n\n";

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/embed_helper.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    echo "DB error: " . $conn->connect_error . "\n";
    exit;
}
$stmt = $conn->prepare("SELECT body FROM forumbodies WHERE id = ?");
$stmt->bind_param("i", $postId);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    echo "No body for post id=$postId\n";
    $conn->close();
    exit;
}
$raw = $res->fetch_assoc()['body'];
$conn->close();

$urls = extract_urls_from_text($raw);
echo "Post body length: " . strlen($raw) . "\n";
echo "Extracted URLs: " . count($urls) . "\n";
foreach ($urls as $u) echo "  - " . $u . "\n";

$html = post_body_with_embeds($raw);
$hasBlock = (strpos($html, 'post-embeds') !== false);
echo "\nHas embed block in output: " . ($hasBlock ? 'YES' : 'NO') . "\n";
echo "Output length: " . strlen($html) . "\n";
