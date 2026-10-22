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

-- 2026-05-28: Mark players inactive (S11)
UPDATE Players SET Status = 'inactive'
WHERE Real_Name IN (
    'JudgeFae',
    'Poellie',
    'Adastra',
    'KriMiNal',
    'PorkSisig',
    'Instability',
    'ArduousGem'
);

-- 2026-05-29: S11 supplemental draft team assignments (12 players)
-- See s11_draft_team_assignments.sql

-- 2026-05-29: S11 draft player races (FSL_STATISTICS seed for 7 players showing N/A on roster)
-- See s11_draft_player_races.sql

-- 2026-06-03: Season 11 schedule (GSL + playoffs; Saturday anchor in match_date, Fri/Sat shown in UI)
-- Team IDs: PulledTheBoys=1, Angry Space Hares=2, PSIOP Gaming=6, Special Tactics=7
-- Applied on local 2026-06-03; see s11_schedule.sql

-- 2026-10-17: Broadcast casters + managed production roster. Applied on local 2026-10-17.
-- Each fsl_schedule row is one broadcast. Casters are Players linked to a site user
-- (Players.User_ID -> users.id). Production is a managed subset in fsl_production_staff.
-- Freeedom is the default production person. Assign fsl_scheduler from manage_user_roles_fixed.php.

SET @has_caster1 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'fsl_schedule'
      AND COLUMN_NAME = 'caster1_player_id'
);
SET @alter_schedule = IF(@has_caster1 = 0,
    'ALTER TABLE fsl_schedule
        ADD COLUMN caster1_player_id INT NULL,
        ADD COLUMN caster2_player_id INT NULL,
        ADD COLUMN caster_backup_player_id INT NULL,
        ADD COLUMN production_player_id INT NULL,
        ADD KEY idx_fsl_schedule_caster1 (caster1_player_id),
        ADD KEY idx_fsl_schedule_caster2 (caster2_player_id),
        ADD KEY idx_fsl_schedule_caster_backup (caster_backup_player_id),
        ADD KEY idx_fsl_schedule_production (production_player_id),
        ADD CONSTRAINT fsl_schedule_caster1_fk FOREIGN KEY (caster1_player_id) REFERENCES Players (Player_ID) ON DELETE SET NULL,
        ADD CONSTRAINT fsl_schedule_caster2_fk FOREIGN KEY (caster2_player_id) REFERENCES Players (Player_ID) ON DELETE SET NULL,
        ADD CONSTRAINT fsl_schedule_caster_backup_fk FOREIGN KEY (caster_backup_player_id) REFERENCES Players (Player_ID) ON DELETE SET NULL,
        ADD CONSTRAINT fsl_schedule_production_fk FOREIGN KEY (production_player_id) REFERENCES Players (Player_ID) ON DELETE SET NULL',
    'SELECT 1');
PREPARE fsl_sched_staff_alter FROM @alter_schedule;
EXECUTE fsl_sched_staff_alter;
DEALLOCATE PREPARE fsl_sched_staff_alter;

