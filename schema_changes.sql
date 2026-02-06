-- Schema change log for FSL
-- Append migration commands here. Do not remove prior entries.
-- See temp-files/SEASON_10_READINESS.md for Season 9→10 transition notes.

-- Season 9→10 (2025-02-01): Applied via temp-files/apply_season10_db_changes.php
-- - 01: PSISOP Gaming, 05: TBD (new teams)
-- - 03: fsl_matches winner_team_id, loser_team_id
-- - 07: Teams.Status (active/defunct)
-- - 02,06,08,09,11: Roster assignments (PSISOP, TBD→Finite Drivers, Angry Space Hares, PulledTheBoys)
-- - 12: Renamed TBD→Finite Drivers, inserted S10 schedule (8 weeks)
-- - 15: Renamed Finite Drivers→Special Tactics (final name)
-- - 13: Reverted extra TBD team; fsl_schedule team1_id/team2_id allow NULL for placeholders

-- 2025-02-01: Fix bad race edits in fsl_matches (temp-files/16_fix_player_races_in_fsl_matches.sql)
-- Chatomic/Chat-Omic→T, NuKLeO/NukLeo→Z, MonkeyShaman→R, RevenantRage S7→P
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
