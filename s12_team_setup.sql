-- Season 12 team setup.
-- HiveMind replaces Angry Space Hares. ASH is marked defunct, which the
-- teams page lists under "Inactive Teams" (Teams.Status is active|defunct).
-- Each team keeps captain, co-captain, and two protected players.
-- Other active players on these four teams are cleared (Team_ID NULL) so they
-- can be drafted. Inactive historical rows are left alone.
--
-- S11 finish (for the draft, not stored on Teams):
--   1 Special Tactics   drafts last
--   2 PulledTheBoys
--   3 PSIOP Gaming
--   4 Angry Space Hares drafts first → HiveMind inherits that slot
--
-- Names below are the Real_Name values in Players (Jmpz, FRost, Crankytoaster,
-- NuKLeO, Cubano). Not applied until you run this file.

START TRANSACTION;

INSERT INTO Teams (Team_Name, Captain_ID, Co_Captain_ID, Status)
SELECT 'HiveMind', c.Player_ID, co.Player_ID, 'active'
FROM Players c
JOIN Players co ON co.Player_ID = 105 -- PossiblyMayB
WHERE c.Player_ID = 46 -- Jmpz
  AND NOT EXISTS (SELECT 1 FROM Teams WHERE Team_Name = 'HiveMind');

-- Keepers onto their season 12 teams.
UPDATE Players p
JOIN Teams t ON t.Team_Name = 'HiveMind'
SET p.Team_ID = t.Team_ID, p.Status = 'active'
WHERE p.Player_ID IN (
    46,  -- Jmpz, captain
    105, -- PossiblyMayB, co-captain
    131, -- SunnyD
    129  -- FRost
);

UPDATE Players p
JOIN Teams t ON t.Team_Name = 'Special Tactics'
SET p.Team_ID = t.Team_ID, p.Status = 'active'
WHERE p.Player_ID IN (
    40,  -- Freeedom, captain
    12,  -- HyperTurtle, co-captain
    128, -- Cubano
    101  -- SCVSir
);

UPDATE Players p
JOIN Teams t ON t.Team_Name = 'PulledTheBoys'
SET p.Team_ID = t.Team_ID, p.Status = 'active'
WHERE p.Player_ID IN (
    49,  -- Neutrophil, captain
    58,  -- MonkeyShaman, co-captain
    15,  -- LittleReaper
    123  -- Papella
);

UPDATE Players p
JOIN Teams t ON t.Team_Name = 'PSIOP Gaming'
SET p.Team_ID = t.Team_ID, p.Status = 'active'
WHERE p.Player_ID IN (
    94,  -- NuKLeO, captain
    125, -- Crankytoaster, co-captain
    111, -- MedicJR
    132  -- BeanTheBard
);

UPDATE Teams t
JOIN Players c ON c.Player_ID = 46
JOIN Players co ON co.Player_ID = 105
SET t.Captain_ID = c.Player_ID, t.Co_Captain_ID = co.Player_ID
WHERE t.Team_Name = 'HiveMind';

UPDATE Teams t
JOIN Players c ON c.Player_ID = 40
JOIN Players co ON co.Player_ID = 12
SET t.Captain_ID = c.Player_ID, t.Co_Captain_ID = co.Player_ID
WHERE t.Team_Name = 'Special Tactics';

UPDATE Teams t
JOIN Players c ON c.Player_ID = 49
JOIN Players co ON co.Player_ID = 58
SET t.Captain_ID = c.Player_ID, t.Co_Captain_ID = co.Player_ID
WHERE t.Team_Name = 'PulledTheBoys';

UPDATE Teams t
JOIN Players c ON c.Player_ID = 94
JOIN Players co ON co.Player_ID = 125
SET t.Captain_ID = c.Player_ID, t.Co_Captain_ID = co.Player_ID
WHERE t.Team_Name = 'PSIOP Gaming';

