-- Intro videos for the Season 12 players. File names checked on the stream server.
-- Meadowtownn's file is FSL_MeadowTownn.mp4 (capital T).

UPDATE Players
SET Intro_Url = 'https://psistorm.com/stream_production/production_files/video/FSL_MeadowTownn.mp4'
WHERE Player_ID = 133 AND Real_Name = 'Meadowtownn';

UPDATE Players
SET Intro_Url = 'https://psistorm.com/stream_production/production_files/video/FSL_Mulltastic.mp4'
WHERE Player_ID = 134 AND Real_Name = 'Mulltastic';

UPDATE Players
SET Intro_Url = 'https://psistorm.com/stream_production/production_files/video/FSL_MygraiN.mp4'
WHERE Player_ID = 135 AND Real_Name = 'MygraiN';
