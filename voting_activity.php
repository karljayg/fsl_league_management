<?php
/**
 * FSL Voting Activity - Advanced Search & Filter
 * Admin interface to search and view voting activity with filters
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Include configuration and database connection
require_once 'config.php';
require_once 'includes/db.php';
require_once 'includes/reviewer_functions.php';

// Set timezone from config
date_default_timezone_set($config['timezone']);

// Set required permission
$required_permission = 'manage spider charts';

// Include permission check
require_once 'includes/check_permission.php';

// Get filter parameters
$reviewer_filter = $_GET['reviewer'] ?? '';
$player_filter = $_GET['player'] ?? '';
$match_filter = $_GET['match'] ?? '';
$attribute_filter = $_GET['attribute'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$vote_result = $_GET['vote_result'] ?? '';

// Build query with filters
$where_conditions = ["1=1"]; // Always true condition to start
$params = [];

if (!empty($reviewer_filter)) {
    // We'll filter by reviewer name after getting the data from CSV
    // For now, we'll get all votes and filter them in PHP
}

if (!empty($player_filter)) {
    $where_conditions[] = "(p1.Real_Name LIKE ? OR p2.Real_Name LIKE ?)";
    $params[] = '%' . $player_filter . '%';
    $params[] = '%' . $player_filter . '%';
}

if (!empty($match_filter)) {
    $where_conditions[] = "pav.fsl_match_id = ?";
    $params[] = $match_filter;
}

if (!empty($attribute_filter)) {
    $where_conditions[] = "pav.attribute = ?";
    $params[] = $attribute_filter;
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(pav.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(pav.created_at) <= ?";
    $params[] = $date_to;
}

if (!empty($vote_result)) {
    if ($vote_result === 'player1') {
        $where_conditions[] = "pav.vote = 1";
    } elseif ($vote_result === 'player2') {
        $where_conditions[] = "pav.vote = 2";
    } elseif ($vote_result === 'tie') {
        $where_conditions[] = "pav.vote = 0";
    }
}

$where_clause = implode(" AND ", $where_conditions);

// Reviewer functions now handled by database

// Get voting activity with filters
$query = "
    SELECT 
        pav.id,
        pav.fsl_match_id,
        pav.attribute,
        pav.vote,
        pav.created_at,
        pav.reviewer_id,
        p1.Real_Name as player1_name,
        p2.Real_Name as player2_name,
        fm.t_code,
        fm.notes as match_notes,
        fm.season
    FROM Player_Attribute_Votes pav
    JOIN Players p1 ON pav.player1_id = p1.Player_ID
    JOIN Players p2 ON pav.player2_id = p2.Player_ID
    JOIN fsl_matches fm ON pav.fsl_match_id = fm.fsl_match_id
    WHERE $where_clause
    ORDER BY pav.created_at DESC
    LIMIT 1000
";

$stmt = $db->prepare($query);
$stmt->execute($params);
$votes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get reviewers from database and create lookup array
$reviewers = getReviewers('all');
$reviewers_by_id = [];
foreach ($reviewers as $reviewer) {
    $reviewers_by_id[$reviewer['id']] = $reviewer;
}

// Add reviewer info to votes and apply reviewer filter
$filtered_votes = [];
foreach ($votes as &$vote) {
    $reviewer_id = $vote['reviewer_id'];
    if (isset($reviewers_by_id[$reviewer_id])) {
        $vote['reviewer_name'] = $reviewers_by_id[$reviewer_id]['name'];
        $vote['reviewer_weight'] = $reviewers_by_id[$reviewer_id]['weight'];
    } else {
        $vote['reviewer_name'] = 'Unknown Reviewer';
        $vote['reviewer_weight'] = 1.0;
    }
    
    // Apply reviewer filter
    if (!empty($reviewer_filter)) {
        if (stripos($vote['reviewer_name'], $reviewer_filter) === false) {
            continue; // Skip this vote if reviewer name doesn't match
        }
    }
    
    $filtered_votes[] = $vote;
}

$votes = $filtered_votes;

// Calculate statistics from filtered votes
$unique_reviewers = [];
$unique_matches = [];
$unique_players = [];
$total_weight = 0;

foreach ($votes as $vote) {
    // Count unique reviewers
    if (!in_array($vote['reviewer_id'], $unique_reviewers)) {
        $unique_reviewers[] = $vote['reviewer_id'];
    }
    
    // Count unique matches
    if (!in_array($vote['fsl_match_id'], $unique_matches)) {
        $unique_matches[] = $vote['fsl_match_id'];
    }
    
    // Count unique players (we need to get player IDs from the database)
    // For now, we'll use a simpler approach
    $total_weight += $vote['reviewer_weight'];
}

$stats = [
    'total_votes' => count($votes),
    'unique_reviewers' => count($unique_reviewers),
    'unique_matches' => count($unique_matches),
    'unique_players' => count($unique_players), // This will be calculated separately
    'avg_reviewer_weight' => count($votes) > 0 ? $total_weight / count($votes) : 0
];

// Get unique players count from database
if (!empty($votes)) {
    $vote_ids = array_column($votes, 'id');
    $placeholders = str_repeat('?,', count($vote_ids) - 1) . '?';
    $players_query = "
        SELECT COUNT(DISTINCT player_id) as unique_players
        FROM (
            SELECT player1_id as player_id FROM Player_Attribute_Votes WHERE id IN ($placeholders)
            UNION
            SELECT player2_id as player_id FROM Player_Attribute_Votes WHERE id IN ($placeholders)
        ) as all_players
    ";
    $players_stmt = $db->prepare($players_query);
    $players_stmt->execute(array_merge($vote_ids, $vote_ids));
    $players_result = $players_stmt->fetch(PDO::FETCH_ASSOC);
    $stats['unique_players'] = $players_result['unique_players'] ?? 0;
}

// Set page title
$pageTitle = "Voting Activity";

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <div class="admin-header">
        <h1>Voting Activity</h1>
        <div class="admin-user-info">
            <a href="spider_chart_admin.php" class="btn btn-secondary">← Back to Dashboard</a>
            <span>Logged in as: <?= htmlspecialchars($_SESSION['username'] ?? 'Unknown') ?></span>
            <a href="logout.php" class="btn btn-logout">Logout</a>
        </div>
    </div>
    
    <div class="activity-subtitle">
        <p>Search and filter voting activity across all reviewers and matches</p>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <h2>Search Filters</h2>
        <form method="get" class="filter-form">
            <div class="filter-grid">
                <div class="filter-group">
                    <label>Reviewer:</label>
                    <input type="text" name="reviewer" id="reviewer-input" value="<?= htmlspecialchars($reviewer_filter) ?>" placeholder="Search by reviewer name..." autocomplete="off">
                    <div id="reviewer-suggestions" class="suggestions-dropdown"></div>
                </div>
                
                <div class="filter-group">
                    <label>Player:</label>
                    <input type="text" name="player" id="player-input" value="<?= htmlspecialchars($player_filter) ?>" placeholder="Search by player name..." autocomplete="off">
                    <div id="player-suggestions" class="suggestions-dropdown"></div>
                </div>
                
                <div class="filter-group">
                    <label>Match ID:</label>
                    <input type="number" name="match" value="<?= htmlspecialchars($match_filter) ?>" placeholder="Enter match ID...">
                </div>
                
                <div class="filter-group">
                    <label>Attribute:</label>
                    <select name="attribute">
                        <option value="">All Attributes</option>
                        <option value="micro" <?= $attribute_filter === 'micro' ? 'selected' : '' ?>>Micro</option>
                        <option value="macro" <?= $attribute_filter === 'macro' ? 'selected' : '' ?>>Macro</option>
                        <option value="clutch" <?= $attribute_filter === 'clutch' ? 'selected' : '' ?>>Clutch</option>
                        <option value="creativity" <?= $attribute_filter === 'creativity' ? 'selected' : '' ?>>Creativity</option>
                        <option value="aggression" <?= $attribute_filter === 'aggression' ? 'selected' : '' ?>>Aggression</option>
                        <option value="strategy" <?= $attribute_filter === 'strategy' ? 'selected' : '' ?>>Strategy</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Date From:</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                
                <div class="filter-group">
                    <label>Date To:</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                
                <div class="filter-group">
                    <label>Vote Result:</label>
                    <select name="vote_result">
                        <option value="">All Results</option>
                        <option value="player1" <?= $vote_result === 'player1' ? 'selected' : '' ?>>Player 1 Won</option>
                        <option value="player2" <?= $vote_result === 'player2' ? 'selected' : '' ?>>Player 2 Won</option>
                        <option value="tie" <?= $vote_result === 'tie' ? 'selected' : '' ?>>Tie</option>
                    </select>
                </div>
            </div>
            
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">🔍 Search</button>
                <a href="voting_activity.php" class="btn btn-secondary">🔄 Clear Filters</a>
                <button type="button" class="btn btn-info" onclick="exportResults()">📊 Export Results</button>
            </div>
        </form>
    </div>

    <!-- Statistics -->
    <div class="stats-section">
        <h2>Search Results</h2>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">📊</div>
                <div class="stat-content">
                    <h3><?= $stats['total_votes'] ?? 0 ?></h3>
                    <p>Total Votes</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-content">
                    <h3><?= $stats['unique_reviewers'] ?? 0 ?></h3>
                    <p>Reviewers</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">🎮</div>
                <div class="stat-content">
                    <h3><?= $stats['unique_matches'] ?? 0 ?></h3>
                    <p>Matches</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">🏆</div>
                <div class="stat-content">
                    <h3><?= $stats['unique_players'] ?? 0 ?></h3>
                    <p>Players</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">⚖️</div>
                <div class="stat-content">
                    <h3><?= round($stats['avg_reviewer_weight'] ?? 0, 2) ?></h3>
                    <p>Avg Weight</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Results Table -->
    <div class="results-section">
        <h2>Voting Results (<?= count($votes) ?> votes)</h2>
        
        <?php if (empty($votes)): ?>
            <div class="no-results">
                <p>No votes found matching your criteria.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="votes-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Reviewer</th>
                            <th>Match</th>
                            <th>Players</th>
                            <th>Attribute</th>
                            <th>Vote</th>
                            <th>Weight</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($votes as $vote): ?>
                            <tr>
                                <td><?= date('M j, Y g:i A', strtotime($vote['created_at'] . ' UTC')) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($vote['reviewer_name']) ?></strong>
                                </td>
                                <td>
                                    <a href="view_match.php?id=<?= $vote['fsl_match_id'] ?>" class="match-link">
                                        #<?= $vote['fsl_match_id'] ?>
                                    </a>
                                    <br><small>S<?= $vote['season'] ?> - <?= htmlspecialchars($vote['t_code']) ?></small>
                                </td>
                                <td>
                                    <span class="player1"><?= htmlspecialchars($vote['player1_name']) ?></span>
                                    <span class="vs">vs</span>
                                    <span class="player2"><?= htmlspecialchars($vote['player2_name']) ?></span>
                                </td>
                                <td>
                                    <span class="attribute-badge"><?= ucfirst($vote['attribute']) ?></span>
                                </td>
                                <td>
                                    <?php if ($vote['vote'] == 1): ?>
                                        <span class="vote-result winner"><?= htmlspecialchars($vote['player1_name']) ?> won</span>
                                    <?php elseif ($vote['vote'] == 2): ?>
                                        <span class="vote-result winner"><?= htmlspecialchars($vote['player2_name']) ?> won</span>
                                    <?php else: ?>
                                        <span class="vote-result tie">Tie</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="weight-badge"><?= $vote['reviewer_weight'] ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
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
        max-width: 1400px;
        margin: 20px auto;
        padding: 20px;
    }
    
    .admin-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        padding: 20px;
        background: rgba(0, 0, 0, 0.3);
        border-radius: 10px;
        border: 1px solid rgba(0, 212, 255, 0.2);
    }

    .admin-header h1 {
        margin: 0;
        color: #00d4ff;
        font-size: 2rem;
    }

    .admin-user-info {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .admin-user-info span {
        color: #ccc;
    }

    .activity-subtitle {
        text-align: center;
        margin-bottom: 2rem;
        padding: 1rem;
        background: rgba(0, 0, 0, 0.2);
        border-radius: 8px;
        border: 1px solid rgba(0, 212, 255, 0.1);
    }

    .activity-subtitle p {
        margin: 0;
        color: #ccc;
        font-size: 1.1rem;
    }

    /* Filter Section */
    .filter-section {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        padding: 25px;
        margin-bottom: 30px;
    }

    .filter-section h2 {
        color: #00d4ff;
        margin-bottom: 20px;
    }

    .filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
    }

    .filter-group label {
        color: #00d4ff;
        font-weight: 600;
        margin-bottom: 5px;
    }

    .filter-group input,
    .filter-group select {
        padding: 10px;
        border-radius: 4px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        background: rgba(0, 0, 0, 0.3);
        color: #e0e0e0;
        position: relative;
    }

    .suggestions-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: rgba(0, 0, 0, 0.9);
        border: 1px solid rgba(0, 212, 255, 0.3);
        border-radius: 4px;
        max-height: 200px;
        overflow-y: auto;
        z-index: 1000;
        display: none;
    }

    .suggestion-item {
        padding: 8px 12px;
        cursor: pointer;
        color: #e0e0e0;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .suggestion-item:hover,
    .suggestion-item.selected {
        background: rgba(0, 212, 255, 0.2);
        color: #00d4ff;
    }

    .suggestion-item:last-child {
        border-bottom: none;
    }

    .filter-group {
        position: relative;
    }

    .filter-actions {
        display: flex;
        gap: 15px;
        align-items: center;
    }

    /* Stats Section */
    .stats-section {
        margin-bottom: 30px;
    }

    .stats-section h2 {
        color: #00d4ff;
        margin-bottom: 20px;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
    }

    .stat-card {
        background: rgba(0, 0, 0, 0.3);
        border: 1px solid rgba(0, 212, 255, 0.2);
        border-radius: 10px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .stat-icon {
        font-size: 2rem;
    }

    .stat-content h3 {
        margin: 0;
        color: #00d4ff;
        font-size: 1.8rem;
    }

    .stat-content p {
        margin: 5px 0 0 0;
        color: #ccc;
    }

    /* Results Section */
    .results-section {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        padding: 25px;
    }

    .results-section h2 {
        color: #00d4ff;
        margin-bottom: 20px;
    }

    .no-results {
        text-align: center;
        padding: 60px;
        color: #ccc;
    }

    .table-responsive {
        overflow-x: auto;
    }

    .votes-table {
        width: 100%;
        border-collapse: collapse;
        background: rgba(0, 0, 0, 0.3);
        border-radius: 8px;
        overflow: hidden;
    }

    .votes-table th,
    .votes-table td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .votes-table th {
        background: rgba(0, 212, 255, 0.2);
        color: #ffffff;
        font-weight: 700;
    }

    .votes-table tr:hover {
        background: rgba(0, 212, 255, 0.1);
    }

    /* Styling for table elements */
    .match-link {
        color: #00d4ff;
        text-decoration: none;
    }

    .match-link:hover {
        text-decoration: underline;
    }

    .player1 {
        color: #28a745;
        font-weight: 600;
    }

    .player2 {
        color: #dc3545;
        font-weight: 600;
    }

    .vs {
        color: #ccc;
        margin: 0 5px;
    }

    .attribute-badge {
        background: rgba(0, 212, 255, 0.2);
        color: #00d4ff;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.9em;
        font-weight: 600;
    }

    .vote-result {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.9em;
        font-weight: 600;
    }

    .vote-result.winner {
        background: rgba(40, 167, 69, 0.2);
        color: #28a745;
    }

    .vote-result.tie {
        background: rgba(255, 193, 7, 0.2);
        color: #ffc107;
    }

    .weight-badge {
        background: rgba(108, 117, 125, 0.2);
        color: #6c757d;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.9em;
        font-weight: 600;
    }

    .btn {
        padding: 8px 16px;
        border-radius: 4px;
        border: none;
        cursor: pointer;
        font-weight: 600;
        text-decoration: none;
        text-align: center;
        transition: all 0.3s ease;
    }

    .btn-primary {
        background-color: #00d4ff;
        color: #0f0c29;
    }

    .btn-primary:hover {
        background-color: #00b8e6;
    }

    .btn-secondary {
        background-color: rgba(255, 255, 255, 0.1);
        color: #e0e0e0;
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .btn-secondary:hover {
        background-color: rgba(255, 255, 255, 0.2);
    }

    .btn-info {
        background-color: #17a2b8;
        color: white;
    }

    .btn-info:hover {
        background-color: #138496;
    }

    .btn-logout {
        background-color: #dc3545;
        color: white;
    }

    .btn-logout:hover {
        background-color: #c82333;
    }

    @media (max-width: 768px) {
        .container {
            padding: 10px;
        }
        
        .admin-header {
            flex-direction: column;
            gap: 15px;
        }
        
        .filter-grid {
            grid-template-columns: 1fr;
        }
        
        .filter-actions {
            flex-direction: column;
            align-items: stretch;
        }
        
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .votes-table {
            font-size: 14px;
        }
    }
</style>

<script>
function exportResults() {
    // Get current URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    
    // Create export URL
    const exportUrl = 'voting_activity.php?' + urlParams.toString() + '&export=csv';
    
    // Open in new window/tab for download
    window.open(exportUrl, '_blank');
}

// Auto-submit form when certain filters change
document.addEventListener('DOMContentLoaded', function() {
    const autoSubmitSelects = document.querySelectorAll('select[name="attribute"], select[name="vote_result"]');
    
    autoSubmitSelects.forEach(select => {
        select.addEventListener('change', function() {
            this.form.submit();
        });
    });

    // Autocomplete functionality
    setupAutocomplete('reviewer-input', 'reviewer-suggestions', 'get_reviewer_suggestions.php');
    setupAutocomplete('player-input', 'player-suggestions', 'get_player_suggestions.php');
});

function setupAutocomplete(inputId, suggestionsId, endpoint) {
    const input = document.getElementById(inputId);
    const suggestions = document.getElementById(suggestionsId);
    let timeoutId;

    input.addEventListener('input', function() {
        const query = this.value.trim();
        
        // Clear previous timeout
        clearTimeout(timeoutId);
        
        // Hide suggestions if query is too short
        if (query.length < 2) {
            suggestions.style.display = 'none';
            return;
        }

        // Set timeout to avoid too many requests
        timeoutId = setTimeout(() => {
            fetchSuggestions(query, endpoint, suggestions);
        }, 300);
    });

    // Handle keyboard navigation
    input.addEventListener('keydown', function(e) {
        const visibleSuggestions = suggestions.querySelectorAll('.suggestion-item');
        const currentIndex = Array.from(visibleSuggestions).findIndex(item => item.classList.contains('selected'));
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            navigateSuggestions(visibleSuggestions, currentIndex, 1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            navigateSuggestions(visibleSuggestions, currentIndex, -1);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const selectedItem = suggestions.querySelector('.suggestion-item.selected');
            if (selectedItem) {
                input.value = selectedItem.textContent;
                suggestions.style.display = 'none';
                input.form.submit();
            }
        } else if (e.key === 'Escape') {
            suggestions.style.display = 'none';
        }
    });

    // Handle clicks outside to close suggestions
    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !suggestions.contains(e.target)) {
            suggestions.style.display = 'none';
        }
    });
}

