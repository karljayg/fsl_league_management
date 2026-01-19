<?php
/**
 * FSL Spider Chart Admin Dashboard
 * Central hub for all spider chart management functions
 */

// Include database connection first
require_once 'includes/db.php';
require_once 'includes/reviewer_functions.php';

// Set required permission
$required_permission = 'manage spider charts';

// Include permission check
require_once 'includes/check_permission.php';

$message = '';
$error = '';

// Get quick statistics
try {
    // Count reviewers
    $reviewers_count = countReviewers('active');
    
    // Count matches with votes
    $matches_with_votes = $db->query("
        SELECT COUNT(DISTINCT fsl_match_id) as count 
        FROM Player_Attribute_Votes
    ")->fetchColumn();
    
    // Count total votes
    $total_votes = $db->query("
        SELECT COUNT(*) as count 
        FROM Player_Attribute_Votes
    ")->fetchColumn();
    
    // Count players with scores
    $players_with_scores = $db->query("
        SELECT COUNT(DISTINCT player_id) as count 
        FROM Player_Attributes
    ")->fetchColumn();
    
    // Get recent activity (last 10 votes)
    $recent_votes = $db->query("
        SELECT 
            pav.fsl_match_id,
            p1.Real_Name as player1_name,
            p2.Real_Name as player2_name,
            pav.attribute,
            pav.vote,
            pav.created_at
        FROM Player_Attribute_Votes pav
        JOIN Players p1 ON pav.player1_id = p1.Player_ID
        JOIN Players p2 ON pav.player2_id = p2.Player_ID
        ORDER BY pav.created_at DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Error loading statistics: " . $e->getMessage();
}

// Set page title
$pageTitle = "FSL Spider Chart Admin Dashboard";

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <div class="admin-header">
        <h1>FSL Spider Chart Admin Dashboard</h1>
        <div class="admin-user-info">
            <span>Logged in as: <?= htmlspecialchars($_SESSION['username'] ?? 'Unknown') ?></span>
            <a href="logout.php" class="btn btn-logout">Logout</a>
        </div>
    </div>
    
    <div class="dashboard-subtitle">
        <p>Central management hub for the FSL Spider Chart System</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Quick Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">👥</div>
            <div class="stat-content">
                <h3><?= $reviewers_count ?></h3>
                <p>Active Reviewers</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">🎮</div>
            <div class="stat-content">
                <h3><?= $matches_with_votes ?></h3>
                <p>Matches Voted On</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">📊</div>
            <div class="stat-content">
                <h3><?= $total_votes ?></h3>
                <p>Total Votes Cast</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">🏆</div>
            <div class="stat-content">
                <h3><?= $players_with_scores ?></h3>
                <p>Players with Scores</p>
            </div>
        </div>
    </div>

    <!-- Main Admin Functions -->
    <div class="admin-sections">
        <div class="section-group">
            <h2>Reviewer Management</h2>
            <div class="function-cards">
                <div class="function-card">
                    <div class="function-icon">👥</div>
                    <h3>Manage Reviewers</h3>
                    <p>Add, edit, and manage spider chart reviewers</p>
                    <a href="manage_reviewers.php" class="btn btn-primary">Open Manager</a>
                </div>
                

            </div>
        </div>

        <div class="section-group">
            <h2>Data Processing</h2>
            <div class="function-cards">
                <div class="function-card">
                    <div class="function-icon">⚡</div>
                    <h3>Aggregate Scores</h3>
                    <p>Process votes and calculate player attribute scores</p>
                    <a href="aggregate_scores.php" target="_blank" class="btn btn-warning">Run Aggregation</a>
                </div>
                
                <div class="function-card">
                    <div class="function-icon">📊</div>
                    <h3>Voting Activity</h3>
                    <p>Search and filter voting activity with advanced filters</p>
                    <a href="voting_activity.php" class="btn btn-primary">View Activity</a>
                </div>
            </div>
        </div>



        <div class="section-group">
            <h2>Visualization & Analysis</h2>
            <div class="function-cards">
                <div class="function-card">
                    <div class="function-icon">📊</div>
                    <h3>Player Analysis</h3>
                    <p>Comprehensive player profiles and spider chart visualizations</p>
                    <a href="player_analysis.php" class="btn btn-primary">View Analysis</a>
                </div>
            </div>
        </div>



    </div>

<style>
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

.dashboard-subtitle {
    text-align: center;
    margin-bottom: 2rem;
    padding: 1rem;
    background: rgba(0, 0, 0, 0.2);
    border-radius: 8px;
    border: 1px solid rgba(0, 212, 255, 0.1);
}

.dashboard-subtitle p {
    margin: 0;
    color: #ccc;
    font-size: 1.1rem;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 3rem;
}

.stat-card {
    background: rgba(0, 0, 0, 0.3);
    padding: 1.5rem;
    border-radius: 10px;
    border: 1px solid rgba(0, 212, 255, 0.2);
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: all 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-5px);
    border-color: rgba(0, 212, 255, 0.4);
}

.stat-icon {
    font-size: 2.5rem;
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #00d4ff;
    color: #0f0c29;
    border-radius: 50%;
}

.stat-content h3 {
    margin: 0;
    font-size: 2rem;
    color: #00d4ff;
}

.stat-content p {
    margin: 0.25rem 0 0 0;
    color: #ccc;
    font-weight: 500;
}

.admin-sections {
    margin-bottom: 3rem;
}

.section-group {
    margin-bottom: 3rem;
}

.section-group h2 {
    color: #00d4ff;
    margin-bottom: 1.5rem;
    padding-bottom: 0.5rem;
    border-bottom: 3px solid #00d4ff;
    display: inline-block;
}

.function-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1.5rem;
}

.function-card {
    background: rgba(0, 0, 0, 0.3);
    padding: 2rem;
    border-radius: 10px;
    border: 1px solid rgba(0, 212, 255, 0.2);
    text-align: center;
    transition: all 0.3s ease;
}

.function-card:hover {
    transform: translateY(-5px);
    border-color: rgba(0, 212, 255, 0.4);
}

.function-icon {
    font-size: 3rem;
    margin-bottom: 1rem;
}

.function-card h3 {
    margin: 0 0 1rem 0;
    color: #00d4ff;
    font-size: 1.3rem;
}

.function-card p {
    margin: 0 0 1.5rem 0;
    color: #ccc;
    line-height: 1.5;
}

.recent-activity {
    background: white;
    padding: 2rem;
    border-radius: 15px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    margin-bottom: 3rem;
}

.recent-activity h2 {
    margin: 0 0 1.5rem 0;
    color: #333;
}

.activity-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.activity-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 10px;
}

.activity-icon {
    font-size: 1.5rem;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #667eea;
    color: white;
    border-radius: 50%;
}

.activity-content {
    flex: 1;
}

.activity-title {
    font-weight: 500;
    color: #333;
    margin-bottom: 0.25rem;
}

.activity-details {
    color: #666;
    font-size: 0.9rem;
}

.vote-result {
    font-weight: 500;
}

.vote-result.winner {
    color: #28a745;
}

.vote-result.tie {
    color: #ffc107;
}

.quick-actions {
    background: white;
    padding: 2rem;
    border-radius: 15px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
}

.quick-actions h2 {
    margin: 0 0 1.5rem 0;
    color: #333;
}

.action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
}

.btn {
    padding: 8px 16px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-primary { background: #00d4ff; color: #0f0c29; }
.btn-secondary { background: #6c757d; color: white; }
.btn-warning { background: #ffc107; color: #0f0c29; }
.btn-info { background: #17a2b8; color: white; }
.btn-logout { background: #dc3545; color: white; }

.btn:hover {
    opacity: 0.8;
    text-decoration: none;
    transform: translateY(-2px);
}



@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .function-cards {
        grid-template-columns: 1fr;
    }
    
    .action-buttons {
        flex-direction: column;
    }
    
    .btn {
        justify-content: center;
    }
    
    .filter-row {
        grid-template-columns: 1fr;
    }
    
    .filter-actions {
        flex-direction: column;
        align-items: stretch;
    }
    
    .voting-stats {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .votes-table {
        font-size: 0.8rem;
    }
}
</style>

<?php include 'includes/footer.php'; ?> 