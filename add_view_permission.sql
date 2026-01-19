-- Add view spider charts permission to spider chart admin role
-- The role exists but is missing the view permission

-- Add the missing permission assignment
INSERT IGNORE INTO ws_role_permissions (role_id, permission_id) VALUES 
(6, 5); -- spider chart admin -> view spider charts

-- Verification
SELECT 'Role-Permission Assignments for Spider Chart Admin:' as info;
SELECT r.role_name, p.permission_name, p.description
FROM ws_roles r
JOIN ws_role_permissions rp ON r.role_id = rp.role_id
JOIN ws_permissions p ON rp.permission_id = p.permission_id
WHERE r.role_id = 6
ORDER BY p.permission_name;

-- Check user permissions after fix
SELECT 'User Permissions for NeutrophiL after fix:' as info;
SELECT u.username, p.permission_name, p.description, r.role_name
FROM users u
JOIN ws_user_roles ur ON u.id = ur.user_id
JOIN ws_roles r ON ur.role_id = r.role_id
JOIN ws_role_permissions rp ON r.role_id = rp.role_id
JOIN ws_permissions p ON rp.permission_id = p.permission_id
WHERE u.username = 'NeutrophiL'
ORDER BY p.permission_name; 