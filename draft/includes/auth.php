<?php
/**
 * Draft Authentication
 * Token-based auth for teams and admin
 */

require_once __DIR__ . '/data.php';

/**
 * Validate admin token
 */
function is_valid_admin_token(string $token): bool {
    $session = get_session();
    return $session && hash_equals($session['admin_token'], $token);
}

/**
 * Validate team token and return team data
 */
function validate_team_token(string $token): ?array {
    return get_team_by_token($token);
}

/**
 * Get auth context from request
 */
function get_auth_context(): array {
    $token = $_GET['token'] ?? $_POST['token'] ?? '';
    
    // Check admin first
    $session = get_session();
    if ($session && $token === $session['admin_token']) {
        return [
            'type' => 'admin',
            'token' => $token,
            'team' => null
        ];
    }
    
    // Check team token
    $team = get_team_by_token($token);
    if ($team) {
        return [
            'type' => 'team',
            'token' => $token,
            'team' => $team
        ];
    }
    
    // Public/unauthenticated
    return [
        'type' => 'public',
        'token' => null,
        'team' => null
    ];
}

/**
 * Require admin auth or die
 */
function require_admin(): void {
    $auth = get_auth_context();
    if ($auth['type'] !== 'admin') {
        http_response_code(403);
        die(json_encode(['error' => 'Admin access required']));
    }
}

/**
 * Logged-in site user with the draft admin permission (same check as admin/index.php).
 */
function draft_user_is_site_admin(): bool {
    global $db;
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $userId = trim((string) ($_SESSION['user_id'] ?? ''));
    if ($userId === '') {
        return false;
    }
    require_once __DIR__ . '/../../includes/db.php';
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM ws_user_roles ur
            JOIN ws_role_permissions rp ON ur.role_id = rp.role_id
            JOIN ws_permissions p ON rp.permission_id = p.permission_id
            WHERE ur.user_id = ? AND p.permission_name = ?
        ");
        $stmt->execute([$userId, 'edit player, team, stats']);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('Draft admin permission check failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Require team auth or die
 */
function require_team(): array {
    $auth = get_auth_context();
    if ($auth['type'] !== 'team') {
        http_response_code(403);
        die(json_encode(['error' => 'Team access required']));
    }
    return $auth['team'];
}
