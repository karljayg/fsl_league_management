<?php
/**
 * FSL Spider Chart Score Aggregation Script
 * Calculates weighted scores from votes and populates Player_Attributes table
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include database connection
require_once 'includes/db.php';
require_once 'includes/reviewer_functions.php';
require_once 'config.php';

// Function to normalize scores to 0-10 scale
function normalizeScore($score, $max_possible) {
    if ($max_possible == 0) return 0;
    return ($score / $max_possible) * 10;
}

// Function to apply attribute offset
function applyAttributeOffset($score, $offset, $max_score) {
    // Formula: original score / 2 + 5
    // This scales 0-10 to 5-10 range
    $adjusted_score = ($score / 2) + 5;
    return round($adjusted_score, 2);
}

// Function to get division from t_code
function getDivision($t_code) {
    switch (strtoupper($t_code)) {
        case 'A': return 'A';
        case 'B': return 'B';
        case 'S': return 'S';
        default: return 'S'; // Default to S
    }
}

// Get spider chart configuration
$attribute_offset = $config['spider_chart']['attribute_offset'] ?? 50;
$max_score = $config['spider_chart']['max_score'] ?? 10;

// Reviewer functions now handled by database

echo "Starting FSL Spider Chart Score Aggregation...\n";
echo "Using attribute offset: $attribute_offset, max score: $max_score\n";

// Read reviewers from CSV
$reviewers = getReviewers('active');
$reviewers_by_id = [];
foreach ($reviewers as $reviewer) {
    $reviewers_by_id[$reviewer['id']] = $reviewer;
}

echo "Loaded " . count($reviewers) . " reviewers from CSV.\n";

try {
    $db->beginTransaction();
    
    // Clear existing aggregated scores
    $db->exec("DELETE FROM Player_Attributes");
    echo "Cleared existing Player_Attributes data.\n";
    
    // Get all unique player-division combinations that have votes
    $player_divisions = $db->query("
        SELECT DISTINCT 
            p.Player_ID,
            p.Real_Name,
            COALESCE(
                CASE 
                    WHEN fm.t_code = 'A' THEN 'A'
                    WHEN fm.t_code = 'B' THEN 'B'
                    WHEN fm.t_code = 'S' THEN 'S'
                    ELSE 'S'
                END,
                'S'
            ) as division
        FROM Players p
        JOIN Player_Attribute_Votes pav ON (p.Player_ID = pav.player1_id OR p.Player_ID = pav.player2_id)
        JOIN fsl_matches fm ON pav.fsl_match_id = fm.fsl_match_id
        WHERE p.Status = 'active'
        ORDER BY p.Real_Name, division
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($player_divisions) . " player-division combinations to process.\n";
    
    foreach ($player_divisions as $player_division) {
        $player_id = $player_division['Player_ID'];
        $player_name = $player_division['Real_Name'];
        $division = $player_division['division'];
        
        echo "Processing $player_name ($division)...\n";
        
        // Calculate weighted scores for each attribute
        $attributes = ['micro', 'macro', 'clutch', 'creativity', 'aggression', 'strategy'];
        $scores = [];
        
        foreach ($attributes as $attribute) {
            // Get all votes for this player in this division
            $stmt = $db->prepare("
                SELECT 
                    pav.reviewer_id,
                    pav.vote,
                    pav.player1_id,
                    pav.player2_id
                FROM Player_Attribute_Votes pav
                JOIN fsl_matches fm ON pav.fsl_match_id = fm.fsl_match_id
                WHERE pav.attribute = ?
                AND (pav.player1_id = ? OR pav.player2_id = ?)
                AND fm.t_code = ?
            ");
            
            $stmt->execute([$attribute, $player_id, $player_id, $division]);
            $votes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $positive_votes = 0;
            $negative_votes = 0;
            $total_votes = count($votes);
            
            // Calculate weighted votes using CSV data
            foreach ($votes as $vote) {
                $reviewer_id = $vote['reviewer_id'];
                $vote_value = $vote['vote'];
                $weight = isset($reviewers_by_id[$reviewer_id]) ? $reviewers_by_id[$reviewer_id]['weight'] : 1.0;
                
                if (($vote['player1_id'] == $player_id && $vote_value == 1) || 
                    ($vote['player2_id'] == $player_id && $vote_value == 2)) {
                    $positive_votes += $weight;
                } elseif (($vote['player1_id'] == $player_id && $vote_value == 2) || 
                         ($vote['player2_id'] == $player_id && $vote_value == 1)) {
                    $negative_votes += $weight;
                }
            }
            
            // Calculate total weight for normalization
            $total_weight = 0;
            foreach ($votes as $vote) {
                $reviewer_id = $vote['reviewer_id'];
                $weight = isset($reviewers_by_id[$reviewer_id]) ? $reviewers_by_id[$reviewer_id]['weight'] : 1.0;
                $total_weight += $weight;
            }
            
            // Normalize to 0-10 scale
            // If total_weight is 0, score is 5 (neutral)
            if ($total_weight == 0) {
                $normalized_score = 5.0;
            } else {
                // Convert to 0-10 scale where 0 = all negative, 10 = all positive
                $normalized_score = ($positive_votes / $total_weight) * 10;
                $normalized_score = max(0, min(10, $normalized_score)); // Clamp to 0-10
            }
            
            // Apply attribute offset to prevent 0 scores
            $final_score = applyAttributeOffset($normalized_score, $attribute_offset, $max_score);
            $scores[$attribute] = round($final_score, 2);
        }
        
        // Insert or update the player's attributes
        $stmt = $db->prepare("
            INSERT INTO Player_Attributes 
            (player_id, division, micro, macro, clutch, creativity, aggression, strategy) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            micro = VALUES(micro),
            macro = VALUES(macro),
            clutch = VALUES(clutch),
            creativity = VALUES(creativity),
            aggression = VALUES(aggression),
            strategy = VALUES(strategy)
        ");
        
        $stmt->execute([
            $player_id,
            $division,
            $scores['micro'],
            $scores['macro'],
            $scores['clutch'],
            $scores['creativity'],
            $scores['aggression'],
            $scores['strategy']
        ]);
        
        echo "  Scores for $player_name ($division): " . 
             "Micro: {$scores['micro']}, " .
             "Macro: {$scores['macro']}, " .
             "Clutch: {$scores['clutch']}, " .
             "Creativity: {$scores['creativity']}, " .
             "Aggression: {$scores['aggression']}, " .
             "Strategy: {$scores['strategy']}\n";
    }
    
    $db->commit();
    echo "\nAggregation completed successfully!\n";
    
    // Show summary statistics
    $total_players = $db->query("SELECT COUNT(*) FROM Player_Attributes")->fetchColumn();
    $total_votes = $db->query("SELECT COUNT(*) FROM Player_Attribute_Votes")->fetchColumn();
    $active_reviewers = 0;
    foreach ($reviewers as $reviewer) {
        if ($reviewer['status'] === 'active') {
            $active_reviewers++;
        }
    }
    
    echo "\nSummary:\n";
    echo "- Total players with scores: $total_players\n";
    echo "- Total votes cast: $total_votes\n";
    echo "- Active reviewers: $active_reviewers\n";
    
} catch (PDOException $e) {
    $db->rollBack();
    echo "Error during aggregation: " . $e->getMessage() . "\n";
    exit(1);
}

// Optional: Show some sample results
echo "\nSample Results:\n";
$sample_results = $db->query("
    SELECT 
        pa.*,
        p.Real_Name
    FROM Player_Attributes pa
    JOIN Players p ON pa.player_id = p.Player_ID
    ORDER BY p.Real_Name, pa.division
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($sample_results as $result) {
    echo "- {$result['Real_Name']} ({$result['division']}): " .
         "Micro: {$result['micro']}, " .
         "Macro: {$result['macro']}, " .
         "Clutch: {$result['clutch']}, " .
         "Creativity: {$result['creativity']}, " .
         "Aggression: {$result['aggression']}, " .
         "Strategy: {$result['strategy']}\n";
}

echo "\nAggregation script completed.\n";
?> 