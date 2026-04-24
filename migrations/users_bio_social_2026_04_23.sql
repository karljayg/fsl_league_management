-- Public profile: bio + structured social links (JSON array of {type, value}).
-- Run once on the server against the same database as `users` (e.g. psistorm).
-- If a column already exists, remove that line before running.

ALTER TABLE users
  ADD COLUMN bio TEXT NULL COMMENT 'Public profile bio (plain text)' AFTER avatar_url,
  ADD COLUMN social_links JSON NULL COMMENT 'JSON array: [{type, value}, ...]; types validated in PHP' AFTER bio;

-- If your server has no JSON type (older MySQL), use LONGTEXT instead of JSON:
-- ALTER TABLE users ADD COLUMN social_links LONGTEXT NULL COMMENT 'JSON string' AFTER bio;
