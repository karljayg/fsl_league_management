<?php
/**
 * POST-only: empties application cache/ (preserves .gitkeep).
 * Allowed: admin role, or permission "edit player, team, stats".
 */
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['clear_cache'])) {
    header('Location: fsl_season.php', true, 302);
    exit;
}

require_once __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    exit('Forbidden');
}

$user_id = $_SESSION['user_id'];

function userHasAdminRoleForCacheClear(PDO $db, $user_id) {
    $stmt = $db->prepare("
        SELECT COUNT(*) AS c
        FROM ws_user_roles ur
        JOIN ws_roles r ON ur.role_id = r.role_id
        WHERE ur.user_id = ? AND (r.role_id = 1 OR r.role_name = 'admin')
    ");
    $stmt->execute([$user_id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r && (int) $r['c'] > 0;
}

function userHasEditPlayerTeamStatsPermission(PDO $db, $user_id) {
    $stmt = $db->prepare("
        SELECT COUNT(*) AS c
        FROM ws_user_roles ur
        JOIN ws_role_permissions rp ON ur.role_id = rp.role_id
        JOIN ws_permissions p ON rp.permission_id = p.permission_id
        WHERE ur.user_id = ? AND p.permission_name = 'edit player, team, stats'
    ");
    $stmt->execute([$user_id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r && (int) $r['c'] > 0;
}

if (!userHasAdminRoleForCacheClear($db, $user_id) && !userHasEditPlayerTeamStatsPermission($db, $user_id)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Forbidden');
}

$cacheRoot = __DIR__ . '/cache';
if (!is_dir($cacheRoot)) {
    @mkdir($cacheRoot, 0755, true);
}

$cacheReal = realpath($cacheRoot);
if ($cacheReal === false) {
    header('HTTP/1.1 500 Internal Server Error');
    exit('Cache directory unavailable');
}

function clearApplicationCacheDir($dirPath, $allowedBase) {
    $dirPath = realpath($dirPath);
    if ($dirPath === false || $allowedBase === false || strpos($dirPath, $allowedBase) !== 0) {
        return;
    }
    foreach (scandir($dirPath) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if ($item === '.gitkeep') {
            continue;
        }
        $full = $dirPath . DIRECTORY_SEPARATOR . $item;
        if (is_dir($full)) {
            clearApplicationCacheDir($full, $allowedBase);
            @rmdir($full);
        } else {
            @unlink($full);
        }
    }
}

clearApplicationCacheDir($cacheReal, $cacheReal);

$redirect = 'fsl_season.php';
if (!empty($_SERVER['HTTP_REFERER'])) {
    $ref = $_SERVER['HTTP_REFERER'];
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $refHost = parse_url($ref, PHP_URL_HOST);
    if ($host !== '' && $refHost !== null && strcasecmp((string) $refHost, (string) $host) === 0) {
        $redirect = $ref;
    }
}

header('Location: ' . $redirect, true, 302);
exit;
