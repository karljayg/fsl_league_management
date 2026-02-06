<?php
/**
 * Forum admin auth - uses same permission as chat: 'chat admin'
 */
function forum_has_chat_admin() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    try {
        $dbFile = dirname(__DIR__) . '/includes/db.php';
        if (!file_exists($dbFile)) {
            return false;
        }
        require_once $dbFile;
        if (!isset($db_host) || !isset($db_name) || !isset($db_user) || !isset($db_pass)) {
            return false;
        }
        $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $db->prepare("
            SELECT COUNT(*) AS cnt
            FROM ws_user_roles ur
            JOIN ws_role_permissions rp ON ur.role_id = rp.role_id
            JOIN ws_permissions p ON rp.permission_id = p.permission_id
            WHERE ur.user_id = ? AND p.permission_name = 'chat admin'
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($result && $result['cnt'] > 0);
    } catch (Exception $e) {
        return false;
    }
}
