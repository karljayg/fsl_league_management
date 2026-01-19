<?php
/**
 * Edit Match Page
 * Allows searching for matches to edit with various search criteria
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Include database connection
require_once 'includes/db.php';

// Check if user has permission to edit matches
$required_permission = 'edit_matches';
require_once 'includes/check_permission.php';

// Connect to database
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get all players for dropdown menus
$playersQuery = "SELECT Player_ID, Real_Name FROM Players ORDER BY Real_Name";
try {
    $stmt = $db->query($playersQuery);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Query failed: " . $e->getMessage());
}

// Process search form submission
$searchResults = [];
$searchPerformed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
    $searchPerformed = true;
    
    // Build the query
    $query = "SELECT 
        fm.*,
        p_w.Real_Name AS winner_name,
        p_l.Real_Name AS loser_name
    FROM fsl_matches fm
    JOIN Players p_w ON fm.winner_player_id = p_w.Player_ID
    JOIN Players p_l ON fm.loser_player_id = p_l.Player_ID
    WHERE 1=1";
    
    $params = [];
    
    // Add search conditions
    if (!empty($_POST['match_id'])) {
        $query .= " AND fm.fsl_match_id = :match_id";
        $params['match_id'] = $_POST['match_id'];
    }
    
    if (!empty($_POST['season'])) {
        $query .= " AND fm.season = :season";
        $params['season'] = $_POST['season'];
    }
    
    if (!empty($_POST['season_extra_info'])) {
        $query .= " AND fm.season_extra_info LIKE :season_extra_info";
        $params['season_extra_info'] = '%' . $_POST['season_extra_info'] . '%';
    }
    
    if (!empty($_POST['t_code'])) {
        $query .= " AND fm.t_code LIKE :t_code";
        $params['t_code'] = '%' . $_POST['t_code'] . '%';
    }
    
    if (!empty($_POST['winner_player_id'])) {
        $query .= " AND fm.winner_player_id = :winner_player_id";
        $params['winner_player_id'] = $_POST['winner_player_id'];
    }
    
    if (!empty($_POST['loser_player_id'])) {
        $query .= " AND fm.loser_player_id = :loser_player_id";
        $params['loser_player_id'] = $_POST['loser_player_id'];
    }
    
    if (!empty($_POST['winner_race'])) {
        $query .= " AND fm.winner_race LIKE :winner_race";
        $params['winner_race'] = '%' . $_POST['winner_race'] . '%';
    }
    
    if (!empty($_POST['loser_race'])) {
        $query .= " AND fm.loser_race LIKE :loser_race";
        $params['loser_race'] = '%' . $_POST['loser_race'] . '%';
    }
    
    if (!empty($_POST['best_of'])) {
        $query .= " AND fm.best_of = :best_of";
        $params['best_of'] = $_POST['best_of'];
    }
    
    if (!empty($_POST['player_name'])) {
        $query .= " AND (p_w.Real_Name LIKE :player_name OR p_l.Real_Name LIKE :player_name)";
        $params['player_name'] = '%' . $_POST['player_name'] . '%';
    }
    
    // Add order by
    $query .= " ORDER BY fm.season DESC, fm.fsl_match_id DESC";
    
    // Add limit
    $query .= " LIMIT 100";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Search query failed: " . $e->getMessage());
    }
}

// Process match update if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_match'])) {
    $matchId = $_POST['match_id'];
    
    // Validate required fields
    if (empty($_POST['winner_player_id']) || empty($_POST['loser_player_id']) || 
        empty($_POST['winner_race']) || empty($_POST['loser_race']) || 
        empty($_POST['season']) || empty($_POST['best_of']) || 
        empty($_POST['map_win']) || empty($_POST['map_loss'])) {
        $updateError = "All required fields must be filled out.";
    } else {
        // Build update query
        $updateQuery = "UPDATE fsl_matches SET 
            season = :season,
            season_extra_info = :season_extra_info,
            notes = :notes,
            t_code = :t_code,
            winner_player_id = :winner_player_id,
            winner_race = :winner_race,
            best_of = :best_of,
            map_win = :map_win,
            map_loss = :map_loss,
            loser_player_id = :loser_player_id,
            loser_race = :loser_race,
            source = :source,
            vod = :vod
        WHERE fsl_match_id = :match_id";
        
        $updateParams = [
            'season' => $_POST['season'],
            'season_extra_info' => $_POST['season_extra_info'],
            'notes' => $_POST['notes'],
            't_code' => $_POST['t_code'],
            'winner_player_id' => $_POST['winner_player_id'],
            'winner_race' => $_POST['winner_race'],
            'best_of' => $_POST['best_of'],
            'map_win' => $_POST['map_win'],
            'map_loss' => $_POST['map_loss'],
            'loser_player_id' => $_POST['loser_player_id'],
            'loser_race' => $_POST['loser_race'],
            'source' => $_POST['source'],
            'vod' => $_POST['vod'],
            'match_id' => $matchId
        ];
        
        try {
            $stmt = $db->prepare($updateQuery);
            $stmt->execute($updateParams);
            $updateSuccess = "Match #$matchId updated successfully!";
            
            // Redirect to view the updated match
            header("Location: view_match.php?id=$matchId");
            exit;
        } catch (PDOException $e) {
            $updateError = "Update failed: " . $e->getMessage();
        }
    }
}

// Get match data if editing a specific match
$matchData = null;
if (isset($_GET['id'])) {
    $matchId = (int)$_GET['id'];
    
    $matchQuery = "SELECT * FROM fsl_matches WHERE fsl_match_id = :match_id";
    try {
        $stmt = $db->prepare($matchQuery);
        $stmt->execute(['match_id' => $matchId]);
        $matchData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$matchData) {
            die("Match not found");
        }
    } catch (PDOException $e) {
        die("Query failed: " . $e->getMessage());
    }
}

// Set page title
$pageTitle = isset($_GET['id']) ? "Edit Match #" . htmlspecialchars($_GET['id']) : "Search Matches to Edit";

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <div class="admin-header">
        <h1><i class="fas fa-edit"></i> Match Editor</h1>
        <div class="admin-user-info">
            <span>Logged in as: <?= htmlspecialchars($_SESSION['username'] ?? 'Unknown') ?></span>
            <a href="logout.php" class="btn btn-logout">Logout</a>
        </div>
    </div>
    
    <?php if (isset($_GET['id'])): ?>
        <!-- Edit Match Form -->
        <h2 class="text-center">Edit Match #<?= htmlspecialchars($_GET['id']) ?></h2>
        
        <?php if (isset($updateError)): ?>
            <div class="alert alert-danger"><?= $updateError ?></div>
        <?php endif; ?>
        
        <?php if (isset($updateSuccess)): ?>
            <div class="alert alert-success"><?= $updateSuccess ?></div>
        <?php endif; ?>
        
        <form method="post" action="edit_match.php?id=<?= htmlspecialchars($_GET['id']) ?>" class="edit-match-form">
            <input type="hidden" name="match_id" value="<?= htmlspecialchars($_GET['id']) ?>">
            <input type="hidden" name="update_match" value="1">
            
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="season">Season *</label>
                    <input type="number" class="form-control" id="season" name="season" value="<?= htmlspecialchars($matchData['season']) ?>" required>
                </div>
                <div class="form-group col-md-6">
                    <label for="season_extra_info">Season Extra Info</label>
                    <input type="text" class="form-control" id="season_extra_info" name="season_extra_info" value="<?= htmlspecialchars($matchData['season_extra_info'] ?? '') ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="t_code">Tournament Code</label>
                    <input type="text" class="form-control" id="t_code" name="t_code" value="<?= htmlspecialchars($matchData['t_code'] ?? '') ?>">
                </div>
                <div class="form-group col-md-6">
                    <label for="best_of">Best of *</label>
                    <input type="number" class="form-control" id="best_of" name="best_of" value="<?= htmlspecialchars($matchData['best_of']) ?>" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="winner_player_id">Winner *</label>
                    <select class="form-control" id="winner_player_id" name="winner_player_id" required>
                        <option value="">Select Winner</option>
                        <?php foreach ($players as $player): ?>
                            <option value="<?= $player['Player_ID'] ?>" <?= ($matchData['winner_player_id'] == $player['Player_ID']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($player['Real_Name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-6">
                    <label for="winner_race">Winner Race *</label>
                    <input type="text" class="form-control" id="winner_race" name="winner_race" value="<?= htmlspecialchars($matchData['winner_race']) ?>" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="loser_player_id">Loser *</label>
                    <select class="form-control" id="loser_player_id" name="loser_player_id" required>
                        <option value="">Select Loser</option>
                        <?php foreach ($players as $player): ?>
                            <option value="<?= $player['Player_ID'] ?>" <?= ($matchData['loser_player_id'] == $player['Player_ID']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($player['Real_Name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-6">
                    <label for="loser_race">Loser Race *</label>
                    <input type="text" class="form-control" id="loser_race" name="loser_race" value="<?= htmlspecialchars($matchData['loser_race']) ?>" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="map_win">Maps Won *</label>
                    <input type="number" class="form-control" id="map_win" name="map_win" value="<?= htmlspecialchars($matchData['map_win']) ?>" required>
                </div>
                <div class="form-group col-md-6">
                    <label for="map_loss">Maps Lost *</label>
                    <input type="number" class="form-control" id="map_loss" name="map_loss" value="<?= htmlspecialchars($matchData['map_loss']) ?>" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="notes">Notes</label>
                <textarea class="form-control" id="notes" name="notes" rows="3"><?= htmlspecialchars($matchData['notes'] ?? '') ?></textarea>
            </div>
            
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="source">Source URL</label>
                    <input type="url" class="form-control" id="source" name="source" value="<?= htmlspecialchars($matchData['source'] ?? '') ?>">
                </div>
                <div class="form-group col-md-6">
                    <label for="vod">VOD URL</label>
                    <input type="url" class="form-control" id="vod" name="vod" value="<?= htmlspecialchars($matchData['vod'] ?? '') ?>">
                </div>
            </div>
            
            <div class="form-group text-center">
                <button type="submit" class="btn btn-primary">Update Match</button>
                <a href="view_match.php?id=<?= htmlspecialchars($_GET['id']) ?>" class="btn btn-secondary ml-2">Cancel</a>
            </div>
        </form>
    <?php else: ?>
        <!-- Search Form -->
        <h2 class="text-center">Search Matches to Edit</h2>
        
        <form method="post" action="edit_match.php" class="search-form mb-4">
            <input type="hidden" name="search" value="1">
            
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="match_id">Match ID</label>
                    <input type="number" class="form-control" id="match_id" name="match_id" value="<?= htmlspecialchars($_POST['match_id'] ?? '') ?>">
                </div>
                <div class="form-group col-md-4">
                    <label for="season">Season</label>
                    <input type="number" class="form-control" id="season" name="season" value="<?= htmlspecialchars($_POST['season'] ?? '') ?>">
                </div>
                <div class="form-group col-md-4">
                    <label for="season_extra_info">Season Extra Info</label>
                    <input type="text" class="form-control" id="season_extra_info" name="season_extra_info" value="<?= htmlspecialchars($_POST['season_extra_info'] ?? '') ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="t_code">Tournament Code</label>
                    <input type="text" class="form-control" id="t_code" name="t_code" value="<?= htmlspecialchars($_POST['t_code'] ?? '') ?>">
                </div>
                <div class="form-group col-md-4">
                    <label for="best_of">Best of</label>
                    <input type="number" class="form-control" id="best_of" name="best_of" value="<?= htmlspecialchars($_POST['best_of'] ?? '') ?>">
                </div>
                <div class="form-group col-md-4">
                    <label for="player_name">Player Name</label>
                    <input type="text" class="form-control" id="player_name" name="player_name" value="<?= htmlspecialchars($_POST['player_name'] ?? '') ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group col-md-3">
                    <label for="winner_player_id">Winner</label>
                    <select class="form-control" id="winner_player_id" name="winner_player_id">
                        <option value="">Any Winner</option>
                        <?php foreach ($players as $player): ?>
                            <option value="<?= $player['Player_ID'] ?>" <?= (isset($_POST['winner_player_id']) && $_POST['winner_player_id'] == $player['Player_ID']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($player['Real_Name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-3">
                    <label for="winner_race">Winner Race</label>
                    <input type="text" class="form-control" id="winner_race" name="winner_race" value="<?= htmlspecialchars($_POST['winner_race'] ?? '') ?>">
                </div>
                <div class="form-group col-md-3">
                    <label for="loser_player_id">Loser</label>
                    <select class="form-control" id="loser_player_id" name="loser_player_id">
                        <option value="">Any Loser</option>
                        <?php foreach ($players as $player): ?>
                            <option value="<?= $player['Player_ID'] ?>" <?= (isset($_POST['loser_player_id']) && $_POST['loser_player_id'] == $player['Player_ID']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($player['Real_Name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-3">
                    <label for="loser_race">Loser Race</label>
                    <input type="text" class="form-control" id="loser_race" name="loser_race" value="<?= htmlspecialchars($_POST['loser_race'] ?? '') ?>">
                </div>
            </div>
            
            <div class="form-group text-center">
                <button type="submit" class="btn btn-primary">Search</button>
                <button type="reset" class="btn btn-secondary ml-2">Reset</button>
            </div>
        </form>
        
        <!-- Search Results -->
        <?php if ($searchPerformed): ?>
            <h2 class="mt-4">Search Results (<?= count($searchResults) ?> matches found)</h2>
            
            <?php if (empty($searchResults)): ?>
                <div class="alert alert-info">No matches found matching your search criteria.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="thead-dark">
                            <tr>
                                <th>ID</th>
                                <th>Season</th>
                                <th>Winner</th>
                                <th>Race</th>
                                <th>Score</th>
                                <th>Loser</th>
                                <th>Race</th>
                                <th>Tournament</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($searchResults as $match): ?>
                                <tr>
                                    <td><?= htmlspecialchars($match['fsl_match_id']) ?></td>
                                    <td>
                                        <?= htmlspecialchars($match['season']) ?>
                                        <?php if (!empty($match['season_extra_info'])): ?>
                                            <small>(<?= htmlspecialchars($match['season_extra_info']) ?>)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($match['winner_name']) ?></td>
                                    <td><?= htmlspecialchars($match['winner_race']) ?></td>
                                    <td><?= htmlspecialchars($match['map_win']) ?>-<?= htmlspecialchars($match['map_loss']) ?></td>
                                    <td><?= htmlspecialchars($match['loser_name']) ?></td>
                                    <td><?= htmlspecialchars($match['loser_race']) ?></td>
                                    <td><?= htmlspecialchars($match['t_code'] ?? '') ?></td>
                                    <td>
                                        <a href="edit_match.php?id=<?= $match['fsl_match_id'] ?>" class="btn btn-sm btn-primary">Edit</a>
                                        <a href="view_match.php?id=<?= $match['fsl_match_id'] ?>" class="btn btn-sm btn-info">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
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
        max-width: 1200px;
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
    
    h2 {
        color: #00d4ff;
        margin-bottom: 20px;
        text-align: center;
    }
    
    .search-form, .edit-match-form {
        background: rgba(255, 255, 255, 0.1);
        padding: 20px;
        border-radius: 10px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        margin-bottom: 30px;
        border: 1px solid rgba(0, 212, 255, 0.2);
    }
    
    .form-group label {
        color: #00d4ff;
        font-weight: 600;
        margin-bottom: 8px;
        display: block;
    }
    
    .form-control {
        background-color: rgba(0, 0, 0, 0.5);
        border: 1px solid rgba(0, 212, 255, 0.3);
        color: #e0e0e0;
        border-radius: 5px;
        padding: 10px;
    }
    
    .form-control:focus {
        background-color: rgba(0, 0, 0, 0.5);
        border-color: #00d4ff;
        color: #e0e0e0;
        box-shadow: 0 0 10px rgba(0, 212, 255, 0.3);
        outline: none;
    }
    
    .form-control::placeholder {
        color: rgba(224, 224, 224, 0.6);
    }
    
    .table {
        background: rgba(0, 0, 0, 0.3);
        color: #e0e0e0;
        border: 1px solid rgba(0, 212, 255, 0.2);
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
    }
    
    .table th {
        background: rgba(0, 212, 255, 0.1);
        color: #00d4ff;
        border-color: rgba(0, 212, 255, 0.2);
        font-weight: bold;
    }
    
    .table td {
        border-color: rgba(0, 212, 255, 0.2);
        vertical-align: middle;
    }
    
    .table-striped tbody tr:nth-of-type(odd) {
        background-color: rgba(0, 212, 255, 0.05);
    }
    
    .table-hover tbody tr:hover {
        background-color: rgba(0, 212, 255, 0.1);
    }
    
    .btn {
        padding: 8px 16px;
        border-radius: 5px;
        border: none;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-block;
    }
    
    .btn-primary {
        background: #00d4ff;
        color: #0f0c29;
        border: none;
    }
    
    .btn-primary:hover {
        background: #00b8e6;
        transform: translateY(-1px);
    }
    
    .btn-secondary {
        background: #6c757d;
        color: white;
        border: none;
    }
    
    .btn-secondary:hover {
        background: #5a6268;
        transform: translateY(-1px);
    }
    
    .btn-info {
        background: #17a2b8;
        color: white;
        border: none;
    }
    
    .btn-info:hover {
        background: #138496;
        transform: translateY(-1px);
    }
    
    .btn-sm {
        padding: 4px 8px;
        font-size: 0.8em;
    }
    
    .btn-logout {
        background: #dc3545;
        color: white;
        padding: 8px 16px;
        border-radius: 5px;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    
    .btn-logout:hover {
        opacity: 0.8;
        transform: translateY(-1px);
    }
    
    .alert {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 5px;
    }
    
    .alert-danger {
        background: rgba(220, 53, 69, 0.2);
        border: 1px solid #dc3545;
        color: #dc3545;
    }
    
    .alert-success {
        background: rgba(40, 167, 69, 0.2);
        border: 1px solid #28a745;
        color: #28a745;
    }
    
    .alert-info {
        background: rgba(33, 150, 243, 0.2);
        border: 1px solid #2196f3;
        color: #2196f3;
    }
    
    .text-center {
        text-align: center;
    }
    
    .mt-4 {
        margin-top: 1.5rem;
    }
    
    .mb-4 {
        margin-bottom: 1.5rem;
    }
    
    .ml-2 {
        margin-left: 0.5rem;
    }
    
    .form-row {
        display: flex;
        flex-wrap: wrap;
        margin-right: -5px;
        margin-left: -5px;
    }
    
    .col-md-3, .col-md-4, .col-md-6 {
        position: relative;
        width: 100%;
        padding-right: 5px;
        padding-left: 5px;
    }
    
    .col-md-3 {
        flex: 0 0 25%;
        max-width: 25%;
    }
    
    .col-md-4 {
        flex: 0 0 33.333333%;
        max-width: 33.333333%;
    }
    
    .col-md-6 {
        flex: 0 0 50%;
        max-width: 50%;
    }
    
    @media (max-width: 768px) {
        .form-row {
            flex-direction: column;
        }
        
        .col-md-3, .col-md-4, .col-md-6 {
            flex: 0 0 100%;
            max-width: 100%;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .admin-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
        
        h1 {
            font-size: 2em;
        }
    }
</style>

<?php include 'includes/footer.php'; ?> 