CREATE TABLE IF NOT EXISTS fsl_production_staff (
    production_staff_id INT NOT NULL AUTO_INCREMENT,
    player_id INT NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (production_staff_id),
    UNIQUE KEY uniq_production_player (player_id),
    CONSTRAINT fsl_production_staff_player_fk FOREIGN KEY (player_id) REFERENCES Players (Player_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO fsl_production_staff (player_id, is_default, sort_order, active)
SELECT p.Player_ID, seed.is_default, seed.sort_order, 1
FROM (
    SELECT 'Freeedom' AS Real_Name, 1 AS is_default, 1 AS sort_order
    UNION ALL SELECT 'NuKLeO', 0, 2
    UNION ALL SELECT 'MvonLipwig', 0, 3
    UNION ALL SELECT 'HyperTurtle', 0, 4
) AS seed
JOIN Players p ON p.Real_Name = seed.Real_Name
WHERE NOT EXISTS (
    SELECT 1 FROM fsl_production_staff ps WHERE ps.player_id = p.Player_ID
);

INSERT INTO ws_roles (role_name, description)
SELECT 'fsl_scheduler', 'Can edit the FSL broadcast schedule, casters, and production staff'
WHERE NOT EXISTS (SELECT 1 FROM ws_roles WHERE role_name = 'fsl_scheduler');

INSERT INTO ws_role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM ws_roles r
JOIN ws_permissions p ON p.permission_name = 'manage fsl schedule'
WHERE r.role_name = 'fsl_scheduler'
  AND NOT EXISTS (
      SELECT 1 FROM ws_role_permissions rp
      WHERE rp.role_id = r.role_id AND rp.permission_id = p.permission_id
  );

-- 2026-10-17: !spider private ballots. Not applied yet.
-- One Twitch login, one token. Web choices fold into the TwitchChat tally.
-- See docs/FSL_Spider_Link_API.txt.

CREATE TABLE IF NOT EXISTS spider_ballot_tokens (
    twitch_login VARCHAR(25) NOT NULL,
    token CHAR(32) NOT NULL,
    preselect_match_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (twitch_login),
    UNIQUE KEY uniq_spider_ballot_token (token),
    KEY idx_spider_preselect (preselect_match_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS spider_ballots (
    id INT NOT NULL AUTO_INCREMENT,
    twitch_login VARCHAR(25) NOT NULL,
    fsl_match_id INT NOT NULL,
    attribute ENUM('micro','macro','clutch','creativity','aggression','strategy') NOT NULL,
    side CHAR(1) NOT NULL,
    in_tally TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_spider_ballot (twitch_login, fsl_match_id, attribute),
    KEY idx_spider_ballot_match (fsl_match_id, in_tally),
    CONSTRAINT spider_ballots_match_fk FOREIGN KEY (fsl_match_id) REFERENCES fsl_matches (fsl_match_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 2026-10-18: Per-broadcast staff slots (0..N days) tied to a team matchup row in fsl_schedule.
-- fsl_schedule stays one row per season week (teams, series score, winner). S9–S11 unchanged unless
-- they already had casters on the parent row (copied below into one broadcast row). Apply before s12_schedule.sql on prod.
-- After review: run this block, then s12_schedule_broadcast_migration.sql if prod still has 32 S12 rows,
-- then s12_schedule.sql seeds (only if no season 12 rows) or s12_schedule_broadcast_seed.sql for 16-row S12.

CREATE TABLE IF NOT EXISTS fsl_schedule_broadcasts (
    broadcast_id INT NOT NULL AUTO_INCREMENT,
    schedule_id INT NOT NULL,
    broadcast_at DATETIME NULL,
    slot_label VARCHAR(64) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    cancelled TINYINT(1) NOT NULL DEFAULT 0,
    caster1_player_id INT NULL,
    caster2_player_id INT NULL,
    caster_backup_player_id INT NULL,
    production_player_id INT NULL,
    notes VARCHAR(500) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (broadcast_id),
    KEY idx_fsl_sched_bcast_schedule (schedule_id, sort_order),
    KEY idx_fsl_sched_bcast_at (broadcast_at),
    KEY idx_fsl_sched_bcast_caster1 (caster1_player_id),
    KEY idx_fsl_sched_bcast_production (production_player_id),
    CONSTRAINT fsl_sched_bcasts_schedule_fk FOREIGN KEY (schedule_id) REFERENCES fsl_schedule (schedule_id) ON DELETE CASCADE,
    CONSTRAINT fsl_sched_bcasts_caster1_fk FOREIGN KEY (caster1_player_id) REFERENCES Players (Player_ID) ON DELETE SET NULL,
    CONSTRAINT fsl_sched_bcasts_caster2_fk FOREIGN KEY (caster2_player_id) REFERENCES Players (Player_ID) ON DELETE SET NULL,
    CONSTRAINT fsl_sched_bcasts_caster_backup_fk FOREIGN KEY (caster_backup_player_id) REFERENCES Players (Player_ID) ON DELETE SET NULL,
    CONSTRAINT fsl_sched_bcasts_production_fk FOREIGN KEY (production_player_id) REFERENCES Players (Player_ID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO fsl_schedule_broadcasts (
    schedule_id, broadcast_at, slot_label, sort_order,
    caster1_player_id, caster2_player_id, caster_backup_player_id, production_player_id
)
SELECT
    s.schedule_id,
    s.match_date,
    NULL,
    1,
    s.caster1_player_id,
    s.caster2_player_id,
    s.caster_backup_player_id,
    s.production_player_id
FROM fsl_schedule s
WHERE (
    s.caster1_player_id IS NOT NULL
    OR s.caster2_player_id IS NOT NULL
    OR s.caster_backup_player_id IS NOT NULL
    OR s.production_player_id IS NOT NULL
)
AND NOT EXISTS (
    SELECT 1 FROM fsl_schedule_broadcasts b WHERE b.schedule_id = s.schedule_id
);

-- 2026-10-18 (follow-up): S12 notes without duplicate team names; normalize Fri 7 PM / Sat 12 PM ET on broadcast rows.
-- See s12_schedule_notes_and_broadcast_times.sql (same statements; run on prod after schedule + broadcast seed/realign).

-- 2026-10-21: PlantQueen S4 championship note still says StuBlue. Player row is stublu88. Not shown on the player page.
UPDATE Players
SET Championship_Record = JSON_SET(
  Championship_Record,
  '$.records[0].notes',
  'B : stublu88 def. Charizma 3-1'
)
WHERE Player_ID = 4
  AND JSON_UNQUOTE(JSON_EXTRACT(Championship_Record, '$.records[0].notes')) = 'B : StuBlue def. Charizma 3-1';
