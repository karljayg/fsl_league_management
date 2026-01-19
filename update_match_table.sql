-- Modify the matches table to update match_type and add result tracking
ALTER TABLE matches 
    MODIFY COLUMN match_type ENUM('1v1', '1v2', '2v2', 'FFA', 'other') NOT NULL,
    ADD COLUMN winning_team INT NULL AFTER current_bid,
    ADD COLUMN match_completed BOOLEAN DEFAULT FALSE AFTER winning_team,
    ADD COLUMN result_description TEXT NULL AFTER match_completed;

-- Create a new table to track player participation and team assignments
CREATE TABLE IF NOT EXISTS match_players (
    id VARCHAR(36) PRIMARY KEY,
    match_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    team_id INT NOT NULL,
    is_pro BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY (match_id, user_id)
);

-- Add indexes for better performance
CREATE INDEX idx_match_players_match_id ON match_players(match_id);
CREATE INDEX idx_match_players_user_id ON match_players(user_id);
CREATE INDEX idx_match_players_team ON match_players(match_id, team_id); 