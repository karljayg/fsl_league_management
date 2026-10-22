-- Season 12 schedule: double round robin, then playoffs.
-- One fsl_schedule row per matchup week (teams, score, winner).
-- Fri/Sat (or other) broadcast times and staff live in fsl_schedule_broadcasts
-- (see schema_changes.sql 2026-10-18 and s12_schedule_broadcast_seed.sql).
--
-- Teams: A Special Tactics (7), B HiveMind (15), C PSIOP Gaming (6), D PulledTheBoys (1)
-- match_date = Saturday 12:00 PM US Eastern anchor for that matchup week.
-- First week anchor: November 7, 2026.
--
-- Skipped weekends (2026-27 calendar):
--   Nov 27-28  Thanksgiving (Nov 26)
--   Dec 4-5    Off (Philly)
--   Dec 25-26  Christmas
--   Jan 1-2    New Year's Day
-- Group stage ends Feb 20. Semifinals Feb 27 and Mar 6.
-- Third place Mar 13. Grand final Mar 20.

INSERT INTO fsl_schedule (season, week_number, match_date, team1_id, team2_id, status, notes)
SELECT 12, week_number, match_date, team1_id, team2_id, 'scheduled', notes
FROM (
    SELECT 1 AS week_number, '2026-11-07 12:00:00' AS match_date, 7 AS team1_id, 15 AS team2_id, 'Round 1' AS notes
    UNION ALL SELECT 2,  '2026-11-14 12:00:00', 6,  1,  'Round 1'
    UNION ALL SELECT 3,  '2026-11-21 12:00:00', 7,  6,  'Round 2'
    UNION ALL SELECT 4,  '2026-12-12 12:00:00', 15, 1,  'Round 2'
    UNION ALL SELECT 5,  '2026-12-19 12:00:00', 7,  1,  'Round 3'
    UNION ALL SELECT 6,  '2027-01-09 12:00:00', 15, 6,  'Round 3'
    UNION ALL SELECT 7,  '2027-01-16 12:00:00', 15, 7,  'Round 4'
    UNION ALL SELECT 8,  '2027-01-23 12:00:00', 1,  6,  'Round 4'
    UNION ALL SELECT 9,  '2027-01-30 12:00:00', 6,  7,  'Round 5'
    UNION ALL SELECT 10, '2027-02-06 12:00:00', 1,  15, 'Round 5'
    UNION ALL SELECT 11, '2027-02-13 12:00:00', 1,  7,  'Round 6'
    UNION ALL SELECT 12, '2027-02-20 12:00:00', 6,  15, 'Round 6'
    UNION ALL SELECT 13, '2027-02-27 12:00:00', NULL, NULL, 'Semifinal · Seed 2 vs Seed 3'
    UNION ALL SELECT 14, '2027-03-06 12:00:00', NULL, NULL, 'Semifinal · Seed 1 vs Seed 4'
    UNION ALL SELECT 15, '2027-03-13 12:00:00', NULL, NULL, 'Third place · Loser of Seed 1 vs 4 against loser of Seed 2 vs 3'
    UNION ALL SELECT 16, '2027-03-20 12:00:00', NULL, NULL, 'Grand Final · Winners of the semifinals'
) AS weeks
WHERE NOT EXISTS (SELECT 1 FROM fsl_schedule WHERE season = 12);
