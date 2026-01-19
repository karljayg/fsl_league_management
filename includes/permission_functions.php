<?php
/**
 * Permission Functions
 * Centralized functions for permission checking
 */

// Function to check for a given permission
function hasPermission($db, $role_id, $permissionName) {
    $sql = "SELECT COUNT(*) AS cnt
            FROM ws_role_permissions rp
            JOIN ws_permissions p ON rp.permission_id = p.permission_id
            WHERE rp.role_id = ? AND p.permission_name = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$role_id, $permissionName]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['cnt'] > 0;
}
?> 