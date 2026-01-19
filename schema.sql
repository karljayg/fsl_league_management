-- Create users table
CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(36) PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('user', 'pro') NOT NULL,
    mmr INT,
    race_preference ENUM('Protoss', 'Terran', 'Zerg', 'Random'),
    avatar_url VARCHAR(255),
    auth_token VARCHAR(64) UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create matches table
CREATE TABLE IF NOT EXISTS matches (
    id VARCHAR(36) PRIMARY KEY,
    pro_id VARCHAR(36) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    status ENUM('scheduled', 'in_progress', 'completed', 'cancelled') NOT NULL,
    date DATE NOT NULL,
    time TIME NOT NULL,
    match_type VARCHAR(50) NOT NULL,
    min_bid DECIMAL(10,2) NOT NULL,
    current_bid DECIMAL(10,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pro_id) REFERENCES users(id)
);

-- Create bids table``````
CREATE TABLE IF NOT EXISTS bids (
    id VARCHAR(36) PRIMARY KEY,
    match_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending', 'accepted', 'rejected') NOT NULL,
    bid_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (match_id) REFERENCES matches(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
); 