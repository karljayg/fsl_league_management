-- Season 12 schedule: double round robin, then playoffs.
-- Not applied. Safe to run twice: does nothing if any season 12 row exists.
--
-- Teams: A Special Tactics (7), B HiveMind (15), C PSIOP Gaming (6), D PulledTheBoys (1)
-- Two broadcasts each weekend: Friday 7:00 PM and Saturday 12:00 PM US Eastern.
-- First broadcast is Friday, November 6, 2026.
--
-- Skipped weekends (2026 calendar):
--   Nov 27-28  Thanksgiving (Nov 26)
--   Dec 25-26  Christmas
--   Jan 1-2    New Year's Day
-- Group stage ends Dec 19. Semifinals Jan 8-9. Third place and final Jan 15-16.
-- Playoff teams stay NULL until the group seeds are known.

INSERT INTO fsl_schedule (season, week_number, match_date, team1_id, team2_id, status, notes)
SELECT 12, week_number, match_date, team1_id, team2_id, 'scheduled', notes
FROM (
    SELECT 1 AS week_number, '2026-11-06 19:00:00' AS match_date, 7 AS team1_id, 15 AS team2_id, 'Round 1 · Friday · Special Tactics vs HiveMind' AS notes
    UNION ALL SELECT 2,  '2026-11-07 12:00:00', 6,  1,  'Round 1 · Saturday · PSIOP Gaming vs PulledTheBoys'
    UNION ALL SELECT 3,  '2026-11-13 19:00:00', 7,  6,  'Round 2 · Friday · Special Tactics vs PSIOP Gaming'
    UNION ALL SELECT 4,  '2026-11-14 12:00:00', 15, 1,  'Round 2 · Saturday · HiveMind vs PulledTheBoys'
    UNION ALL SELECT 5,  '2026-11-20 19:00:00', 7,  1,  'Round 3 · Friday · Special Tactics vs PulledTheBoys'
    UNION ALL SELECT 6,  '2026-11-21 12:00:00', 15, 6,  'Round 3 · Saturday · HiveMind vs PSIOP Gaming'
    UNION ALL SELECT 7,  '2026-12-04 19:00:00', 15, 7,  'Round 4 · Friday · HiveMind vs Special Tactics'
    UNION ALL SELECT 8,  '2026-12-05 12:00:00', 1,  6,  'Round 4 · Saturday · PulledTheBoys vs PSIOP Gaming'
    UNION ALL SELECT 9,  '2026-12-11 19:00:00', 6,  7,  'Round 5 · Friday · PSIOP Gaming vs Special Tactics'
    UNION ALL SELECT 10, '2026-12-12 12:00:00', 1,  15, 'Round 5 · Saturday · PulledTheBoys vs HiveMind'
    UNION ALL SELECT 11, '2026-12-18 19:00:00', 1,  7,  'Round 6 · Friday · PulledTheBoys vs Special Tactics'
    UNION ALL SELECT 12, '2026-12-19 12:00:00', 6,  15, 'Round 6 · Saturday · PSIOP Gaming vs HiveMind'
    UNION ALL SELECT 13, '2027-01-08 19:00:00', NULL, NULL, 'Semifinal · Friday · Seed 2 vs Seed 3'
    UNION ALL SELECT 14, '2027-01-09 12:00:00', NULL, NULL, 'Semifinal · Saturday · Seed 1 vs Seed 4'
    UNION ALL SELECT 15, '2027-01-15 19:00:00', NULL, NULL, 'Third place · Friday · Loser of Seed 1 vs 4 against loser of Seed 2 vs 3'
    UNION ALL SELECT 16, '2027-01-16 12:00:00', NULL, NULL, 'Grand Final · Saturday · Winners of the semifinals'
) AS weeks
WHERE NOT EXISTS (SELECT 1 FROM fsl_schedule WHERE season = 12);
