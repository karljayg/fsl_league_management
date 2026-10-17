<?php
/**
 * FSL schedule / match write access (lineup send, edit matches, admin schedule).
 *
 * Policy:
 *   1. admin role → always allowed
 *   2. else fsl_manager or fsl_scheduler role → allowed
 *   3. else manage fsl schedule permission → allowed
 *
 * user_id is varchar(36) in ws_user_roles — never cast to int.
 */

const FSL_ROLE_ADMIN = 'admin';
const FSL_ROLE_MANAGER = 'fsl_manager';
const FSL_ROLE_SCHEDULER = 'fsl_scheduler';
const FSL_PERM_MANAGE_SCHEDULE = 'manage fsl schedule';

/**
 * @param mixed $userId
 */
function fsl_normalize_user_id($userId): string
{
    return trim((string) $userId);
}

/**
 * @param mixed $userId
 */
function fsl_valid_user_id($userId): bool
{
    return fsl_normalize_user_id($userId) !== '';
}

/**
 * @param PDO $dbConn
 * @param mixed $userId
 */
function fsl_user_has_admin_role(PDO $dbConn, $userId): bool
{
    $userId = fsl_normalize_user_id($userId);
    if ($userId === '') {
        return false;
    }
    $stmt = $dbConn->prepare("
        SELECT COUNT(*) AS cnt
        FROM ws_user_roles ur
        JOIN ws_roles r ON ur.role_id = r.role_id
        WHERE ur.user_id = ? AND (r.role_id = 1 OR r.role_name = ?)
    ");
    $stmt->execute([$userId, FSL_ROLE_ADMIN]);
    return ((int) $stmt->fetchColumn()) > 0;
}

/**
 * @param PDO $dbConn
 * @param mixed $userId
 */
function fsl_user_has_role_name(PDO $dbConn, $userId, string $roleName): bool
{
    $userId = fsl_normalize_user_id($userId);
    if ($userId === '') {
        return false;
    }
    $stmt = $dbConn->prepare("
        SELECT COUNT(*) AS cnt
        FROM ws_user_roles ur
        JOIN ws_roles r ON ur.role_id = r.role_id
        WHERE ur.user_id = ? AND r.role_name = ?
    ");
    $stmt->execute([$userId, $roleName]);
    return ((int) $stmt->fetchColumn()) > 0;
}

/**
 * @param PDO $dbConn
 * @param mixed $userId
 */
function fsl_user_has_permission(PDO $dbConn, $userId, string $permissionName): bool
{
    $userId = fsl_normalize_user_id($userId);
    if ($userId === '') {
        return false;
    }
    $stmt = $dbConn->prepare("
        SELECT COUNT(*) AS cnt
        FROM ws_user_roles ur
        JOIN ws_role_permissions rp ON ur.role_id = rp.role_id
        JOIN ws_permissions p ON rp.permission_id = p.permission_id
        WHERE ur.user_id = ? AND p.permission_name = ?
    ");
    $stmt->execute([$userId, $permissionName]);
    return ((int) $stmt->fetchColumn()) > 0;
}

/**
 * @param PDO $dbConn
 * @param mixed $userId
 */
function fsl_can_manage_schedule(PDO $dbConn, $userId): bool
{
    if (!fsl_valid_user_id($userId)) {
        return false;
    }
    $userId = fsl_normalize_user_id($userId);

    if (fsl_user_has_admin_role($dbConn, $userId)) {
        return true;
    }
    if (fsl_user_has_any_role_name($dbConn, $userId, [
        FSL_ROLE_MANAGER,
        FSL_ROLE_SCHEDULER,
        'fsl-manager',
        'fsl-scheduler',
    ])) {
        return true;
    }
    return fsl_user_has_permission($dbConn, $userId, FSL_PERM_MANAGE_SCHEDULE);
}

/**
 * @param PDO $dbConn
 * @param mixed $userId
 * @param string[] $roleNames
 */
function fsl_user_has_any_role_name(PDO $dbConn, $userId, array $roleNames): bool
{
    $userId = fsl_normalize_user_id($userId);
    $roleNames = array_values(array_filter($roleNames, function ($name) {
        return is_string($name) && $name !== '';
    }));
    if ($userId === '' || $roleNames === []) {
        return false;
    }
    $placeholders = implode(',', array_fill(0, count($roleNames), '?'));
    $stmt = $dbConn->prepare("
        SELECT COUNT(*) AS cnt
        FROM ws_user_roles ur
        JOIN ws_roles r ON ur.role_id = r.role_id
        WHERE ur.user_id = ? AND r.role_name IN ($placeholders)
    ");
    $stmt->execute(array_merge([$userId], $roleNames));
    return ((int) $stmt->fetchColumn()) > 0;
}

/**
 * @return string|null Error message for JSON responses, or null if allowed.
 */
function fsl_schedule_auth_error(PDO $dbConn): ?string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id']) || !fsl_valid_user_id($_SESSION['user_id'])) {
        return 'Not logged in. Refresh the page and sign in again.';
    }
    if (!fsl_can_manage_schedule($dbConn, $_SESSION['user_id'])) {
        return 'You do not have access to manage the FSL schedule.';
    }
    return null;
}

/**
 * Lineup planner POST auth — page already gated the button; verify same session via send_key.
 *
 * @return string|null Error message for JSON responses, or null if allowed.
 */
function fsl_lineup_send_auth_error(): ?string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id']) || !fsl_valid_user_id($_SESSION['user_id'])) {
        return 'Your session expired. Refresh the page and try again.';
    }
    $expectedKey = (string) ($_SESSION['lineup_send_key'] ?? '');
    $postedKey = (string) ($_POST['send_key'] ?? '');
    if ($expectedKey === '' || $postedKey === '' || !hash_equals($expectedKey, $postedKey)) {
        return 'Your session expired. Refresh the page and try again.';
    }
    return null;
}

/**
 * Issue or reuse a per-session key when rendering the lineup send button.
 */
function fsl_lineup_send_key(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['lineup_send_key'])) {
        $_SESSION['lineup_send_key'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['lineup_send_key'];
}
