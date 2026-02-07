<?php
/**
 * Legacy redirect: old forum used new_post.php?tid=XXX.
 * Maps tid to new postid and redirects to index.php?postid=XXX.
 */
$tid = isset($_GET['tid']) ? (int) $_GET['tid'] : 0;
if ($tid <= 0) {
    header('Location: index.php', true, 302);
    exit;
}

$map = require __DIR__ . '/legacy_redirects.php';
$postid = isset($map[$tid]) ? (int) $map[$tid] : 0;

if ($postid > 0) {
    header('Location: index.php?postid=' . $postid, true, 301);
    exit;
}

header('Location: index.php', true, 302);
exit;