-- Drop every other active player still on these clubs.
UPDATE Players p
JOIN Teams t ON t.Team_ID = p.Team_ID
SET p.Team_ID = NULL
WHERE p.Status = 'active'
  AND t.Team_Name IN ('PulledTheBoys', 'Angry Space Hares', 'PSIOP Gaming', 'Special Tactics', 'HiveMind')
  AND p.Player_ID NOT IN (46, 105, 131, 129, 40, 12, 128, 101, 49, 58, 15, 123, 94, 125, 111, 132);

UPDATE Teams
SET Status = 'defunct'
WHERE Team_Name = 'Angry Space Hares';

-- New active players. No team, so they are in the draft pool. No ranking.
-- Division / race: Meadowtownn B P, Multastic B R, MygraiN S T.
-- Portraits: images/player_thumbnails/{Real_Name}.png (placeholder until replaced).
INSERT INTO Players (Real_Name, Status)
SELECT 'Meadowtownn', 'active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM Players WHERE Real_Name = 'Meadowtownn');

INSERT INTO Players (Real_Name, Status)
SELECT 'Multastic', 'active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM Players WHERE Real_Name = 'Multastic');

INSERT INTO Players (Real_Name, Status)
SELECT 'MygraiN', 'active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM Players WHERE Real_Name = 'MygraiN');

INSERT INTO Player_Aliases (Player_ID, Alias_Name)
SELECT p.Player_ID, p.Real_Name
FROM Players p
WHERE p.Real_Name IN ('Meadowtownn', 'Multastic', 'MygraiN')
  AND NOT EXISTS (
      SELECT 1 FROM Player_Aliases pa
      WHERE pa.Player_ID = p.Player_ID AND pa.Alias_Name = p.Real_Name
  );

INSERT INTO FSL_STATISTICS (Player_ID, Alias_ID, Division, Race, MapsW, MapsL, SetsW, SetsL)
SELECT
    p.Player_ID,
    pa.Alias_ID,
    CASE p.Real_Name
        WHEN 'Meadowtownn' THEN 'B'
        WHEN 'Multastic' THEN 'B'
        WHEN 'MygraiN' THEN 'S'
    END,
    CASE p.Real_Name
        WHEN 'Meadowtownn' THEN 'P'
        WHEN 'Multastic' THEN 'R'
        WHEN 'MygraiN' THEN 'T'
    END,
    0, 0, 0, 0
FROM Players p
JOIN Player_Aliases pa ON pa.Player_ID = p.Player_ID AND pa.Alias_Name = p.Real_Name
WHERE p.Real_Name IN ('Meadowtownn', 'Multastic', 'MygraiN')
  AND NOT EXISTS (
      SELECT 1 FROM FSL_STATISTICS fs WHERE fs.Player_ID = p.Player_ID
  );

COMMIT;

-- After commit, expect 4 active players on each of HiveMind, Special Tactics,
-- PulledTheBoys, and PSIOP Gaming, and Angry Space Hares defunct with none:
-- SELECT t.Team_Name, t.Status, c.Real_Name AS captain, co.Real_Name AS co_captain, p.Real_Name
-- FROM Teams t
-- LEFT JOIN Players c ON c.Player_ID = t.Captain_ID
-- LEFT JOIN Players co ON co.Player_ID = t.Co_Captain_ID
-- LEFT JOIN Players p ON p.Team_ID = t.Team_ID AND p.Status = 'active'
-- WHERE t.Team_Name IN ('HiveMind', 'Angry Space Hares', 'Special Tactics', 'PulledTheBoys', 'PSIOP Gaming')
-- ORDER BY t.Team_Name, p.Real_Name;

-- Active players still pointing at a defunct team become free agents.
-- Inactive players keep their last Team_ID: view_team.php lists them as former members.
-- Applied 2026-10-16: NotSlothinAround (116) was on Rages Raiders (4).
UPDATE Players p
JOIN Teams t ON t.Team_ID = p.Team_ID
SET p.Team_ID = NULL
WHERE p.Status = 'active' AND t.Status = 'defunct';
