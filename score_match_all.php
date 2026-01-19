<?php
/**
 * FSL Spider Chart Match Scoring Interface - FIXED VERSION
 * URL-based access for reviewers to score matches
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Include database connection
require_once 'includes/db.php';
require_once 'includes/reviewer_functions.php';

$reviewer = null;
$message = '';
$error = '';

// Reviewer authentication now handled by database functions

// Get reviewer from URL token
if (isset($_GET['token'])) {
    $token = $_GET['token'];
    $reviewer = getReviewerByToken($token);
    
    if (!$reviewer) {
        $error = "Invalid or inactive reviewer token.";
    }
} else {
    $error = "No reviewer token provided.";
}

// Handle vote submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reviewer) {
    try {
        $fsl_match_id = $_POST['fsl_match_id'];
        $player1_id = $_POST['player1_id'];
        $player2_id = $_POST['player2_id'];
        
        // Get match details to verify
        $stmt = $db->prepare("SELECT * FROM fsl_matches WHERE fsl_match_id = ?");
        $stmt->execute([$fsl_match_id]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) {
            $error = "Invalid match ID.";
        } else {
            // Check if reviewer already voted on this match (per attribute)
            $attributes = ['micro', 'macro', 'clutch', 'creativity', 'aggression', 'strategy'];
            $existing_votes = [];
            
            $stmt = $db->prepare("SELECT attribute FROM Player_Attribute_Votes WHERE fsl_match_id = ? AND reviewer_id = ?");
            $stmt->execute([$fsl_match_id, $reviewer['id']]);
            $existing_votes = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Check for duplicate votes on the same attributes being submitted
            $duplicate_votes = [];
            foreach ($attributes as $attribute) {
                if (isset($_POST[$attribute]) && in_array($attribute, $existing_votes)) {
                    $duplicate_votes[] = $attribute;
                }
            }
            
            if (!empty($duplicate_votes)) {
                $voted_attributes = implode(', ', $duplicate_votes);
                $error = "You have already voted on this match for attributes: $voted_attributes. Each reviewer can only vote once per attribute per match.";
            } else {
                // Insert votes for each attribute with proper validation
                $db->beginTransaction();
                $votes_inserted = 0;
                $valid_votes_submitted = 0;
                
                try {
                    foreach ($attributes as $attribute) {
                        if (isset($_POST[$attribute])) {
                            $vote = intval($_POST[$attribute]);
                            if ($vote >= 0 && $vote <= 2) {
                                $valid_votes_submitted++;
                                
                                // Check if vote already exists before inserting
                                $check_stmt = $db->prepare("SELECT COUNT(*) FROM Player_Attribute_Votes WHERE fsl_match_id = ? AND reviewer_id = ? AND attribute = ?");
                                $check_stmt->execute([$fsl_match_id, $reviewer['id'], $attribute]);
                                $exists = $check_stmt->fetchColumn() > 0;
                                
                                if (!$exists) {
                                    // Insert new vote
                                    $stmt = $db->prepare("INSERT INTO Player_Attribute_Votes (fsl_match_id, reviewer_id, attribute, vote, player1_id, player2_id) VALUES (?, ?, ?, ?, ?, ?)");
                                    $result = $stmt->execute([$fsl_match_id, $reviewer['id'], $attribute, $vote, $player1_id, $player2_id]);
                                    
                                    if ($result) {
                                        $votes_inserted++;
                                    }
                                }
                            }
                        }
                    }
                    
                    // DEBUG: Show what's happening
                    $debug_info = "DEBUG: valid_votes_submitted=$valid_votes_submitted, votes_inserted=$votes_inserted, POST=" . json_encode($_POST) . ", existing_votes=" . json_encode($existing_votes);
                    
                    if ($valid_votes_submitted > 0) {
                        $db->commit();
                        if ($votes_inserted > 0) {
                            $message = "Votes submitted successfully! Thank you for your review.";
                        } else {
                            $message = "All submitted votes were already recorded. No new votes were added. $debug_info";
                        }
                    } else {
                        $db->rollback();
                        $error = "No valid votes were submitted. Please ensure all attributes have valid values (0, 1, or 2). $debug_info";
                    }
                } catch (PDOException $e) {
                    $db->rollback();
                    if (strpos($e->getMessage(), 'unique_reviewer_match_attribute') !== false) {
                        $error = "Duplicate vote detected. You have already voted on this match. Please refresh the page.";
                    } else {
                        $error = "Database error: " . $e->getMessage();
                    }
                }
            }
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Get matches for this reviewer with detailed vote status
$matches_per_page = 5;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $matches_per_page;

// First, get total count of matches
$count_stmt = $db->prepare("
    SELECT COUNT(*) as total
    FROM fsl_matches fm
    WHERE fm.season = 9
");
$count_stmt->execute();
$total_matches = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_matches / $matches_per_page);

// Get paginated matches for this reviewer with detailed vote status
$stmt = $db->prepare("
    SELECT 
        fm.fsl_match_id,
        fm.season,
        fm.t_code,
        fm.notes,
        fm.source,
        fm.vod,
        COALESCE(p1.Real_Name, CONCAT('Player ', fm.winner_player_id)) as player1_name,
        COALESCE(p2.Real_Name, CONCAT('Player ', fm.loser_player_id)) as player2_name,
        fm.winner_player_id,
        fm.loser_player_id,
        fm.map_win,
        fm.map_loss,
        fm.winner_race as player1_race,
        fm.loser_race as player2_race,
        GROUP_CONCAT(DISTINCT pav.attribute) as voted_attributes
    FROM fsl_matches fm
    LEFT JOIN Players p1 ON fm.winner_player_id = p1.Player_ID
    LEFT JOIN Players p2 ON fm.loser_player_id = p2.Player_ID
    LEFT JOIN Player_Attribute_Votes pav ON fm.fsl_match_id = pav.fsl_match_id AND pav.reviewer_id = ?
    WHERE fm.season = 9
    GROUP BY fm.fsl_match_id
    ORDER BY fm.fsl_match_id DESC
    LIMIT ? OFFSET ?
");
$stmt->bindValue(1, $reviewer['id'] ?? 0, PDO::PARAM_INT);
$stmt->bindValue(2, $matches_per_page, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Debug: Log the number of matches found
error_log("Score match debug: Found " . count($matches) . " matches for reviewer ID " . ($reviewer['id'] ?? 0));

// Process vote status for each match
foreach ($matches as &$match) {
    $voted_attributes = $match['voted_attributes'] ? explode(',', $match['voted_attributes']) : [];
    $total_attributes = 6; // micro, macro, clutch, creativity, aggression, strategy
    $voted_count = count($voted_attributes);
    
    if ($voted_count == 0) {
        $match['vote_status'] = 'pending';
        $match['vote_progress'] = '0/6';
    } elseif ($voted_count == $total_attributes) {
        $match['vote_status'] = 'completed';
        $match['vote_progress'] = '6/6';
    } else {
        $match['vote_status'] = 'partial';
        $match['vote_progress'] = "$voted_count/6";
    }
    
    $match['voted_attributes'] = $voted_attributes;
}

// Set page title
$pageTitle = "FSL Spider Chart Match Scoring";

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <?php if ($error): ?>
        <div class="alert alert-error">
            <h2>Access Error</h2>
            <p><?= htmlspecialchars($error) ?></p>
            <p>Please contact the administrator for a valid scoring link.</p>
        </div>
    <?php elseif ($reviewer): ?>
        <div class="reviewer-header">
            <div class="header-left">
                <h1>FSL Spider Chart Match Scoring</h1>
                <div class="reviewer-info">
                    <p>Welcome, <strong><?= htmlspecialchars($reviewer['name']) ?></strong></p>
                    <p>Your vote weight: <strong><?= $reviewer['weight'] ?>x</strong></p>
                    <p>Reviewer ID: <strong><?= $reviewer['id'] ?></strong></p>
                    <p>Total matches available: <strong><?= $total_matches ?></strong></p>
                </div>
            </div>
            <div class="header-right">
                <div class="mode-toggle">
                    <a href="score_match.php?token=<?= htmlspecialchars($_GET['token']) ?>" class="btn btn-primary">Single Match Mode</a>
                </div>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="scoring-instructions">
            <h2>Scoring Instructions</h2>
            <p>For each match, please rate the 6 attributes by comparing the two players:</p>
            <ul>
                <li><strong>0</strong> = Tie or unsure</li>
                <li><strong>1</strong> = Player 1 (winner) performed better</li>
                <li><strong>2</strong> = Player 2 (loser) performed better</li>
            </ul>
            <p><strong>Attributes:</strong></p>
            <ul>
                <li><strong>Micro</strong> - Fine unit control in combat</li>
                <li><strong>Macro</strong> - Resource gathering and production efficiency</li>
                <li><strong>Clutch</strong> - Performance under pressure</li>
                <li><strong>Creativity</strong> - Off-meta builds and unexpected strategies</li>
                <li><strong>Aggression</strong> - Proactive attacking style</li>
                <li><strong>Strategy</strong> - Build order planning and adaptation</li>
            </ul>
        </div>

        <!-- Match Selector -->
        <div class="match-selector">
            <div class="selector-header">
                <h3>Match Navigation</h3>
                <div class="selector-controls">
                    <select id="quickJump" class="quick-jump-select">
                        <option value="">Quick Jump to Match...</option>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <option value="page-<?= $i ?>">
                                Page <?= $i ?> (<?= $matches_per_page * ($i - 1) + 1 ?> - <?= min($matches_per_page * $i, $total_matches) ?>)
                            </option>
                        <?php endfor; ?>
                    </select>
                    <button class="btn btn-sm btn-outline" id="toggleFilters">Show/Hide Filters</button>
                </div>
            </div>
            <div class="match-filters" id="matchFilters" style="display: none;">
                <button class="filter-btn active" data-filter="all">All (<?= count($matches) ?>)</button>
                <button class="filter-btn" data-filter="pending">Pending (<?= array_count_values(array_column($matches, 'vote_status'))['pending'] ?? 0 ?>)</button>
                <button class="filter-btn" data-filter="partial">Partial (<?= array_count_values(array_column($matches, 'vote_status'))['partial'] ?? 0 ?>)</button>
                <button class="filter-btn" data-filter="completed">Completed (<?= array_count_values(array_column($matches, 'vote_status'))['completed'] ?? 0 ?>)</button>
            </div>
        </div>

        <div class="matches-list">
            <h2>Matches to Score (Page <?= $current_page ?> of <?= $total_pages ?>)</h2>
            
            <?php if (empty($matches)): ?>
                <div class="alert alert-warning">
                    <p>No matches found for scoring. This could be because:</p>
                    <ul>
                        <li>No matches exist in season 9</li>
                        <li>All matches have missing player data</li>
                        <li>There's an issue with the database connection</li>
                    </ul>
                    <p>Please contact the administrator.</p>
                </div>
            <?php else: ?>
                <?php foreach ($matches as $match): ?>
                    <div class="match-card <?= $match['vote_status'] ?>" data-match-id="<?= $match['fsl_match_id'] ?>">
                        <div class="match-header">
                            <a href="view_match.php?id=<?= $match['fsl_match_id'] ?>" target="_blank" class="match-link">
                                <h3>Match #<?= $match['fsl_match_id'] ?></h3>
                            </a>
                            <span class="match-status <?= $match['vote_status'] ?>">
                                <?= ucfirst($match['vote_status']) ?> (<?= $match['vote_progress'] ?>)
                            </span>
                        </div>
                        
                        <div class="match-details">
                            <div class="players">
                                <div class="player winner">
                                    <div class="player-intro-wrapper">
                                        <div class="player-intro-container" id="player1-intro-<?= $match['fsl_match_id'] ?>">
                                            <?php 
                                            $introPlayerName = $match['player1_name'];
                                            $uniqueId = 'player1_' . $match['fsl_match_id'];
                                            include 'view_player_intro.php';
                                            ?>
                                        </div>
                                        <div class="player-info">
                                            <a href="view_player.php?name=<?= urlencode($match['player1_name']) ?>" target="_blank" class="player-link">
                                                <strong><?= htmlspecialchars($match['player1_name']) ?></strong>
                                                <?php if ($match['player1_race']): ?>
                                                    <span class="race-badge"><?= htmlspecialchars($match['player1_race']) ?></span>
                                                <?php endif; ?>
                                            </a>
                                            <span class="score"><?= $match['map_win'] ?>-<?= $match['map_loss'] ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="vs">vs</div>
                                <div class="player loser">
                                    <div class="player-intro-wrapper">
                                        <div class="player-intro-container" id="player2-intro-<?= $match['fsl_match_id'] ?>">
                                            <?php 
                                            $introPlayerName = $match['player2_name'];
                                            $uniqueId = 'player2_' . $match['fsl_match_id'];
                                            include 'view_player_intro.php';
                                            ?>
                                        </div>
                                        <div class="player-info">
                                            <a href="view_player.php?name=<?= urlencode($match['player2_name']) ?>" target="_blank" class="player-link">
                                                <strong><?= htmlspecialchars($match['player2_name']) ?></strong>
                                                <?php if ($match['player2_race']): ?>
                                                    <span class="race-badge"><?= htmlspecialchars($match['player2_race']) ?></span>
                                                <?php endif; ?>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="match-info">
                                <p><strong>Season:</strong> <?= $match['season'] ?> | <strong>Code:</strong> <?= $match['t_code'] ?></p>
                                <?php if ($match['notes']): ?>
                                    <p><strong>Notes:</strong> <?= htmlspecialchars($match['notes']) ?></p>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($match['vod']): ?>
                                <div class="vod-link">
                                    <a href="<?= htmlspecialchars($match['vod']) ?>" target="_blank" class="btn btn-primary">
                                        Watch VOD
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($match['vote_status'] === 'completed'): ?>
                            <div class="voted-message">
                                <p>✅ You have already voted on this match.</p>
                            </div>
                        <?php else: ?>
                            <div class="scoring-form">
                                <form method="post" class="vote-form">
                                    <input type="hidden" name="fsl_match_id" value="<?= $match['fsl_match_id'] ?>">
                                    <input type="hidden" name="player1_id" value="<?= $match['winner_player_id'] ?>">
                                    <input type="hidden" name="player2_id" value="<?= $match['loser_player_id'] ?>">
                                    
                                    <div class="attributes-grid">
                                        <div class="attribute <?= in_array('micro', $match['voted_attributes']) ? 'voted' : '' ?>">
                                            <label>Micro: <?= in_array('micro', $match['voted_attributes']) ? '✓ Voted' : '' ?></label>
                                            <?php if (!in_array('micro', $match['voted_attributes'])): ?>
                                            <div class="radio-group">
                                                <label class="radio-option">
                                                    <input type="radio" name="micro" value="0" required>
                                                    <span class="radio-label">Tie/Unsure</span>
                                                </label>
                                                <label class="radio-option">
                                                    <input type="radio" name="micro" value="1">
                                                    <span class="radio-label"><?= htmlspecialchars($match['player1_name']) ?> better</span>
                                                </label>
                                                <label class="radio-option">
                                                    <input type="radio" name="micro" value="2">
                                                    <span class="radio-label"><?= htmlspecialchars($match['player2_name']) ?> better</span>
                                                </label>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="attribute <?= in_array('macro', $match['voted_attributes']) ? 'voted' : '' ?>">
                                            <label>Macro: <?= in_array('macro', $match['voted_attributes']) ? '✓ Voted' : '' ?></label>
                                            <?php if (!in_array('macro', $match['voted_attributes'])): ?>
                                            <div class="radio-group">
                                                <label class="radio-option">
                                                    <input type="radio" name="macro" value="0" required>
                                                    <span class="radio-label">Tie/Unsure</span>
                                                </label>
                                                <label class="radio-option">
                                                    <input type="radio" name="macro" value="1">
                                                    <span class="radio-label"><?= htmlspecialchars($match['player1_name']) ?> better</span>
                                                </label>
                                                <label class="radio-option">
                                                    <input type="radio" name="macro" value="2">
                                                    <span class="radio-label"><?= htmlspecialchars($match['player2_name']) ?> better</span>
                                                </label>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="attribute <?= in_array('clutch', $match['voted_attributes']) ? 'voted' : '' ?>">
                                            <label>Clutch: <?= in_array('clutch', $match['voted_attributes']) ? '✓ Voted' : '' ?></label>
                                            <?php if (!in_array('clutch', $match['voted_attributes'])): ?>
                                            <div class="radio-group">
                                                <label class="radio-option">
                                                    <input type="radio" name="clutch" value="0" required>
                                                    <span class="radio-label">Tie/Unsure</span>
                                                </label>
                                                <label class="radio-option">
                                                    <input type="radio" name="clutch" value="1">
                                                    <span class="radio-label"><?= htmlspecialchars($match['player1_name']) ?> better</span>
                                                </label>
                                                <label class="radio-option">
                                                    <input type="radio" name="clutch" value="2">
                                                    <span class="radio-label"><?= htmlspecialchars($match['player2_name']) ?> better</span>
                                                </label>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="attribute <?= in_array('creativity', $match['voted_attributes']) ? 'voted' : '' ?>">
                                            <label>Creativity: <?= in_array('creativity', $match['voted_attributes']) ? '✓ Voted' : '' ?></label>
                                            <?php if (!in_array('creativity', $match['voted_attributes'])): ?>
                                            <div class="radio-group">
                                                <label class="radio-option">
                                                    <input type="radio" name="creativity" value="0" required>
                                                    <span class="radio-label">Tie/Unsure</span>
                                                </label>
                                                <label class="radio-option">
                                                    <input type="radio" name="creativity" value="1">
                                                    <span class="radio-label"><?= htmlspecialchars($match['player1_name']) ?> better</span>
                                                </label>
                                                <label class="radio-option">
                                                    <input type="radio" name="creativity" value="2">
                                                    <span class="radio-label"><?= htmlspecialchars($match['player2_name']) ?> better</span>
                                                </label>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="attribute <?= in_array('aggression', $match['voted_attributes']) ? 'voted' : '' ?>">
                                            <label>Aggression: <?= in_array('aggression', $match['voted_attributes']) ? '✓ Voted' : '' ?></label>
                                            <?php if (!in_array('aggression', $match['voted_attributes'])): ?>
                                            <div class="radio-group">
                                                <label class="radio-option">
                                                    <input type="radio" name="aggression" value="0" required>
                                                    <span class="radio-label">Tie/Unsure</span>
                                                </label>
                                                <label class="radio-option">
                                                    <input type="radio" name="aggression" value="1">
                                                    <span class="radio-label"><?= htmlspecialchars($match['player1_name']) ?> better</span>
                                                </label>
                                                <label class="radio-option">
                                                    <input type="radio" name="aggression" value="2">
                                                    <span class="radio-label"><?= htmlspecialchars($match['player2_name']) ?> better</span>
                                                </label>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="attribute <?= in_array('strategy', $match['voted_attributes']) ? 'voted' : '' ?>">
                                            <label>Strategy: <?= in_array('strategy', $match['voted_attributes']) ? '✓ Voted' : '' ?></label>
                                            <?php if (!in_array('strategy', $match['voted_attributes'])): ?>
                                            <div class="radio-group">
                                                <label class="radio-option">
                                                    <input type="radio" name="strategy" value="0" required>
                                                    <span class="radio-label">Tie/Unsure</span>
                                                </label>
                                                <label class="radio-option">
                                                    <input type="radio" name="strategy" value="1">
                                                    <span class="radio-label"><?= htmlspecialchars($match['player1_name']) ?> better</span>
                                                </label>
                                                <label class="radio-option">
                                                    <input type="radio" name="strategy" value="2">
                                                    <span class="radio-label"><?= htmlspecialchars($match['player2_name']) ?> better</span>
                                                </label>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <?php if ($match['vote_status'] === 'partial'): ?>
                                        <button type="submit" class="btn btn-warning">Complete Missing Votes</button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-success">Submit Votes</button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <!-- Pagination Controls -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination-container">
                <div class="pagination-info">
                    <p>Showing matches <?= $offset + 1 ?> - <?= min($offset + $matches_per_page, $total_matches) ?> of <?= $total_matches ?> total</p>
                </div>
                <div class="pagination-controls">
                    <?php if ($current_page > 1): ?>
                        <a href="?token=<?= htmlspecialchars($_GET['token']) ?>&page=1" class="btn btn-sm btn-outline">First</a>
                        <a href="?token=<?= htmlspecialchars($_GET['token']) ?>&page=<?= $current_page - 1 ?>" class="btn btn-sm btn-outline">Previous</a>
                    <?php endif; ?>
                    
                    <div class="page-numbers">
                        <?php
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);
                        
                        if ($start_page > 1): ?>
                            <a href="?token=<?= htmlspecialchars($_GET['token']) ?>&page=1" class="btn btn-sm btn-outline">1</a>
                            <?php if ($start_page > 2): ?>
                                <span class="pagination-ellipsis">...</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <?php if ($i == $current_page): ?>
                                <span class="btn btn-sm btn-primary"><?= $i ?></span>
                            <?php else: ?>
                                <a href="?token=<?= htmlspecialchars($_GET['token']) ?>&page=<?= $i ?>" class="btn btn-sm btn-outline"><?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <span class="pagination-ellipsis">...</span>
                            <?php endif; ?>
                            <a href="?token=<?= htmlspecialchars($_GET['token']) ?>&page=<?= $total_pages ?>" class="btn btn-sm btn-outline"><?= $total_pages ?></a>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($current_page < $total_pages): ?>
                        <a href="?token=<?= htmlspecialchars($_GET['token']) ?>&page=<?= $current_page + 1 ?>" class="btn btn-sm btn-outline">Next</a>
                        <a href="?token=<?= htmlspecialchars($_GET['token']) ?>&page=<?= $total_pages ?>" class="btn btn-sm btn-outline">Last</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<style>
    body {
        font-family: 'Inter', sans-serif;
        background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
        color: #e0e0e0;
        margin: 0;
        padding: 0;
        line-height: 1.4;
    }
    
    .container {
        max-width: 1000px;
        margin: 20px auto;
        padding: 20px;
    }
    
    .reviewer-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 30px;
        gap: 20px;
    }
    
    .header-left {
        flex: 1;
        text-align: left;
    }
    
    .header-right {
        flex-shrink: 0;
    }
    
    .mode-toggle {
        text-align: right;
    }
    
    .reviewer-header h1 {
        color: #00d4ff;
        text-shadow: 0 0 15px #00d4ff;
        font-size: 2.4em;
        margin-bottom: 10px;
    }
    
    .reviewer-info {
        background: rgba(0, 212, 255, 0.1);
        border: 1px solid rgba(0, 212, 255, 0.3);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
    }
    
    .scoring-instructions {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        padding: 25px;
        margin-bottom: 30px;
    }
    
    .scoring-instructions h2 {
        color: #00d4ff;
        margin-bottom: 15px;
    }
    
    .matches-list h2 {
        color: #00d4ff;
        margin-bottom: 20px;
    }
    
    .match-card {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
        border: 1px solid rgba(255, 255, 255, 0.2);
    }
    
    .match-card.completed {
        border-color: #28a745;
        background: rgba(40, 167, 69, 0.1);
    }
    
    .match-card.partial {
        border-color: #ffc107;
        background: rgba(255, 193, 7, 0.1);
    }
    
    .btn-warning {
        background-color: #ffc107;
        border-color: #ffc107;
        color: #212529;
    }
    
    .btn-warning:hover {
        background-color: #e0a800;
        border-color: #d39e00;
        color: #212529;
    }
    
    .btn {
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: bold;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-block;
        text-align: center;
    }
    
    .btn-primary {
        background: #007bff;
        color: white;
    }
    
    .btn-primary:hover {
        background: #0056b3;
    }
    
    .match-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
    }
    
    .match-header h3 {
        color: #00d4ff;
        margin: 0;
    }
    
    .match-status {
        padding: 5px 10px;
        border-radius: 15px;
        font-size: 0.9em;
        font-weight: bold;
    }
    
    .match-status.pending {
        background: #6c757d;
        color: white;
    }
    
    .match-status.partial {
        background: #ffc107;
        color: black;
    }
    
    .match-status.completed {
        background: #28a745;
        color: white;
    }
    
    .players {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 20px;
        margin-bottom: 15px;
    }
    
    .player {
        text-align: center;
    }
    
    .player.winner {
        color: #28a745;
    }
    
    .player.loser {
        color: #dc3545;
    }
    
    .vs {
        color: #6c757d;
        font-weight: bold;
    }
    
    .score {
        display: block;
        font-size: 0.9em;
        margin-top: 5px;
    }
    
    .match-info {
        margin-bottom: 15px;
    }
    
    .vod-link {
        text-align: center;
        margin-bottom: 15px;
    }
    
    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 5px;
        text-decoration: none;
        display: inline-block;
        cursor: pointer;
        font-weight: bold;
        transition: all 0.3s ease;
    }
    
    .btn-primary {
        background: #007bff;
        color: white;
    }
    
    .btn-primary:hover {
        background: #0056b3;
    }
    
    .btn-success {
        background: #28a745;
        color: white;
    }
    
    .btn-success:hover {
        background: #1e7e34;
    }
    
    .radio-group {
        display: flex;
        flex-direction: column;
        gap: 12px;
        margin-top: 15px;
    }
    
    .radio-option {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 15px 18px;
        border-radius: 8px;
        background: rgba(0, 0, 0, 0.2);
        border: 2px solid rgba(255, 255, 255, 0.1);
        cursor: pointer;
        transition: all 0.3s ease;
        min-height: 50px;
    }
    
    .radio-option:hover {
        background: rgba(57, 255, 20, 0.1);
        border-color: rgba(57, 255, 20, 0.3);
        transform: translateY(-1px);
    }
    
    .radio-option input[type="radio"] {
        margin: 0;
        cursor: pointer;
        width: 20px;
        height: 20px;
        accent-color: #39ff14;
    }
    
    .radio-option input[type="radio"]:checked + .radio-label {
        color: #39ff14;
        font-weight: bold;
    }
    
    .radio-option input[type="radio"]:checked {
        transform: scale(1.1);
    }
    
    .radio-option:has(input[type="radio"]:checked) {
        background: rgba(57, 255, 20, 0.15);
        border-color: #39ff14;
        box-shadow: 0 0 10px rgba(57, 255, 20, 0.4);
    }
    
    .radio-label {
        cursor: pointer;
        flex: 1;
        font-size: 16px;
        line-height: 1.4;
    }
    
    .player-link {
        text-decoration: none;
        color: inherit;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s ease;
    }
    
    .player-link:hover {
        color: #39ff14;
        text-decoration: underline;
    }
    
    .race-badge {
        background: rgba(57, 255, 20, 0.2);
        color: #39ff14;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: bold;
        border: 1px solid #39ff14;
    }
    
    .match-link {
        text-decoration: none;
        color: inherit;
        transition: all 0.2s ease;
    }
    
    .match-link:hover {
        color: #39ff14;
    }
    
    .match-link h3 {
        margin: 0;
        transition: all 0.2s ease;
    }
    
    .match-link:hover h3 {
        color: #39ff14;
    }
    
    .player-intro-wrapper {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 10px;
        width: 200px;
    }
    
    .player-intro-container {
        width: 180px;
        height: 120px;
        position: relative;
        overflow: hidden;
        border-radius: 8px;
        border: 2px solid rgba(255, 255, 255, 0.1);
        background: rgba(0, 0, 0, 0.3);
    }
    
    .player-intro-container canvas {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .player-info {
        text-align: center;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 5px;
    }
    
    .players {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 30px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }
    
    .player {
        text-align: center;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    
    .attributes-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }
    
    .attribute {
        background: rgba(255, 255, 255, 0.05);
        padding: 15px;
        border-radius: 8px;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .attribute.voted {
        background: rgba(40, 167, 69, 0.1);
        border-color: #28a745;
    }
    
    .attribute label {
        display: block;
        margin-bottom: 8px;
        font-weight: bold;
        color: #00d4ff;
    }
    
    .attribute select {
        width: 100%;
        padding: 8px;
        border: 1px solid rgba(255, 255, 255, 0.3);
        border-radius: 4px;
        background: rgba(255, 255, 255, 0.1);
        color: #e0e0e0;
    }
    
    .attribute select:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    
    .voted-message {
        text-align: center;
        padding: 15px;
        background: rgba(40, 167, 69, 0.1);
        border: 1px solid #28a745;
        border-radius: 8px;
        color: #28a745;
    }
    
    .alert {
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    
    .alert-error {
        background: rgba(220, 53, 69, 0.1);
        border: 1px solid #dc3545;
        color: #dc3545;
    }
    
    .alert-success {
        background: rgba(40, 167, 69, 0.1);
        border: 1px solid #28a745;
        color: #28a745;
    }
    
    .alert-warning {
        background: rgba(255, 193, 7, 0.1);
        border: 1px solid #ffc107;
        color: #ffc107;
    }
    
    /* New styles for match selector */
    .match-selector {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 30px;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .selector-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
    }

    .selector-header h3 {
        color: #00d4ff;
        margin: 0;
    }

    .selector-controls {
        display: flex;
        gap: 15px;
    }

    .quick-jump-select {
        width: 250px;
        padding: 10px;
        border: 1px solid rgba(255, 255, 255, 0.3);
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.1);
        color: #e0e0e0;
    }

    .quick-jump-select option {
        background: #1a1a2e;
        color: #e0e0e0;
    }

    .quick-jump-select:focus {
        outline: none;
        border-color: #00d4ff;
    }

    .btn-sm {
        padding: 8px 15px;
        font-size: 14px;
    }

    .btn-outline {
        background: none;
        border: 1px solid #00d4ff;
        color: #00d4ff;
    }

    .btn-outline:hover {
        background: rgba(0, 212, 255, 0.1);
        border-color: #00d4ff;
        color: #fff;
    }

    .match-filters {
        display: flex;
        gap: 10px;
        margin-top: 15px;
        flex-wrap: wrap;
    }

    .filter-btn {
        padding: 8px 15px;
        border: 1px solid #00d4ff;
        border-radius: 8px;
        background: none;
        color: #00d4ff;
        font-weight: bold;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .filter-btn:hover {
        background: rgba(0, 212, 255, 0.1);
        border-color: #00d4ff;
        color: #fff;
    }

    .filter-btn.active {
        background: #00d4ff;
        color: #1a1a2e;
        border-color: #00d4ff;
    }

    .filter-btn.active:hover {
        background: #00d4ff;
        color: #1a1a2e;
        border-color: #00d4ff;
    }
    
    /* New styles for pagination */
    .pagination-container {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
    }

    .pagination-info {
        color: #00d4ff;
        font-size: 0.9em;
    }

    .pagination-controls {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .page-numbers {
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .pagination-ellipsis {
        color: #00d4ff;
        font-size: 1.2em;
    }

    .btn-sm.btn-primary {
        background: #00d4ff;
        color: #1a1a2e;
        border: 1px solid #00d4ff;
    }

    .btn-sm.btn-primary:hover {
        background: #00b7d9;
        border-color: #00b7d9;
    }

    @media (max-width: 768px) {
        .container {
            padding: 10px;
        }
        
        .reviewer-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
        
        .header-left h1 {
            font-size: 24px;
        }
        
        .attributes-grid {
            grid-template-columns: 1fr;
            gap: 15px;
        }
        
        .radio-option {
            padding: 12px 15px;
            min-height: 45px;
        }
        
        .radio-option input[type="radio"] {
            width: 18px;
            height: 18px;
        }
        
        .radio-label {
            font-size: 15px;
        }
        
        .players {
            flex-direction: column;
            gap: 20px;
            text-align: center;
        }
        
        .player-intro-wrapper {
            width: 150px;
        }
        
        .player-intro-container {
            width: 140px;
            height: 90px;
        }
        
        .match-header {
            flex-direction: column;
            gap: 10px;
            text-align: center;
        }
        
        .btn {
            min-width: 100px;
            padding: 12px 20px;
            font-size: 14px;
        }

        .match-selector {
            padding: 15px;
        }

        .selector-header {
            flex-direction: column;
            gap: 10px;
        }

        .selector-controls {
            flex-direction: column;
            align-items: center;
        }

        .quick-jump-select {
            width: 100%;
            max-width: 300px;
        }

        .match-filters {
            flex-direction: column;
            align-items: center;
        }

        .filter-btn {
            width: 100%;
            text-align: center;
        }

        .pagination-container {
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }

        .pagination-info {
            text-align: center;
        }

        .pagination-controls {
            flex-direction: column;
            align-items: center;
        }

        .page-numbers {
            flex-wrap: wrap;
        }

        .btn-sm.btn-primary {
            width: 100%;
            max-width: 150px;
        }
    }
    
    @media (max-width: 480px) {
        .container {
            padding: 5px;
        }
        
        .header-left h1 {
            font-size: 20px;
        }
        
        .radio-option {
            padding: 10px 12px;
            min-height: 40px;
        }
        
        .radio-option input[type="radio"] {
            width: 16px;
            height: 16px;
        }
        
        .radio-label {
            font-size: 14px;
        }
        
        .btn {
            min-width: 80px;
            padding: 10px 16px;
            font-size: 13px;
        }
    }
</style>

<script>
    // Quick jump functionality
    document.getElementById('quickJump').addEventListener('change', function() {
        const selectedMatch = this.value;
        if (selectedMatch !== '') {
            const pageNumber = selectedMatch.replace('page-', '');
            const currentUrl = new URL(window.location);
            currentUrl.searchParams.set('page', pageNumber);
            window.location.href = currentUrl.toString();
        }
    });

// Toggle filters visibility
document.getElementById('toggleFilters').addEventListener('click', function() {
    const matchFilters = document.getElementById('matchFilters');
    matchFilters.style.display = matchFilters.style.display === 'none' ? 'flex' : 'none';
    this.textContent = matchFilters.style.display === 'none' ? 'Show Filters' : 'Hide Filters';
});

// Filter matches by status
document.querySelectorAll('.filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const filter = this.getAttribute('data-filter');
        
        // Update active button
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        
        // Filter match cards
        document.querySelectorAll('.match-card').forEach(card => {
            const status = card.classList.contains('completed') ? 'completed' : 
                          card.classList.contains('partial') ? 'partial' : 'pending';
            
            if (filter === 'all' || status === filter) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?> 