function fetchSuggestions(query, endpoint, suggestionsElement) {
    const formData = new FormData();
    formData.append('query', query);

    fetch(endpoint, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        displaySuggestions(data, suggestionsElement);
    })
    .catch(error => {
        console.error('Error fetching suggestions:', error);
    });
}

function displaySuggestions(suggestions, suggestionsElement) {
    suggestionsElement.innerHTML = '';
    
    if (suggestions.length === 0) {
        suggestionsElement.style.display = 'none';
        return;
    }

    suggestions.forEach(suggestion => {
        const item = document.createElement('div');
        item.className = 'suggestion-item';
        item.textContent = suggestion;
        item.addEventListener('click', function() {
            const input = suggestionsElement.previousElementSibling;
            input.value = this.textContent;
            suggestionsElement.style.display = 'none';
            input.form.submit();
        });
        suggestionsElement.appendChild(item);
    });

    suggestionsElement.style.display = 'block';
}

function navigateSuggestions(suggestions, currentIndex, direction) {
    const newIndex = currentIndex + direction;
    
    // Remove current selection
    if (currentIndex >= 0 && currentIndex < suggestions.length) {
        suggestions[currentIndex].classList.remove('selected');
    }
    
    // Add selection to new item
    if (newIndex >= 0 && newIndex < suggestions.length) {
        suggestions[newIndex].classList.add('selected');
    }
}
</script>

<?php include 'includes/footer.php'; ?> 