-- Add unique constraints to prevent duplicate votes
-- This ensures each reviewer can only vote once per attribute per match

-- Add unique constraint for reviewer + match + attribute combination
ALTER TABLE Player_Attribute_Votes 
ADD CONSTRAINT unique_reviewer_match_attribute 
UNIQUE (reviewer_id, fsl_match_id, attribute);

-- Add index for better performance on vote queries
CREATE INDEX idx_reviewer_match_votes 
ON Player_Attribute_Votes (reviewer_id, fsl_match_id);

-- Add index for attribute-based queries
CREATE INDEX idx_attribute_votes 
ON Player_Attribute_Votes (attribute, fsl_match_id);

-- Add index for player-based queries
CREATE INDEX idx_player_votes 
ON Player_Attribute_Votes (player1_id, player2_id); 