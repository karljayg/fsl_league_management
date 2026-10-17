-- Season 12 draft: player team assignments only (30 players).
-- Team_IDs: HiveMind 15, PSIOP Gaming 6, PulledTheBoys 1, Special Tactics 7 (same on server and local).

-- HiveMind: MygraiN, Infestor-x, Dpoo, Chat-Omic, LanixMagi, Parkanian, Vales
UPDATE Players SET Team_ID = 15 WHERE Player_ID IN (135, 130, 8, 115, 95, 87, 52);

-- PSIOP Gaming: Meadowtownn, DarkMenace, Darkblade, NotSlothinAround, stublu88, Reevou, Nuke
UPDATE Players SET Team_ID = 6 WHERE Player_ID IN (133, 37, 127, 116, 24, 126, 56);

-- PulledTheBoys: AntoineQ, SaraNghae, MvonLipwig, Sopuli, cyan, Multastic, Pacha, WindShadow
UPDATE Players SET Team_ID = 1 WHERE Player_ID IN (93, 124, 114, 109, 6, 134, 120, 97);

-- Special Tactics: HarOuz, Nachoz, RevenantRage, GreatArchon, HurtnTime, SirMalagant, sOs, Warbunnies
UPDATE Players SET Team_ID = 7 WHERE Player_ID IN (43, 35, 30, 99, 44, 51, 103, 57);

