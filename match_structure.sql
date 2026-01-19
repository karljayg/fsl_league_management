-- Match structure changes for StormClash
-- Complete recreation of match-related tables

-- Temporarily disable foreign key checks to allow dropping tables with references
SET FOREIGN_KEY_CHECKS = 0;

-- Drop tables if they exist
DROP TABLE IF EXISTS match_players;
DROP TABLE IF EXISTS matches;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Create matches table
CREATE TABLE matches (
    id VARCHAR(36) PRIMARY KEY,
    pro_id VARCHAR(36) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    status ENUM('scheduled', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'scheduled',
    date DATE NOT NULL,
    time TIME NOT NULL,
    match_type ENUM('1v1', '1v2', '2v2', 'FFA', 'other') NOT NULL DEFAULT '1v1',
    min_bid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    winning_team INT NULL,
    match_completed BOOLEAN DEFAULT FALSE,
    result_description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pro_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Create match_players table
CREATE TABLE match_players (
    id VARCHAR(36) PRIMARY KEY,
    match_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    team_id INT NOT NULL,
    is_pro BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Add indexes for better performance
CREATE INDEX idx_matches_status ON matches(status);
CREATE INDEX idx_matches_pro_id ON matches(pro_id);
CREATE INDEX idx_match_players_match_id ON match_players(match_id);
CREATE INDEX idx_match_players_user_id ON match_players(user_id);
CREATE INDEX idx_match_players_team_id ON match_players(team_id); 