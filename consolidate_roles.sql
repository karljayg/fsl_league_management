-- Consolidate Role System
-- This script migrates from the hybrid ws_role field + ws_user_roles table
-- to a clean ws_user_roles table only approach

-- Step 1: Migrate existing ws_role field data to ws_user_roles table
INSERT IGNORE INTO ws_user_roles (user_id, role_id, assigned_at, assigned_by)
SELECT 
    id as user_id,
    ws_role as role_id,
    created_at as assigned_at,
    NULL as assigned_by
FROM users 
WHERE ws_role IS NOT NULL AND ws_role > 0;

-- Step 2: Remove the ws_role column from users table
-- (We'll do this after confirming the migration worked)
-- ALTER TABLE users DROP COLUMN ws_role;

-- Step 3: Update permission checking system to use only ws_user_roles
-- (This will be done in the PHP files)

-- Verification queries:
-- Check migration results
SELECT 
    u.username,
    u.ws_role as old_ws_role,
    GROUP_CONCAT(ur.role_id) as new_roles,
    GROUP_CONCAT(r.role_name) as role_names
FROM users u
LEFT JOIN ws_user_roles ur ON u.id = ur.user_id
LEFT JOIN ws_roles r ON ur.role_id = r.role_id
WHERE u.ws_role IS NOT NULL
GROUP BY u.id, u.username, u.ws_role;

-- Check for any users who might have lost roles
SELECT 
    u.id,
    u.username,
    u.ws_role as old_ws_role,
    COUNT(ur.role_id) as new_role_count
FROM users u
LEFT JOIN ws_user_roles ur ON u.id = ur.user_id
WHERE u.ws_role IS NOT NULL
GROUP BY u.id, u.username, u.ws_role
HAVING new_role_count = 0; 