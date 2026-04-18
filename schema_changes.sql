-- Schema change log for FSL
-- Append migration commands here. Do not remove prior entries.
-- See temp-files/SEASON_10_READINESS.md for Season 9ΓåÆ10 transition notes.

-- Season 9ΓåÆ10 (2025-02-01): Applied via temp-files/apply_season10_db_changes.php
-- - 01: PSISOP Gaming, 05: TBD (new teams)
-- - 03: fsl_matches winner_team_id, loser_team_id
-- - 07: Teams.Status (active/defunct)
-- - 02,06,08,09,11: Roster assignments (PSISOP, TBDΓåÆFinite Drivers, Angry Space Hares, PulledTheBoys)
-- - 12: Renamed TBDΓåÆFinite Drivers, inserted S10 schedule (8 weeks)
-- - 15: Renamed Finite DriversΓåÆSpecial Tactics (final name)
-- - 13: Reverted extra TBD team; fsl_schedule team1_id/team2_id allow NULL for placeholders

-- 2025-02-01: Fix bad race edits in fsl_matches (temp-files/16_fix_player_races_in_fsl_matches.sql)
-- Chatomic/Chat-OmicΓåÆT, NuKLeO/NukLeoΓåÆZ, MonkeyShamanΓåÆR, RevenantRage S7ΓåÆP
-- Run update_player_statistics.php after applying

-- Forum (forumDB): fix invalid datetime defaults (strict mode) then add site_user_id.
-- Step 1: already done (MODIFY date, last to CURRENT_TIMESTAMP default).
-- Step 2: relax sql_mode so we can find and fix stored '0000-00-00 00:00:00' values, then add column, then restore mode.
-- Save current mode first: SELECT @@SESSION.sql_mode;
-- SET SESSION sql_mode = (SELECT REPLACE(REPLACE(REPLACE(@@sql_mode, 'NO_ZERO_DATE', ''), 'STRICT_TRANS_TABLES', ''), 'STRICT_ALL_TABLES', ''));
-- UPDATE forumthreads SET date = '1970-01-01 00:00:00' WHERE date = '0000-00-00 00:00:00';
-- UPDATE forumthreads SET last = '1970-01-01 00:00:00' WHERE last = '0000-00-00 00:00:00';
-- ALTER TABLE forumthreads ADD COLUMN site_user_id INT NULL;
-- SET SESSION sql_mode = '...';  -- restore value from SELECT above

-- 2026-04-10: Twitch Chat Voting API
-- Adds tally columns to Player_Attribute_Votes, voting_sessions table, and bot reviewer row.

-- 1. Add tally columns to Player_Attribute_Votes (NULL for human reviewer rows)
ALTER TABLE Player_Attribute_Votes
    ADD COLUMN IF NOT EXISTS tally_player1 INT NULL AFTER vote,
    ADD COLUMN IF NOT EXISTS tally_player2 INT NULL AFTER tally_player1,
    ADD COLUMN IF NOT EXISTS tally_tie     INT NULL AFTER tally_player2;

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

-- 3. Bot reviewer entry (skip if already exists)
INSERT IGNORE INTO reviewers (name, unique_url, weight, status)
VALUES ('TwitchChat', 'bot-internal-not-for-web', 1.00, 'active');

-- 4. Unique constraint on Player_Attribute_Votes for upsert support
--    (one vote per reviewer per match per attribute)
ALTER TABLE Player_Attribute_Votes
    ADD UNIQUE KEY IF NOT EXISTS unique_reviewer_match_attr (reviewer_id, fsl_match_id, attribute);

-- 2026-04-17: Rankings community voting (ballot permission; super still uses "edit player, team, stats")
INSERT IGNORE INTO ws_permissions (permission_name, description)
VALUES (
    'rankings community vote',
    'Submit a community rankings ballot when a voting window is open'
);
-- Optional: grant to a role, e.g. admin (role_id = 1)
-- INSERT IGNORE INTO ws_role_permissions (role_id, permission_id)
-- SELECT 1, permission_id FROM ws_permissions WHERE permission_name = 'rankings community vote';
