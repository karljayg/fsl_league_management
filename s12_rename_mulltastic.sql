-- Rename Multastic to Mulltastic. Exact match, safe to run twice.

START TRANSACTION;

UPDATE Players
SET Real_Name = 'Mulltastic'
WHERE Real_Name = 'Multastic';

UPDATE Player_Aliases
SET Alias_Name = 'Mulltastic'
WHERE Alias_Name = 'Multastic';

COMMIT;
