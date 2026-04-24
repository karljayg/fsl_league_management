-- FSL Voting API Migration — 2026-04-10
-- Run once on server. Safe to run on a DB that hasn't had these applied yet.
-- All statements use IF NOT EXISTS / INSERT IGNORE to be idempotent.

-- 1. Add tally columns to Player_Attribute_Votes
ALTER TABLE Player_Attribute_Votes
    ADD COLUMN tally_player1 INT NULL AFTER vote,
    ADD COLUMN tally_player2 INT NULL AFTER tally_player1,
    ADD COLUMN tally_tie     INT NULL AFTER tally_player2;

-- 2. Create voting_sessions table
CREATE TABLE IF NOT EXISTS voting_sessions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    fsl_match_id  INT NOT NULL,
    enabled_by    VARCHAR(255) NULL,
    channel       VARCHAR(255) NULL,
    enabled_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at    TIMESTAMP NOT NULL,
    closed_at     TIMESTAMP NULL,
    status        ENUM('open','closed','expired') DEFAULT 'open',
    FOREIGN KEY (fsl_match_id) REFERENCES fsl_matches(fsl_match_id) ON DELETE CASCADE,
    INDEX idx_vs_status  (status),
    INDEX idx_vs_match   (fsl_match_id),
    INDEX idx_vs_expires (expires_at)
);

-- 3. Unique constraint on votes for upsert support
ALTER TABLE Player_Attribute_Votes
    ADD UNIQUE KEY unique_reviewer_match_attr (reviewer_id, fsl_match_id, attribute);

-- 4. Bot reviewer entry
INSERT IGNORE INTO reviewers (name, unique_url, weight, status)
    VALUES ('TwitchChat', 'bot-internal-not-for-web', 1.00, 'active');
