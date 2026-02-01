<?php
/**
 * FSL Spider Chart Match Queue Dashboard
 * Admin interface to view match queue and voting progress
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Include database connection first
require_once 'includes/db.php';
require_once 'includes/season_utils.php';

// Set required permission for this page
$required_permission = 'manage spider charts';

// Include permission check
require_once 'includes/check_permission.php';

// Get filter parameters (default to current/max season)
$currentSeason = getCurrentSeason($db);
$season = isset($_GET['season']) ? (int) $_GET['season'] : $currentSeason;
$t_code = $_GET['t_code'] ?? '';
$status = $_GET['status'] ?? '';

// Build query with filters
$where_conditions = ["fm.season = ?"];
$params = [$season];

if ($t_code) {
    $where_conditions[] = "fm.t_code = ?";
    $params[] = $t_code;
}

if ($status) {
    if ($status === 'voted') {
        $where_conditions[] = "pav.reviewer_id IS NOT NULL";
    } else {
        $where_conditions[] = "pav.reviewer_id IS NULL";
    }
}

$where_clause = implode(" AND ", $where_conditions);

// Get matches with voting status
$query = "
    SELECT 
        fm.fsl_match_id,
        fm.season,
        fm.t_code,
        fm.notes,
        fm.source,
        fm.vod,
        p1.Real_Name as player1_name,
        p2.Real_Name as player2_name,
        fm.winner_player_id,
        fm.loser_player_id,
        fm.map_win,
        fm.map_loss,
        COUNT(DISTINCT pav.reviewer_id) as votes_received,
        COUNT(DISTINCT r.id) as total_reviewers,
        ROUND(COUNT(DISTINCT pav.reviewer_id) * 100.0 / COUNT(DISTINCT r.id), 1) as completion_percentage
    FROM fsl_matches fm
    JOIN Players p1 ON fm.winner_player_id = p1.Player_ID
    JOIN Players p2 ON fm.loser_player_id = p2.Player_ID
    CROSS JOIN Reviewers r
    LEFT JOIN Player_Attribute_Votes pav ON fm.fsl_match_id = pav.fsl_match_id AND pav.reviewer_id = r.id
    WHERE $where_clause AND r.status = 'active'
    GROUP BY fm.fsl_match_id, fm.season, fm.t_code, fm.notes, fm.source, fm.vod, 
             p1.Real_Name, p2.Real_Name, fm.winner_player_id, fm.loser_player_id, 
             fm.map_win, fm.map_loss
    ORDER BY fm.fsl_match_id DESC
";

$stmt = $db->prepare($query);
$stmt->execute($params);
$matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = $db->query("
    SELECT 
        COUNT(DISTINCT fm.fsl_match_id) as total_matches,
        COUNT(DISTINCT r.id) as total_reviewers,
        COUNT(DISTINCT pav.id) as total_votes,
        ROUND(AVG(
            CASE 
                WHEN match_votes.vote_count IS NOT NULL 
                THEN match_votes.vote_count * 100.0 / (SELECT COUNT(*) FROM Reviewers WHERE status = 'active')
                ELSE 0 
            END
        ), 1) as avg_completion_percentage
    FROM fsl_matches fm
    CROSS JOIN Reviewers r
    LEFT JOIN Player_Attribute_Votes pav ON r.id = pav.reviewer_id
    LEFT JOIN (
        SELECT 
            fsl_match_id,
            COUNT(DISTINCT reviewer_id) as vote_count
        FROM Player_Attribute_Votes
        GROUP BY fsl_match_id
    ) match_votes ON fm.fsl_match_id = match_votes.fsl_match_id
    WHERE fm.season = $season AND r.status = 'active'
")->fetch(PDO::FETCH_ASSOC);

// Get reviewer participation
$reviewer_participation = $db->query("
    SELECT 
        r.name,
        r.weight,
        COUNT(DISTINCT pav.fsl_match_id) as matches_voted,
        COUNT(pav.id) as total_votes,
        ROUND(COUNT(DISTINCT pav.fsl_match_id) * 100.0 / (SELECT COUNT(*) FROM fsl_matches WHERE season = $season), 1) as participation_percentage
    FROM Reviewers r
    LEFT JOIN Player_Attribute_Votes pav ON r.id = pav.reviewer_id
    LEFT JOIN fsl_matches fm ON pav.fsl_match_id = fm.fsl_match_id AND fm.season = $season
    WHERE r.status = 'active'
    GROUP BY r.id, r.name, r.weight
    ORDER BY participation_percentage DESC, matches_voted DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Set page title
$pageTitle = "FSL Spider Chart Match Queue";

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <div class="admin-header">
        <h1>FSL Spider Chart Match Queue</h1>
        <div class="admin-user-info">
            <a href="spider_chart_admin.php" class="btn btn-secondary">← Back to Dashboard</a>
            <span>Logged in as: <?= htmlspecialchars($_SESSION['username'] ?? 'Unknown') ?></span>
            <a href="logout.php" class="btn btn-logout">Logout</a>
        </div>
    </div>

    <!-- Statistics Overview -->
    <div class="stats-overview">
        <div class="stat-card">
            <h3>Total Matches</h3>
            <div class="stat-value"><?= $stats['total_matches'] ?></div>
        </div>
        <div class="stat-card">
            <h3>Active Reviewers</h3>
            <div class="stat-value"><?= $stats['total_reviewers'] ?></div>
        </div>
        <div class="stat-card">
            <h3>Total Votes</h3>
            <div class="stat-value"><?= $stats['total_votes'] ?></div>
        </div>
        <div class="stat-card">
            <h3>Avg Completion</h3>
            <div class="stat-value"><?= $stats['avg_completion_percentage'] ?>%</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-section">
        <form method="get" class="filters-form">
            <div class="form-group">
                <label>Season:</label>
                <select name="season" onchange="this.form.submit()">
                    <?php
                    $seasonOpts = $db->query("SELECT DISTINCT season FROM fsl_matches ORDER BY season DESC")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($seasonOpts as $s): ?>
                    <option value="<?= (int) $s ?>" <?= $season == (int) $s ? 'selected' : '' ?>>Season <?= (int) $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Code:</label>
                <select name="t_code" onchange="this.form.submit()">
                    <option value="">All Codes</option>
                    <option value="A" <?= $t_code == 'A' ? 'selected' : '' ?>>Code A</option>
                    <option value="B" <?= $t_code == 'B' ? 'selected' : '' ?>>Code B</option>
                    <option value="S" <?= $t_code == 'S' ? 'selected' : '' ?>>Code S</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Status:</label>
                <select name="status" onchange="this.form.submit()">
                    <option value="">All Matches</option>
                    <option value="voted" <?= $status == 'voted' ? 'selected' : '' ?>>Voted</option>
                    <option value="pending" <?= $status == 'pending' ? 'selected' : '' ?>>Pending</option>
                </select>
            </div>
        </form>
    </div>

    <!-- Match Queue -->
    <div class="match-queue">
        <h2>Match Queue (Season <?= $season ?>)</h2>
        
        <div class="matches-table">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Match ID</th>
                        <th>Players</th>
                        <th>Code</th>
                        <th>Score</th>
                        <th>Votes</th>
                        <th>Completion</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($matches as $match): ?>
                        <tr class="<?= $match['completion_percentage'] == 100 ? 'completed' : '' ?>">
                            <td>#<?= $match['fsl_match_id'] ?></td>
                            <td>
                                <div class="players-info">
                                    <div class="player winner">
                                        <?= htmlspecialchars($match['player1_name']) ?>
                                        <span class="score"><?= $match['map_win'] ?>-<?= $match['map_loss'] ?></span>
                                    </div>
                                    <div class="vs">vs</div>
                                    <div class="player loser">
                                        <?= htmlspecialchars($match['player2_name']) ?>
                                    </div>
                                </div>
                                <?php if ($match['notes']): ?>
                                    <div class="match-notes"><?= htmlspecialchars($match['notes']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="code-badge <?= strtolower($match['t_code']) ?>">
                                    <?= $match['t_code'] ?>
                                </span>
                            </td>
                            <td><?= $match['map_win'] ?>-<?= $match['map_loss'] ?></td>
                            <td>
                                <?= $match['votes_received'] ?>/<?= $match['total_reviewers'] ?>
                            </td>
                            <td>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?= $match['completion_percentage'] ?>%"></div>
                                    <span class="progress-text"><?= $match['completion_percentage'] ?>%</span>
                                </div>
                            </td>
                            <td>
                                <?php if ($match['vod']): ?>
                                    <a href="<?= htmlspecialchars($match['vod']) ?>" target="_blank" class="btn btn-sm btn-primary">VOD</a>
                                <?php endif; ?>
                                <a href="view_match.php?id=<?= $match['fsl_match_id'] ?>" class="btn btn-sm btn-secondary">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Reviewer Participation -->
    <div class="reviewer-participation">
        <h2>Reviewer Participation</h2>
        
        <div class="reviewers-table">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Reviewer</th>
                        <th>Weight</th>
                        <th>Matches Voted</th>
                        <th>Total Votes</th>
                        <th>Participation</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reviewer_participation as $reviewer): ?>
                        <tr>
                            <td><?= htmlspecialchars($reviewer['name']) ?></td>
                            <td><?= $reviewer['weight'] ?>x</td>
                            <td><?= $reviewer['matches_voted'] ?></td>
                            <td><?= $reviewer['total_votes'] ?></td>
                            <td>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?= $reviewer['participation_percentage'] ?>%"></div>
                                    <span class="progress-text"><?= $reviewer['participation_percentage'] ?>%</span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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
    }
    
    .admin-user-info {
        display: flex;
        align-items: center;
        gap: 15px;
        color: #ccc;
    }
    
    h1 {
        color: #00d4ff;
        text-shadow: 0 0 15px #00d4ff;
        font-size: 2.4em;
        margin: 0;
    }
    
    .stats-overview {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .stat-card {
        background: rgba(0, 212, 255, 0.1);
        border: 1px solid rgba(0, 212, 255, 0.3);
        border-radius: 10px;
        padding: 20px;
        text-align: center;
    }
    
    .stat-card h3 {
        color: #00d4ff;
        margin-bottom: 10px;
        font-size: 1em;
    }
    
    .stat-value {
        font-size: 2em;
        font-weight: bold;
        color: #ffffff;
    }
    
    .filters-section {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 30px;
    }
    
    .filters-form {
        display: flex;
        gap: 20px;
        align-items: end;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
    }
    
    .form-group label {
        color: #00d4ff;
        font-weight: 600;
        margin-bottom: 5px;
    }
    
    .form-group select {
        padding: 8px;
        border-radius: 4px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        background: rgba(0, 0, 0, 0.3);
        color: #e0e0e0;
        min-width: 120px;
    }
    
    .match-queue,
    .reviewer-participation {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        padding: 25px;
        margin-bottom: 30px;
    }
    
    .match-queue h2,
    .reviewer-participation h2 {
        color: #00d4ff;
        margin-bottom: 20px;
    }
    
    .table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .table th,
    .table td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .table th {
        background: rgba(0, 212, 255, 0.2);
        color: #ffffff;
        font-weight: 700;
    }
    
    .players-info {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 5px;
    }
    
    .player {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.9em;
    }
    
    .player.winner {
        background: rgba(40, 167, 69, 0.2);
        border: 1px solid #28a745;
    }
    
    .player.loser {
        background: rgba(220, 53, 69, 0.2);
        border: 1px solid #dc3545;
    }
    
    .vs {
        font-weight: bold;
        color: #00d4ff;
    }
    
    .score {
        font-weight: bold;
        color: #00d4ff;
        margin-left: 5px;
    }
    
    .match-notes {
        font-size: 0.8em;
        color: #ccc;
        font-style: italic;
    }
    
    .code-badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 0.8em;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .code-badge.a { background: #28a745; color: white; }
    .code-badge.b { background: #ffc107; color: #0f0c29; }
    .code-badge.s { background: #dc3545; color: white; }
    
    .progress-bar {
        position: relative;
        width: 100px;
        height: 20px;
        background: rgba(0, 0, 0, 0.3);
        border-radius: 10px;
        overflow: hidden;
    }
    
    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #28a745, #20c997);
        transition: width 0.3s ease;
    }
    
    .progress-text {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-size: 0.7em;
        font-weight: bold;
        color: white;
        text-shadow: 1px 1px 1px rgba(0, 0, 0, 0.5);
    }
    
    .btn {
        padding: 6px 12px;
        border-radius: 4px;
        border: none;
        cursor: pointer;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.3s ease;
        display: inline-block;
        margin-right: 5px;
    }
    
    .btn-primary { background: #00d4ff; color: #0f0c29; }
    .btn-secondary { background: #6c757d; color: white; }
    .btn-logout { background: #dc3545; color: white; }
    .btn-sm { padding: 4px 8px; font-size: 0.8em; }
    
    .btn:hover {
        opacity: 0.8;
        text-decoration: none;
    }
    
    .completed {
        background: rgba(40, 167, 69, 0.1);
    }
    
    @media (max-width: 768px) {
        .container {
            padding: 10px;
        }
        
        .admin-header {
            flex-direction: column;
            gap: 15px;
        }
        
        .filters-form {
            flex-direction: column;
            gap: 15px;
        }
        
        .stats-overview {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .table {
            font-size: 0.9em;
        }
        
        .players-info {
            flex-direction: column;
            gap: 5px;
        }
    }
</style>

<?php include 'includes/footer.php'; ?> 