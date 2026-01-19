-- Create Reviewers table for FSL Spider Chart System
CREATE TABLE IF NOT EXISTS Reviewers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    unique_url VARCHAR(255) UNIQUE NOT NULL,
    weight DECIMAL(3,2) DEFAULT 1.00,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Add foreign key constraint to Player_Attribute_Votes if it doesn't exist
-- (This will fail silently if the constraint already exists)
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE 
     WHERE TABLE_NAME = 'Player_Attribute_Votes' 
     AND REFERENCED_TABLE_NAME = 'Reviewers') = 0,
    'ALTER TABLE Player_Attribute_Votes ADD CONSTRAINT fk_reviewer_id FOREIGN KEY (reviewer_id) REFERENCES Reviewers(id)',
    'SELECT "Foreign key constraint already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt; 