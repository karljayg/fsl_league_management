-- Update the avatar_url column for existing users if it's NULL
ALTER TABLE users MODIFY COLUMN avatar_url VARCHAR(255) DEFAULT NULL;

-- You can run this to verify users without avatar_url
-- SELECT id, username, avatar_url FROM users WHERE avatar_url IS NULL;

-- If you need to reset all avatar_url values for testing
-- UPDATE users SET avatar_url = NULL; 