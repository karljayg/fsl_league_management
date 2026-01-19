-- Update Spider Chart Permissions
-- Remove old permissions and add simplified ones

-- Remove old permissions
DELETE FROM ws_role_permissions 
WHERE permission_id IN (
    SELECT permission_id FROM ws_permissions 
    WHERE permission_name IN ('view spider charts', 'manage spider chart reviewers')
);

DELETE FROM ws_permissions 
WHERE permission_name IN ('view spider charts', 'manage spider chart reviewers');

-- Add new simplified permissions
INSERT IGNORE INTO ws_permissions (permission_name, description) 
VALUES 
('view spider charts', 'View spider chart visualizations and player profiles'),
('manage spider charts', 'Manage spider chart reviewers and voting system');

-- Grant permissions to admin role (role_id = 1)
INSERT IGNORE INTO ws_role_permissions (role_id, permission_id) 
SELECT 1, permission_id FROM ws_permissions 
WHERE permission_name IN ('view spider charts', 'manage spider charts'); 