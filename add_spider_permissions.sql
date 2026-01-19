-- Add missing permissions for FSL Spider Chart System
-- Run this SQL to grant access to the new pages

-- Add 'manage spider chart reviewers' permission (if not already exists)
INSERT IGNORE INTO ws_permissions (permission_name, description) 
VALUES ('manage spider chart reviewers', 'Manage spider chart reviewers and voting system');

-- Grant permission to admin role (role_id = 1)
INSERT IGNORE INTO ws_role_permissions (role_id, permission_id) 
SELECT 1, permission_id FROM ws_permissions 
WHERE permission_name = 'manage spider chart reviewers';

-- Grant permissions to any other roles you want to have access
-- For example, if you have a 'moderator' role with role_id = 2:
-- INSERT IGNORE INTO ws_role_permissions (role_id, permission_id) 
-- SELECT 2, permission_id FROM ws_permissions 
-- WHERE permission_name IN ('view spider charts', 'manage spider chart reviewers');

-- Verify the permissions were added
SELECT 
    rp.role_id,
    p.permission_name,
    p.description
FROM ws_role_permissions rp
JOIN ws_permissions p ON rp.permission_id = p.permission_id
WHERE p.permission_name LIKE '%spider%'
ORDER BY rp.role_id, p.permission_name; 