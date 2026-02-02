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
