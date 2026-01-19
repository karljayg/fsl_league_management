-- Fix Spider Chart Permissions
-- Add missing role and permissions for spider chart system

-- Step 1: Add the missing spider chart admin role
INSERT IGNORE INTO ws_roles (role_id, role_name, description) VALUES 
(6, 'spider chart admin', 'can do all spider chart analytics and administration');

-- Step 2: Add the missing permissions
INSERT IGNORE INTO ws_permissions (permission_id, permission_name, description) VALUES 
(6, 'manage spider charts', 'Can manage spider chart system, reviewers, and voting data'),
(7, 'view spider charts', 'Can view spider chart analytics and player profiles');

-- Step 3: Assign permissions to roles
-- Admin gets both permissions
INSERT IGNORE INTO ws_role_permissions (role_id, permission_id) VALUES 
(1, 6), -- admin -> manage spider charts
(1, 7); -- admin -> view spider charts

-- Spider chart admin gets both permissions
INSERT IGNORE INTO ws_role_permissions (role_id, permission_id) VALUES 
(6, 6), -- spider chart admin -> manage spider charts
(6, 7); -- spider chart admin -> view spider charts

-- Step 4: Add the user to spider chart admin role (if not already there)
INSERT IGNORE INTO ws_user_roles (user_id, role_id, assigned_at, assigned_by) VALUES 
('usr_67c27109bc50a4.84327447', 6, NOW(), 'e9e86a4c-f5fc-11ef-912f-047c168baaeb');

-- Verification queries:
SELECT 'Roles:' as info;
SELECT * FROM ws_roles ORDER BY role_id;

SELECT 'Permissions:' as info;
SELECT * FROM ws_permissions ORDER BY permission_id;

SELECT 'Role-Permission Assignments:' as info;
SELECT r.role_name, p.permission_name
FROM ws_role_permissions rp
JOIN ws_roles r ON rp.role_id = r.role_id
JOIN ws_permissions p ON rp.permission_id = p.permission_id
ORDER BY r.role_name, p.permission_name;

SELECT 'User Roles for NeutrophiL:' as info;
SELECT u.username, r.role_name, r.description
FROM users u
JOIN ws_user_roles ur ON u.id = ur.user_id
JOIN ws_roles r ON ur.role_id = r.role_id
WHERE u.username = 'NeutrophiL'
ORDER BY r.role_name;

SELECT 'User Permissions for NeutrophiL:' as info;
SELECT u.username, p.permission_name, p.description, r.role_name
FROM users u
JOIN ws_user_roles ur ON u.id = ur.user_id
JOIN ws_roles r ON ur.role_id = r.role_id
JOIN ws_role_permissions rp ON r.role_id = rp.role_id
JOIN ws_permissions p ON rp.permission_id = p.permission_id
WHERE u.username = 'NeutrophiL'
ORDER BY p.permission_name; 