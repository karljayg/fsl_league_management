-- =====================================================
-- FSL Spider Chart System - Complete Database Setup
-- =====================================================
-- This file contains all SQL statements needed to set up
-- the spider chart voting system for FSL
-- =====================================================

-- Step 1: Create new tables
-- =====================================================

-- Player_Attribute_Votes table - stores individual reviewer votes
CREATE TABLE IF NOT EXISTS Player_Attribute_Votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fsl_match_id INT NOT NULL,
    reviewer_id VARCHAR(255) NOT NULL,
    attribute ENUM('micro', 'macro', 'clutch', 'creativity', 'aggression', 'strategy') NOT NULL,
    vote INT NOT NULL CHECK (vote >= 1 AND vote <= 10),
    player1_id INT NOT NULL,
    player2_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (fsl_match_id) REFERENCES fsl_matches(fsl_match_id) ON DELETE CASCADE,
    FOREIGN KEY (player1_id) REFERENCES Players(Player_ID) ON DELETE CASCADE,
    FOREIGN KEY (player2_id) REFERENCES Players(Player_ID) ON DELETE CASCADE
);

-- Player_Attributes table - stores aggregated spider chart scores
CREATE TABLE IF NOT EXISTS Player_Attributes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    division ENUM('A', 'S') NOT NULL,
    micro DECIMAL(4,2) DEFAULT 0,
    macro DECIMAL(4,2) DEFAULT 0,
    clutch DECIMAL(4,2) DEFAULT 0,
    creativity DECIMAL(4,2) DEFAULT 0,
    aggression DECIMAL(4,2) DEFAULT 0,
    strategy DECIMAL(4,2) DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (player_id) REFERENCES Players(Player_ID) ON DELETE CASCADE,
    UNIQUE KEY unique_player_division (player_id, division)
);

-- Step 2: Add constraints and indexes
-- =====================================================

-- Unique constraint to prevent duplicate votes from same reviewer
ALTER TABLE Player_Attribute_Votes 
ADD CONSTRAINT IF NOT EXISTS unique_reviewer_match_attribute 
UNIQUE (reviewer_id, fsl_match_id, attribute);

-- Performance indexes for Player_Attribute_Votes
CREATE INDEX IF NOT EXISTS idx_player_attribute_votes_match ON Player_Attribute_Votes(fsl_match_id);
CREATE INDEX IF NOT EXISTS idx_player_attribute_votes_reviewer ON Player_Attribute_Votes(reviewer_id);
CREATE INDEX IF NOT EXISTS idx_player_attribute_votes_players ON Player_Attribute_Votes(player1_id, player2_id);
CREATE INDEX IF NOT EXISTS idx_player_attribute_votes_attribute ON Player_Attribute_Votes(attribute);
CREATE INDEX IF NOT EXISTS idx_player_attribute_votes_created ON Player_Attribute_Votes(created_at);

-- Performance indexes for Player_Attributes
CREATE INDEX IF NOT EXISTS idx_player_attributes_division ON Player_Attributes(division);
CREATE INDEX IF NOT EXISTS idx_player_attributes_player ON Player_Attributes(player_id);
CREATE INDEX IF NOT EXISTS idx_player_attributes_updated ON Player_Attributes(last_updated);

-- Step 3: Add permissions
-- =====================================================

-- Add new permissions for spider chart system
INSERT IGNORE INTO ws_permissions (permission_name, description) VALUES
('view spider charts', 'Can view spider chart visualizations and player analysis'),
('manage spider charts', 'Can manage spider chart system, reviewers, and voting data');

-- Step 4: Grant permissions to admin role (role_id = 1)
-- =====================================================

-- Grant spider chart permissions to admin role
INSERT IGNORE INTO ws_role_permissions (role_id, permission_id) 
SELECT 1, permission_id FROM ws_permissions 
WHERE permission_name IN ('view spider charts', 'manage spider charts');

-- Step 5: Clean up old permissions (if they exist)
-- =====================================================

-- Remove old spider chart permissions if they exist
DELETE FROM ws_role_permissions 
WHERE permission_id IN (
    SELECT permission_id FROM ws_permissions 
    WHERE permission_name IN ('manage spider chart reviewers', 'view spider chart reviewers')
);

DELETE FROM ws_permissions 
WHERE permission_name IN ('manage spider chart reviewers', 'view spider chart reviewers');

-- Step 6: Sample data (optional - for testing)
-- =====================================================

-- Sample player attributes (uncomment if needed for testing)
/*
INSERT INTO Player_Attributes (player_id, division, micro, macro, clutch, creativity, aggression, strategy) VALUES
(35, 'A', 7.5, 6.8, 8.2, 7.1, 6.9, 7.3),
(109, 'A', 6.2, 7.8, 5.9, 8.1, 7.4, 6.7);
*/

-- Sample vote data (uncomment if needed for testing)
/*
INSERT INTO Player_Attribute_Votes (fsl_match_id, reviewer_id, attribute, vote, player1_id, player2_id) VALUES
(544, 'reviewer_token_1', 'micro', 7, 109, 35),
(544, 'reviewer_token_1', 'macro', 8, 109, 35),
(544, 'reviewer_token_1', 'clutch', 6, 109, 35),
(544, 'reviewer_token_1', 'creativity', 9, 109, 35),
(544, 'reviewer_token_1', 'aggression', 7, 109, 35),
(544, 'reviewer_token_1', 'strategy', 8, 109, 35);
*/

-- Step 7: Verification queries
-- =====================================================

-- Check table structure
DESCRIBE Player_Attribute_Votes;
DESCRIBE Player_Attributes;

-- Check constraints
SELECT 
    CONSTRAINT_NAME,
    TABLE_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE 
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME IN ('Player_Attribute_Votes', 'Player_Attributes');

-- Check permissions
SELECT 
    p.permission_name,
    r.role_name,
    rp.role_id
FROM ws_permissions p
JOIN ws_role_permissions rp ON p.permission_id = rp.permission_id
JOIN ws_roles r ON rp.role_id = r.role_id
WHERE p.permission_name LIKE '%spider%';

-- Check for orphaned votes (should return 0)
SELECT COUNT(*) as orphaned_votes 
FROM Player_Attribute_Votes pav 
LEFT JOIN fsl_matches fm ON pav.fsl_match_id = fm.fsl_match_id 
WHERE fm.fsl_match_id IS NULL;

-- Check for duplicate votes (should return 0)
SELECT COUNT(*) as duplicate_votes 
FROM (
    SELECT reviewer_id, fsl_match_id, attribute, COUNT(*) as cnt
    FROM Player_Attribute_Votes 
    GROUP BY reviewer_id, fsl_match_id, attribute 
    HAVING cnt > 1
) as duplicates;

-- Step 8: Backup commands (uncomment if needed)
-- =====================================================

-- Create backups of existing data before major changes
/*
CREATE TABLE Player_Attribute_Votes_backup AS SELECT * FROM Player_Attribute_Votes;
CREATE TABLE Player_Attributes_backup AS SELECT * FROM Player_Attributes;
*/

-- =====================================================
-- Setup Complete!
-- =====================================================
-- The spider chart system is now ready to use.
-- 
-- Next steps:
-- 1. Create reviewers.csv file in the root directory
-- 2. Run the aggregation script to calculate initial scores
-- 3. Test the voting system with sample data
-- ===================================================== 