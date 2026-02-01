<?php
/**
 * FSL Spider Chart Single-Match Voting Interface
 * Streamlined voting interface that shows one match at a time
 */

require_once 'config.php';
require_once 'includes/db.php';
require_once 'includes/season_utils.php';
require_once 'includes/reviewer_functions.php';

// Set timezone from config
date_default_timezone_set($config['timezone']);

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
                    
                    if ($valid_votes_submitted > 0) {
                        $db->commit();
                        if ($votes_inserted > 0) {
                            $message = "Votes submitted successfully! Moving to next match...";
                        } else {
                            $message = "All submitted votes were already recorded. Moving to next match...";
                        }
                    } else {
                        $db->rollback();
                        $error = "No valid votes were submitted. Please ensure all attributes have valid values (0, 1, or 2).";
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

// Get all matches for this reviewer (current season)
$currentSeason = getCurrentSeason($db);
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
    WHERE fm.season = ?
    GROUP BY fm.fsl_match_id
    ORDER BY fm.fsl_match_id DESC
    LIMIT 200
");
$stmt->execute([$reviewer['id'] ?? 0, $currentSeason]);
$all_matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Process vote status for each match
foreach ($all_matches as &$match) {
    $voted_attributes = $match['voted_attributes'] ? explode(',', $match['voted_attributes']) : [];
    $total_attributes = 6;
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

// Get current match index
$current_match_index = $_GET['match'] ?? 0;
$current_match_index = intval($current_match_index);

// Ensure index is within bounds
if ($current_match_index >= count($all_matches)) {
    $current_match_index = 0;
}

$current_match = $all_matches[$current_match_index] ?? null;

// Calculate progress
$completed_matches = 0;
$total_matches = count($all_matches);
foreach ($all_matches as $match) {
    if ($match['vote_status'] === 'completed') {
        $completed_matches++;
    }
}

// Set page title
$pageTitle = "FSL Spider Chart - Single Match Voting";

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
    <?php elseif ($reviewer && $current_match): ?>
        <!-- Header with progress and mode toggle -->
        <div class="voting-header">
            <div class="header-left">
                <h1>FSL Spider Chart Voting</h1>
                <div class="reviewer-info">
                    <p>Welcome, <strong><?= htmlspecialchars($reviewer['name']) ?></strong> | Weight: <strong><?= $reviewer['weight'] ?>x</strong></p>
                </div>
            </div>
            <div class="header-right">
                <div class="mode-toggle">
                    <a href="score_match_all.php?token=<?= htmlspecialchars($_GET['token']) ?>" class="btn btn-secondary">View All Matches</a>
                </div>
            </div>
        </div>

        <!-- Help Notice -->
        <div class="help-notice">
            <p>📖 <strong>New to voting?</strong> Need help understanding how this works? Check out our comprehensive <a href="voting_guide.php" target="_blank" class="help-link">Voting Guide & Instructions</a> for detailed explanations of attributes, scoring, and best practices.</p>
        </div>

        <!-- Progress Bar -->
        <div class="progress-section">
            <div class="progress-info">
                <span class="progress-text">Match <?= $current_match_index + 1 ?> of <?= $total_matches ?></span>
                <span class="overall-progress"><?= $completed_matches ?> of <?= $total_matches ?> completed</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?= ($completed_matches / $total_matches) * 100 ?>%"></div>
            </div>
        </div>

        <!-- Match Selector -->
        <div class="match-selector">
            <div class="selector-header">
                <h3>Match Navigation</h3>
                <div class="selector-controls">
                    <select id="quickJump" class="quick-jump-select">
                        <option value="">Quick Jump to Match...</option>
                        <?php foreach ($all_matches as $index => $match): ?>
                            <option value="<?= $index ?>" <?= $index === $current_match_index ? 'selected' : '' ?>>
                                Match <?= $index + 1 ?> (#<?= $match['fsl_match_id'] ?>) - <?= ucfirst($match['vote_status']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-outline" id="toggleSelector">Show/Hide All Matches</button>
                </div>
            </div>
            <div class="match-grid" id="matchGrid" style="display: none;">
                <div class="match-filters">
                    <button class="filter-btn active" data-filter="all">All (<?= $total_matches ?>)</button>
                    <button class="filter-btn" data-filter="pending">Pending (<?= array_count_values(array_column($all_matches, 'vote_status'))['pending'] ?? 0 ?>)</button>
                    <button class="filter-btn" data-filter="partial">Partial (<?= array_count_values(array_column($all_matches, 'vote_status'))['partial'] ?? 0 ?>)</button>
                    <button class="filter-btn" data-filter="completed">Completed (<?= array_count_values(array_column($all_matches, 'vote_status'))['completed'] ?? 0 ?>)</button>
                </div>
                <div class="matches-container">
                    <?php foreach ($all_matches as $index => $match): ?>
                        <a href="?token=<?= htmlspecialchars($_GET['token']) ?>&match=<?= $index ?>" 
                           class="match-item <?= $match['vote_status'] ?> <?= $index === $current_match_index ? 'current' : '' ?>"
                           data-status="<?= $match['vote_status'] ?>"
                           title="Match #<?= $match['fsl_match_id'] ?> - <?= ucfirst($match['vote_status']) ?> (<?= $match['vote_progress'] ?>)">
                            <div class="match-number"><?= $index + 1 ?></div>
                            <div class="match-id">#<?= $match['fsl_match_id'] ?></div>
                            <div class="match-status-indicator">
                                <?php if ($match['vote_status'] === 'completed'): ?>
                                    <span class="status-icon completed">✓</span>
                                <?php elseif ($match['vote_status'] === 'partial'): ?>
                                    <span class="status-icon partial">○</span>
                                <?php else: ?>
                                    <span class="status-icon pending">○</span>
                                <?php endif; ?>
                            </div>
                            <div class="match-players">
                                <div class="player1"><?= htmlspecialchars(substr($match['player1_name'], 0, 12)) ?></div>
                                <div class="vs">vs</div>
                                <div class="player2"><?= htmlspecialchars(substr($match['player2_name'], 0, 12)) ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- Current Match Card -->
        <div class="match-card <?= $current_match['vote_status'] ?>">
            <div class="match-header">
                <a href="view_match.php?id=<?= $current_match['fsl_match_id'] ?>" target="_blank" class="match-link">
                    <h2>Match #<?= $current_match['fsl_match_id'] ?></h2>
                </a>
                <span class="match-status <?= $current_match['vote_status'] ?>">
                    <?= ucfirst($current_match['vote_status']) ?> (<?= $current_match['vote_progress'] ?>)
                </span>
            </div>
            
            <div class="match-details">
                <div class="players">
                    <div class="player winner">
                        <div class="player-intro-wrapper">
                            <div class="player-intro-container" id="player1-intro">
                                <?php 
                                $introPlayerName = $current_match['player1_name'];
                                $uniqueId = 'player1_' . $current_match['fsl_match_id'];
                                include 'view_player_intro.php';
                                ?>
                            </div>
                            <div class="player-info">
                                <a href="view_player.php?name=<?= urlencode($current_match['player1_name']) ?>" target="_blank" class="player-link">
                                    <strong><?= htmlspecialchars($current_match['player1_name']) ?></strong>
                                    <?php if ($current_match['player1_race']): ?>
                                        <span class="race-badge"><?= htmlspecialchars($current_match['player1_race']) ?></span>
                                    <?php endif; ?>
                                </a>
                                <span class="score"><?= $current_match['map_win'] ?>-<?= $current_match['map_loss'] ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="vs">vs</div>
                    <div class="player loser">
                        <div class="player-intro-wrapper">
                            <div class="player-intro-container" id="player2-intro">
                                <?php 
                                $introPlayerName = $current_match['player2_name'];
                                $uniqueId = 'player2_' . $current_match['fsl_match_id'];
                                include 'view_player_intro.php';
                                ?>
                            </div>
                            <div class="player-info">
                                <a href="view_player.php?name=<?= urlencode($current_match['player2_name']) ?>" target="_blank" class="player-link">
                                    <strong><?= htmlspecialchars($current_match['player2_name']) ?></strong>
                                    <?php if ($current_match['player2_race']): ?>
                                        <span class="race-badge"><?= htmlspecialchars($current_match['player2_race']) ?></span>
                                    <?php endif; ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="match-info">
                    <p><strong>Season:</strong> <?= $current_match['season'] ?> | <strong>Code:</strong> <?= $current_match['t_code'] ?></p>
                    <?php if ($current_match['notes']): ?>
                        <p><strong>Notes:</strong> <?= htmlspecialchars($current_match['notes']) ?></p>
                    <?php endif; ?>
                </div>
                
                <?php if ($current_match['vod']): ?>
                    <div class="vod-link">
                        <a href="<?= htmlspecialchars($current_match['vod']) ?>" target="_blank" class="btn btn-primary">
                            Watch VOD
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($current_match['vote_status'] === 'completed'): ?>
                <div class="voted-message">
                    <p>✅ You have already voted on this match.</p>
                    <div class="navigation-buttons">
                        <?php if ($current_match_index > 0): ?>
                            <a href="?token=<?= htmlspecialchars($_GET['token']) ?>&match=<?= $current_match_index - 1 ?>" class="btn btn-secondary">← Previous Match</a>
                        <?php endif; ?>
                        <?php if ($current_match_index < $total_matches - 1): ?>
                            <a href="?token=<?= htmlspecialchars($_GET['token']) ?>&match=<?= $current_match_index + 1 ?>" class="btn btn-primary">Next Match →</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="scoring-form">
                    <form method="post" class="vote-form">
                        <input type="hidden" name="fsl_match_id" value="<?= $current_match['fsl_match_id'] ?>">
                        <input type="hidden" name="player1_id" value="<?= $current_match['winner_player_id'] ?>">
                        <input type="hidden" name="player2_id" value="<?= $current_match['loser_player_id'] ?>">
                        
                        <div class="attributes-grid">
                            <div class="attribute <?= in_array('micro', $current_match['voted_attributes']) ? 'voted' : '' ?>">
                                <label>Micro: <?= in_array('micro', $current_match['voted_attributes']) ? '✓ Voted' : '' ?></label>
                                <?php if (!in_array('micro', $current_match['voted_attributes'])): ?>
                                <div class="radio-group">
                                    <label class="radio-option">
                                        <input type="radio" name="micro" value="0" required>
                                        <span class="radio-label">Tie/Unsure</span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="micro" value="1">
                                        <span class="radio-label"><?= htmlspecialchars($current_match['player1_name']) ?> better</span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="micro" value="2">
                                        <span class="radio-label"><?= htmlspecialchars($current_match['player2_name']) ?> better</span>
                                    </label>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="attribute <?= in_array('macro', $current_match['voted_attributes']) ? 'voted' : '' ?>">
                                <label>Macro: <?= in_array('macro', $current_match['voted_attributes']) ? '✓ Voted' : '' ?></label>
                                <?php if (!in_array('macro', $current_match['voted_attributes'])): ?>
                                <div class="radio-group">
                                    <label class="radio-option">
                                        <input type="radio" name="macro" value="0" required>
                                        <span class="radio-label">Tie/Unsure</span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="macro" value="1">
                                        <span class="radio-label"><?= htmlspecialchars($current_match['player1_name']) ?> better</span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="macro" value="2">
                                        <span class="radio-label"><?= htmlspecialchars($current_match['player2_name']) ?> better</span>
                                    </label>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="attribute <?= in_array('clutch', $current_match['voted_attributes']) ? 'voted' : '' ?>">
                                <label>Clutch: <?= in_array('clutch', $current_match['voted_attributes']) ? '✓ Voted' : '' ?></label>
                                <?php if (!in_array('clutch', $current_match['voted_attributes'])): ?>
                                <div class="radio-group">
                                    <label class="radio-option">
                                        <input type="radio" name="clutch" value="0" required>
                                        <span class="radio-label">Tie/Unsure</span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="clutch" value="1">
                                        <span class="radio-label"><?= htmlspecialchars($current_match['player1_name']) ?> better</span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="clutch" value="2">
                                        <span class="radio-label"><?= htmlspecialchars($current_match['player2_name']) ?> better</span>
                                    </label>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="attribute <?= in_array('creativity', $current_match['voted_attributes']) ? 'voted' : '' ?>">
                                <label>Creativity: <?= in_array('creativity', $current_match['voted_attributes']) ? '✓ Voted' : '' ?></label>
                                <?php if (!in_array('creativity', $current_match['voted_attributes'])): ?>
                                <div class="radio-group">
                                    <label class="radio-option">
                                        <input type="radio" name="creativity" value="0" required>
                                        <span class="radio-label">Tie/Unsure</span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="creativity" value="1">
                                        <span class="radio-label"><?= htmlspecialchars($current_match['player1_name']) ?> better</span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="creativity" value="2">
                                        <span class="radio-label"><?= htmlspecialchars($current_match['player2_name']) ?> better</span>
                                    </label>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="attribute <?= in_array('aggression', $current_match['voted_attributes']) ? 'voted' : '' ?>">
                                <label>Aggression: <?= in_array('aggression', $current_match['voted_attributes']) ? '✓ Voted' : '' ?></label>
                                <?php if (!in_array('aggression', $current_match['voted_attributes'])): ?>
                                <div class="radio-group">
                                    <label class="radio-option">
                                        <input type="radio" name="aggression" value="0" required>
                                        <span class="radio-label">Tie/Unsure</span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="aggression" value="1">
                                        <span class="radio-label"><?= htmlspecialchars($current_match['player1_name']) ?> better</span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="aggression" value="2">
                                        <span class="radio-label"><?= htmlspecialchars($current_match['player2_name']) ?> better</span>
                                    </label>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="attribute <?= in_array('strategy', $current_match['voted_attributes']) ? 'voted' : '' ?>">
                                <label>Strategy: <?= in_array('strategy', $current_match['voted_attributes']) ? '✓ Voted' : '' ?></label>
                                <?php if (!in_array('strategy', $current_match['voted_attributes'])): ?>
                                <div class="radio-group">
                                    <label class="radio-option">
                                        <input type="radio" name="strategy" value="0" required>
                                        <span class="radio-label">Tie/Unsure</span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="strategy" value="1">
                                        <span class="radio-label"><?= htmlspecialchars($current_match['player1_name']) ?> better</span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="strategy" value="2">
                                        <span class="radio-label"><?= htmlspecialchars($current_match['player2_name']) ?> better</span>
                                    </label>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <?php if ($current_match['vote_status'] === 'partial'): ?>
                                <button type="submit" class="btn btn-warning">Complete Missing Votes</button>
                            <?php elseif ($current_match['vote_status'] === 'completed'): ?>
                                <button type="submit" class="btn btn-secondary" disabled>All Votes Submitted</button>
                            <?php else: ?>
                                <button type="submit" class="btn btn-success">Submit Votes</button>
                            <?php endif; ?>
                            
                            <div class="navigation-buttons">
                                <?php if ($current_match_index > 0): ?>
                                    <a href="?token=<?= htmlspecialchars($_GET['token']) ?>&match=<?= $current_match_index - 1 ?>" class="btn btn-secondary">← Previous</a>
                                <?php endif; ?>
                                <?php if ($current_match_index < $total_matches - 1): ?>
                                    <a href="?token=<?= htmlspecialchars($_GET['token']) ?>&match=<?= $current_match_index + 1 ?>" class="btn btn-outline">Skip →</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <!-- Quick Navigation -->
        <div class="quick-nav">
            <h3>Quick Navigation</h3>
            <div class="nav-buttons">
                <?php if ($current_match_index > 0): ?>
                    <a href="?token=<?= htmlspecialchars($_GET['token']) ?>&match=<?= $current_match_index - 1 ?>" class="btn btn-sm btn-secondary">← Previous</a>
                <?php endif; ?>
                
                <span class="current-position"><?= $current_match_index + 1 ?> of <?= $total_matches ?></span>
                
                <?php if ($current_match_index < $total_matches - 1): ?>
                    <a href="?token=<?= htmlspecialchars($_GET['token']) ?>&match=<?= $current_match_index + 1 ?>" class="btn btn-sm btn-primary">Next →</a>
                <?php endif; ?>
            </div>
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
        max-width: 800px;
        margin: 20px auto;
        padding: 20px;
    }
    
    .voting-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        padding: 20px;
        background: rgba(0, 0, 0, 0.3);
        border-radius: 10px;
        border: 1px solid rgba(0, 212, 255, 0.2);
    }
    
    .voting-header h1 {
        margin: 0;
        color: #00d4ff;
        font-size: 2rem;
    }
    
    .reviewer-info {
        margin-top: 10px;
    }
    
    .reviewer-info p {
        margin: 0;
        color: #ccc;
    }
    
    .mode-toggle {
        text-align: right;
    }
    
    .progress-section {
        margin-bottom: 30px;
        padding: 20px;
        background: rgba(0, 0, 0, 0.2);
        border-radius: 10px;
        border: 1px solid rgba(0, 212, 255, 0.1);
    }
    
    .progress-info {
        display: flex;
        justify-content: space-between;
        margin-bottom: 10px;
    }
    
    .progress-text {
        font-weight: bold;
        color: #00d4ff;
    }
    
    .overall-progress {
        color: #ccc;
    }
    
    .progress-bar {
        width: 100%;
        height: 20px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        overflow: hidden;
    }
    
    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #00d4ff, #0099cc);
        transition: width 0.3s ease;
    }
    
    .match-card {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        padding: 30px;
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
    
    .match-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .match-header h2 {
        color: #00d4ff;
        margin: 0;
    }
    
    .match-status {
        padding: 8px 15px;
        border-radius: 20px;
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
        gap: 30px;
        margin-bottom: 20px;
    }
    
    .player {
        text-align: center;
        padding: 15px;
        border-radius: 8px;
        background: rgba(0, 0, 0, 0.2);
    }
    
    .player.winner {
        color: #28a745;
        border: 1px solid #28a745;
    }
    
    .player.loser {
        color: #dc3545;
        border: 1px solid #dc3545;
    }
    
    .vs {
        font-size: 1.5em;
        font-weight: bold;
        color: #00d4ff;
    }
    
    .match-info {
        margin-bottom: 20px;
    }
    
    .match-info p {
        margin: 5px 0;
        color: #ccc;
    }
    
    .vod-link {
        text-align: center;
        margin-bottom: 20px;
    }
    
    .attributes-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .attribute {
        padding: 15px;
        border-radius: 8px;
        background: rgba(0, 0, 0, 0.2);
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
        padding: 10px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 5px;
        background: rgba(0, 0, 0, 0.3);
        color: #e0e0e0;
        font-size: 14px;
    }
    
    .attribute select:disabled {
        opacity: 0.6;
        cursor: not-allowed;
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
    
    .match-link h2 {
        margin: 0;
        transition: all 0.2s ease;
    }
    
    .match-link:hover h2 {
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
    
    /* Mobile Responsive Design */
    @media (max-width: 768px) {
        .container {
            padding: 10px;
        }
        
        .voting-header {
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
        
        .form-actions {
            flex-direction: column;
            gap: 15px;
            padding: 15px;
        }
        
        .navigation-buttons {
            justify-content: center;
            width: 100%;
        }
        
        .btn {
            min-width: 100px;
            padding: 12px 20px;
            font-size: 14px;
        }
        
        .match-card {
            padding: 15px;
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
        
        .vs {
            margin: 10px 0;
        }
        
        .progress-section {
            margin: 20px 0;
        }
        
        .progress-info {
            flex-direction: column;
            gap: 5px;
            text-align: center;
        }

        .help-notice {
            padding: 12px;
            margin-bottom: 15px;
        }

        .help-notice p {
            font-size: 0.9rem;
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

        .help-notice {
            padding: 10px;
            margin-bottom: 10px;
        }

        .help-notice p {
            font-size: 0.85rem;
        }
    }
    
    .form-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
        margin-top: 30px;
        padding: 20px;
        background: rgba(0, 0, 0, 0.3);
        border-radius: 10px;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .navigation-buttons {
        display: flex;
        gap: 10px;
    }
    
    .btn {
        min-width: 120px;
        min-height: 44px;
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
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        -webkit-tap-highlight-color: transparent;
    }
    
    .btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        pointer-events: none;
    }
    
    .btn-primary {
        background: #007bff;
        color: white;
    }
    
    .btn-primary:hover {
        background: #0056b3;
    }
    
    .btn-secondary {
        background: #6c757d;
        color: white;
    }
    
    .btn-secondary:hover {
        background: #545b62;
    }
    
    .btn-success {
        background: #28a745;
        color: white;
    }
    
    .btn-success:hover {
        background: #1e7e34;
    }
    
    .btn-warning {
        background: #ffc107;
        color: #212529;
    }
    
    .btn-warning:hover {
        background: #e0a800;
    }
    
    .btn-outline {
        background: transparent;
        color: #00d4ff;
        border: 1px solid #00d4ff;
    }
    
    .btn-outline:hover {
        background: #00d4ff;
        color: #000;
    }
    
    .btn-sm {
        padding: 8px 16px;
        font-size: 12px;
    }
    
    .voted-message {
        text-align: center;
        padding: 20px;
        background: rgba(40, 167, 69, 0.1);
        border-radius: 8px;
        border: 1px solid #28a745;
    }
    
    .voted-message p {
        margin: 0 0 20px 0;
        font-size: 1.2em;
        color: #28a745;
    }
    
    .quick-nav {
        margin-top: 30px;
        padding: 20px;
        background: rgba(0, 0, 0, 0.2);
        border-radius: 10px;
        border: 1px solid rgba(0, 212, 255, 0.1);
        text-align: center;
    }
    
    .quick-nav h3 {
        margin: 0 0 15px 0;
        color: #00d4ff;
    }
    
    .nav-buttons {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 15px;
    }
    
    .current-position {
        font-weight: bold;
        color: #ccc;
        padding: 8px 16px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 5px;
    }
    
    .alert {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 8px;
        border: 1px solid;
    }
    
    .alert-success {
        background: rgba(40, 167, 69, 0.1);
        border-color: #28a745;
        color: #28a745;
    }
    
    .alert-error {
        background: rgba(220, 53, 69, 0.1);
        border-color: #dc3545;
        color: #dc3545;
    }

    .help-notice {
        background: rgba(40, 167, 69, 0.1);
        border: 1px solid #28a745;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
        text-align: center;
    }

    .help-notice p {
        margin: 0;
        color: #e0e0e0;
        font-size: 1rem;
        line-height: 1.4;
    }

    .help-link {
        color: #28a745;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.3s ease;
        border-bottom: 1px solid #28a745;
    }

    .help-link:hover {
        color: #34ce57;
        border-bottom-color: #34ce57;
        text-shadow: 0 0 5px rgba(52, 206, 87, 0.5);
    }

    /* New styles for match selector */
    .match-selector {
        margin-top: 30px;
        padding: 20px;
        background: rgba(0, 0, 0, 0.2);
        border-radius: 10px;
        border: 1px solid rgba(0, 212, 255, 0.1);
    }

    .selector-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
    }

    .selector-header h3 {
        margin: 0;
        color: #00d4ff;
    }

    .selector-controls {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .quick-jump-select {
        flex: 1;
        padding: 10px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 5px;
        background: rgba(0, 0, 0, 0.3);
        color: #e0e0e0;
        font-size: 14px;
        -webkit-appearance: none; /* Remove default arrow */
        -moz-appearance: none;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23e0e0e0' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' class='feather feather-chevron-down'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 10px center;
        background-size: 16px;
    }

    .quick-jump-select:focus {
        outline: none;
        border-color: #00d4ff;
    }

    .match-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
    }

    .match-filters {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }

    .filter-btn {
        padding: 8px 16px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 20px;
        background: rgba(0, 0, 0, 0.3);
        color: #e0e0e0;
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 14px;
    }

    .filter-btn:hover {
        background: rgba(255, 255, 255, 0.1);
        border-color: rgba(0, 212, 255, 0.3);
    }

    .filter-btn.active {
        background: rgba(0, 212, 255, 0.2);
        border-color: #00d4ff;
        color: #00d4ff;
    }

    .matches-container {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
    }

    .match-item {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 15px;
        border-radius: 8px;
        background: rgba(0, 0, 0, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.05);
        text-decoration: none;
        color: #e0e0e0;
        transition: all 0.2s ease;
        cursor: pointer;
    }

    .match-item.hidden {
        display: none;
    }

    .match-item.current {
        background: rgba(0, 212, 255, 0.1);
        border-color: #00d4ff;
        box-shadow: 0 0 10px rgba(0, 212, 255, 0.3);
    }

    .match-number {
        font-size: 1.5em;
        font-weight: bold;
        color: #00d4ff;
        min-width: 30px;
        text-align: center;
    }

    .match-id {
        font-size: 0.9em;
        color: #ccc;
        min-width: 80px;
    }

    .match-status-indicator {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .status-icon {
        font-size: 1.2em;
        color: #00d4ff;
    }

    .status-icon.completed {
        color: #28a745;
    }

    .status-icon.partial {
        color: #ffc107;
    }

    .status-icon.pending {
        color: #6c757d;
    }

    .match-players {
        display: flex;
        align-items: center;
        gap: 10px;
        flex: 1;
    }

    .player1, .player2 {
        font-weight: bold;
        color: #00d4ff;
        font-size: 1.1em;
    }

    .player1 {
        text-align: right;
    }

    .player2 {
        text-align: left;
    }

    .vs {
        font-size: 1.2em;
        color: #00d4ff;
    }
</style>

<script>
// Auto-advance to next match after successful submission
<?php if ($message && strpos($message, 'successfully') !== false): ?>
setTimeout(function() {
    <?php if ($current_match_index < $total_matches - 1): ?>
        window.location.href = '?token=<?= htmlspecialchars($_GET['token']) ?>&match=<?= $current_match_index + 1 ?>';
    <?php else: ?>
        // If this was the last match, show completion message
        alert('Congratulations! You have completed all matches!');
    <?php endif; ?>
}, 2000);
<?php endif; ?>

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.target.tagName === 'SELECT') return; // Don't interfere with form inputs
    
    if (e.key === 'ArrowLeft' && <?= $current_match_index > 0 ? 'true' : 'false' ?>) {
        window.location.href = '?token=<?= htmlspecialchars($_GET['token']) ?>&match=<?= $current_match_index - 1 ?>';
    } else if (e.key === 'ArrowRight' && <?= $current_match_index < $total_matches - 1 ? 'true' : 'false' ?>) {
        window.location.href = '?token=<?= htmlspecialchars($_GET['token']) ?>&match=<?= $current_match_index + 1 ?>';
    }
});

// Toggle match selector visibility
document.getElementById('toggleSelector').addEventListener('click', function() {
    const matchGrid = document.getElementById('matchGrid');
    matchGrid.style.display = matchGrid.style.display === 'none' ? 'grid' : 'none';
    this.textContent = matchGrid.style.display === 'none' ? 'Show All Matches' : 'Hide All Matches';
});

// Quick jump to selected match
document.getElementById('quickJump').addEventListener('change', function() {
    const selectedMatchIndex = this.value;
    if (selectedMatchIndex !== '') {
        window.location.href = '?token=<?= htmlspecialchars($_GET['token']) ?>&match=' + selectedMatchIndex;
    }
});

// Filter matches by status
document.querySelectorAll('.filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const filter = this.getAttribute('data-filter');
        
        // Update active button
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        
        // Filter match items
        document.querySelectorAll('.match-item').forEach(item => {
            const status = item.getAttribute('data-status');
            if (filter === 'all' || status === filter) {
                item.classList.remove('hidden');
            } else {
                item.classList.add('hidden');
            }
        });
    });
});
</script> 