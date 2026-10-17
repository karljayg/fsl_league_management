-- Season 12 draft results (draft completed 2026-10-16, 30 picks, no skips).
-- Source: live draft/data/events.json. Keepers were set by s12_team_setup.sql.
-- Each row matches Player_ID AND Real_Name, so a mismatched ID updates nothing.
-- Safe to run more than once.

START TRANSACTION;

-- HiveMind
UPDATE Players p JOIN Teams t ON t.Team_Name = 'HiveMind' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 135 AND p.Real_Name = 'MygraiN'; -- pick 1
UPDATE Players p JOIN Teams t ON t.Team_Name = 'HiveMind' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 130 AND p.Real_Name = 'Infestor-x'; -- pick 8
UPDATE Players p JOIN Teams t ON t.Team_Name = 'HiveMind' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 8 AND p.Real_Name = 'Dpoo'; -- pick 9
UPDATE Players p JOIN Teams t ON t.Team_Name = 'HiveMind' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 115 AND p.Real_Name = 'Chat-Omic'; -- pick 16
UPDATE Players p JOIN Teams t ON t.Team_Name = 'HiveMind' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 95 AND p.Real_Name = 'LanixMagi'; -- pick 17
UPDATE Players p JOIN Teams t ON t.Team_Name = 'HiveMind' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 87 AND p.Real_Name = 'Parkanian'; -- pick 24
UPDATE Players p JOIN Teams t ON t.Team_Name = 'HiveMind' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 52 AND p.Real_Name = 'Vales'; -- pick 25

-- PSIOP Gaming
UPDATE Players p JOIN Teams t ON t.Team_Name = 'PSIOP Gaming' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 133 AND p.Real_Name = 'Meadowtownn'; -- pick 2
UPDATE Players p JOIN Teams t ON t.Team_Name = 'PSIOP Gaming' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 37 AND p.Real_Name = 'DarkMenace'; -- pick 7
UPDATE Players p JOIN Teams t ON t.Team_Name = 'PSIOP Gaming' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 127 AND p.Real_Name = 'Darkblade'; -- pick 10
UPDATE Players p JOIN Teams t ON t.Team_Name = 'PSIOP Gaming' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 116 AND p.Real_Name = 'NotSlothinAround'; -- pick 15
UPDATE Players p JOIN Teams t ON t.Team_Name = 'PSIOP Gaming' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 24 AND p.Real_Name = 'stublu88'; -- pick 18
UPDATE Players p JOIN Teams t ON t.Team_Name = 'PSIOP Gaming' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 126 AND p.Real_Name = 'Reevou'; -- pick 23
UPDATE Players p JOIN Teams t ON t.Team_Name = 'PSIOP Gaming' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 56 AND p.Real_Name = 'Nuke'; -- pick 26

-- PulledTheBoys
UPDATE Players p JOIN Teams t ON t.Team_Name = 'PulledTheBoys' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 93 AND p.Real_Name = 'AntoineQ'; -- pick 3
UPDATE Players p JOIN Teams t ON t.Team_Name = 'PulledTheBoys' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 124 AND p.Real_Name = 'SaraNghae'; -- pick 6
UPDATE Players p JOIN Teams t ON t.Team_Name = 'PulledTheBoys' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 114 AND p.Real_Name = 'MvonLipwig'; -- pick 11
UPDATE Players p JOIN Teams t ON t.Team_Name = 'PulledTheBoys' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 109 AND p.Real_Name = 'Sopuli'; -- pick 14
UPDATE Players p JOIN Teams t ON t.Team_Name = 'PulledTheBoys' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 6 AND p.Real_Name = 'cyan'; -- pick 19
UPDATE Players p JOIN Teams t ON t.Team_Name = 'PulledTheBoys' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 134 AND p.Real_Name = 'Multastic'; -- pick 22
UPDATE Players p JOIN Teams t ON t.Team_Name = 'PulledTheBoys' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 120 AND p.Real_Name = 'Pacha'; -- pick 27
UPDATE Players p JOIN Teams t ON t.Team_Name = 'PulledTheBoys' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 97 AND p.Real_Name = 'WindShadow'; -- pick 30

-- Special Tactics
UPDATE Players p JOIN Teams t ON t.Team_Name = 'Special Tactics' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 43 AND p.Real_Name = 'HarOuz'; -- pick 4
UPDATE Players p JOIN Teams t ON t.Team_Name = 'Special Tactics' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 35 AND p.Real_Name = 'Nachoz'; -- pick 5
UPDATE Players p JOIN Teams t ON t.Team_Name = 'Special Tactics' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 30 AND p.Real_Name = 'RevenantRage'; -- pick 12
UPDATE Players p JOIN Teams t ON t.Team_Name = 'Special Tactics' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 99 AND p.Real_Name = 'GreatArchon'; -- pick 13
UPDATE Players p JOIN Teams t ON t.Team_Name = 'Special Tactics' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 44 AND p.Real_Name = 'HurtnTime'; -- pick 20
UPDATE Players p JOIN Teams t ON t.Team_Name = 'Special Tactics' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 51 AND p.Real_Name = 'SirMalagant'; -- pick 21
UPDATE Players p JOIN Teams t ON t.Team_Name = 'Special Tactics' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 103 AND p.Real_Name = 'sOs'; -- pick 28
UPDATE Players p JOIN Teams t ON t.Team_Name = 'Special Tactics' SET p.Team_ID = t.Team_ID WHERE p.Player_ID = 57 AND p.Real_Name = 'Warbunnies'; -- pick 29

COMMIT;

-- Expect HiveMind 11, PSIOP Gaming 11, PulledTheBoys 12, Special Tactics 12 active players:
-- SELECT t.Team_Name, COUNT(*) FROM Players p JOIN Teams t ON t.Team_ID = p.Team_ID WHERE p.Status = 'active' AND t.Status = 'active' GROUP BY t.Team_Name;
