<?php
/**
 * FSL Matches Editor
 * Allows FSL managers to edit match results and add new matches
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Include database connection
require_once 'includes/db.php';

// Check permission
$required_permission = 'manage fsl schedule';
include 'includes/check_permission_updated.php';

// Connect to database
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Function to get dropdown options
function getDropdownOptions($db, $table, $valueColumn, $textColumn, $orderBy = null) {
    $orderClause = $orderBy ? "ORDER BY $orderBy" : "ORDER BY $textColumn";
    $query = "SELECT DISTINCT $valueColumn, $textColumn FROM $table $orderClause";
    $stmt = $db->query($query);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Get dropdown options
$players = getDropdownOptions($db, 'Players', 'Player_ID', 'Real_Name');
$teams = getDropdownOptions($db, 'Teams', 'Team_ID', 'Team_Name');
// Player ID -> Team ID for auto-fill when player selection changes
$playerTeamStmt = $db->query("SELECT Player_ID, Team_ID FROM Players");
$playerToTeam = [];
while ($row = $playerTeamStmt->fetch(PDO::FETCH_ASSOC)) {
    $playerToTeam[$row['Player_ID']] = $row['Team_ID'] ? (int)$row['Team_ID'] : '';
}

// Get races
$races = [
    'P' => 'Protoss',
    'T' => 'Terran', 
    'Z' => 'Zerg',
    'R' => 'Random'
];

// Get tournament codes
$tournamentCodes = [
    'S' => 'S Division',
    'A' => 'A Division',
    'B' => 'B Division',
    '2v2' => '2v2 Match',
    'ACE' => 'Ace Match',
    'OTHER' => 'Other'
];

// Process search query and pagination
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$seasonFilter = isset($_GET['season']) ? trim($_GET['season']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

$searchCondition = '';
$params = [];

if (!empty($searchQuery)) {
    $searchCondition .= " AND (p_w.Real_Name LIKE :search OR p_l.Real_Name LIKE :search OR fm.notes LIKE :search)";
    $params[':search'] = "%{$searchQuery}%";
}

if (!empty($seasonFilter)) {
    $searchCondition .= " AND fm.season = :season";
    $params[':season'] = $seasonFilter;
}

// Get total count for pagination
$countQuery = "
    SELECT COUNT(*) as total
    FROM fsl_matches fm
    JOIN Players p_w ON fm.winner_player_id = p_w.Player_ID
    JOIN Players p_l ON fm.loser_player_id = p_l.Player_ID
    WHERE 1=1 $searchCondition
";

$countStmt = $db->prepare($countQuery);
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalMatches = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalMatches / $perPage);

// Get FSL matches with player information (paginated)
$query = "
    SELECT 
        fm.fsl_match_id,
        fm.season,
        fm.season_extra_info,
        fm.notes,
        fm.t_code,
        fm.winner_player_id,
        fm.winner_race,
        fm.best_of,
        fm.map_win,
        fm.map_loss,
        fm.loser_player_id,
        fm.loser_race,
        fm.source,
        fm.vod,
        fm.winner_team_id,
        fm.loser_team_id,
        p_w.Real_Name AS winner_name,
        p_l.Real_Name AS loser_name
    FROM 
        fsl_matches fm
    JOIN 
        Players p_w ON fm.winner_player_id = p_w.Player_ID
    JOIN 
        Players p_l ON fm.loser_player_id = p_l.Player_ID
    WHERE 1=1 $searchCondition
    ORDER BY 
        fm.fsl_match_id DESC
    LIMIT $perPage OFFSET $offset
";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique seasons for filter
$seasonQuery = "SELECT DISTINCT season FROM fsl_matches ORDER BY season DESC";
$seasons = $db->query($seasonQuery)->fetchAll(PDO::FETCH_COLUMN);

// Include header
include 'includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="admin-header">
        <h1><i class="fas fa-gamepad"></i> FSL Matches Editor</h1>
        <div class="admin-user-info">
            <span>Logged in as: <?= htmlspecialchars($_SESSION['username'] ?? 'Unknown') ?></span>
            <a href="logout.php" class="btn btn-logout">Logout</a>
        </div>
    </div>
    
    <!-- Search and Filter Form -->
    <form method="GET" action="" class="mb-4">
        <div class="row">
            <div class="col-md-6">
                <div class="input-group">
                    <input type="text" name="search" class="form-control" placeholder="Search by player name or notes" value="<?php echo htmlspecialchars($searchQuery); ?>">
                    <div class="input-group-append">
                        <button type="submit" class="btn btn-primary">Search</button>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <select name="season" class="form-control">
                    <option value="">All Seasons</option>
                    <?php foreach ($seasons as $season): ?>
                        <option value="<?php echo $season; ?>" <?php echo ($seasonFilter == $season) ? 'selected' : ''; ?>>
                            Season <?php echo $season; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <a href="edit_fsl_matches.php" class="btn btn-secondary btn-block">Clear</a>
            </div>
        </div>
    </form>
    
    <!-- Add New Match Button -->
    <div class="mb-3">
        <button type="button" class="btn btn-success" onclick="showAddMatchForm()">Add New Match</button>
    </div>
    
    <!-- Matches List -->
    <div class="table-responsive">
        <table id="matches-table" class="table table-dark table-striped table-bordered">
            <thead class="thead-dark">
                <tr>
                    <th style="width: 70px;">ID</th>
                    <th style="width: 280px;">Player A</th>
                    <th style="width: 100px;">A Race</th>
                    <th style="width: 100px;">A Score</th>
                    <th style="width: 280px;">Player B</th>
                    <th style="width: 100px;">B Race</th>
                    <th style="width: 100px;">B Score</th>
                    <th style="width: 100px;">Best Of</th>
                    <th style="width: 120px;">Winner</th>
                    <th style="width: 150px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($matches as $match): ?>
                    <!-- Row 1: Main player entry -->
                    <tr class="match-row match-row-1" data-match-id="<?php echo $match['fsl_match_id']; ?>">
                        <td class="match-id-cell"><?php echo htmlspecialchars($match['fsl_match_id']); ?></td>
                        <td>
                            <select name="player_a_id" class="form-control editor-select">
                                <?php foreach ($players as $id => $name): ?>
                                    <option value="<?php echo $id; ?>" <?php echo ($match['winner_player_id'] == $id) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="player_a_race" class="form-control editor-select">
                                <?php foreach ($races as $race => $name): ?>
                                    <option value="<?php echo $race; ?>" <?php echo ($match['winner_race'] == $race) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($race); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="number" name="score_a" class="form-control editor-input" value="<?php echo $match['map_win']; ?>" min="0" onchange="updateWinnerIndicator(this)">
                        </td>
                        <td>
                            <select name="player_b_id" class="form-control editor-select">
                                <?php foreach ($players as $id => $name): ?>
                                    <option value="<?php echo $id; ?>" <?php echo ($match['loser_player_id'] == $id) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="player_b_race" class="form-control editor-select">
                                <?php foreach ($races as $race => $name): ?>
                                    <option value="<?php echo $race; ?>" <?php echo ($match['loser_race'] == $race) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($race); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="number" name="score_b" class="form-control editor-input" value="<?php echo $match['map_loss']; ?>" min="0" onchange="updateWinnerIndicator(this)">
                        </td>
                        <td>
                            <select name="best_of" class="form-control editor-select">
                                <option value="1" <?php echo ($match['best_of'] == 1) ? 'selected' : ''; ?>>1</option>
                                <option value="2" <?php echo ($match['best_of'] == 2) ? 'selected' : ''; ?>>2</option>
                                <option value="3" <?php echo ($match['best_of'] == 3) ? 'selected' : ''; ?>>3</option>
                                <option value="5" <?php echo ($match['best_of'] == 5) ? 'selected' : ''; ?>>5</option>
                                <option value="7" <?php echo ($match['best_of'] == 7) ? 'selected' : ''; ?>>7</option>
                                <option value="9" <?php echo ($match['best_of'] == 9) ? 'selected' : ''; ?>>9</option>
                            </select>
                        </td>
                        <td class="winner-cell" id="winner-display-<?php echo $match['fsl_match_id']; ?>">
                            <span class="winner-text">TBD</span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button type="button" class="btn btn-success btn-action" onclick="saveMatch(this)">Save</button>
                                <button type="button" class="btn btn-danger btn-action" onclick="deleteMatch(this)" title="Delete Match">Delete</button>
                            </div>
                        </td>
                    </tr>
                    <!-- Row 2: Details / metadata -->
                    <tr class="match-row match-row-2" data-match-id="<?php echo $match['fsl_match_id']; ?>">
                        <!-- Empty ID cell so it lines up visually -->
                        <td></td>
                        <!-- Season + Extra Info + Teams -->
                        <td colspan="3">
                            <div class="metadata-group">
                                <label class="metadata-label">Season:</label>
                                <select name="season" class="form-control editor-select metadata-input">
                                    <?php for ($s = 1; $s <= 20; $s++): ?>
                                        <option value="<?php echo $s; ?>" <?php echo ($match['season'] == $s) ? 'selected' : ''; ?>>
                                            <?php echo $s; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <label class="metadata-label">Extra Info:</label>
                                <input type="text" name="season_extra_info" class="form-control editor-input metadata-input" value="<?php echo htmlspecialchars($match['season_extra_info'] ?? ''); ?>" maxlength="100" placeholder="Extra info">
                                <label class="metadata-label">Winner Team:</label>
                                <select name="winner_team_id" class="form-control editor-select metadata-input team-dropdown">
                                    <option value="" <?php echo empty($match['winner_team_id']) ? 'selected' : ''; ?>>—</option>
                                    <?php foreach ($teams as $tid => $tname): ?>
                                        <option value="<?php echo $tid; ?>" <?php echo (!empty($match['winner_team_id']) && (string)$match['winner_team_id'] === (string)$tid) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tname); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="metadata-label">Loser Team:</label>
                                <select name="loser_team_id" class="form-control editor-select metadata-input team-dropdown">
                                    <option value="" <?php echo empty($match['loser_team_id']) ? 'selected' : ''; ?>>—</option>
                                    <?php foreach ($teams as $tid => $tname): ?>
                                        <option value="<?php echo $tid; ?>" <?php echo (!empty($match['loser_team_id']) && (string)$match['loser_team_id'] === (string)$tid) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tname); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </td>
                        <!-- Notes + T Code -->
                        <td colspan="3">
                            <div class="metadata-group">
                                <label class="metadata-label">Notes:</label>
                                <input type="text" name="notes" class="form-control editor-input metadata-input" value="<?php echo htmlspecialchars($match['notes'] ?? ''); ?>" maxlength="255" placeholder="Match notes">
                                <label class="metadata-label">T Code:</label>
                                <select name="t_code" class="form-control editor-select metadata-input">
                                    <option value="">Code</option>
                                    <?php foreach ($tournamentCodes as $code => $name): ?>
                                        <option value="<?php echo $code; ?>" <?php echo ($match['t_code'] == $code) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($code); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </td>
                        <!-- Source + VOD -->
                        <td colspan="2">
                            <div class="metadata-group">
                                <label class="metadata-label">Source:</label>
                                <input type="url" name="source" class="form-control editor-input metadata-input" value="<?php echo htmlspecialchars($match['source'] ?? ''); ?>" maxlength="255" placeholder="Source URL">
                                <label class="metadata-label">VOD:</label>
                                <input type="url" name="vod" class="form-control editor-input metadata-input" value="<?php echo htmlspecialchars($match['vod'] ?? ''); ?>" maxlength="255" placeholder="VOD URL">
                            </div>
                        </td>
                        <!-- Status cell aligned with Actions column -->
                        <td class="status-cell">
                            <!-- Status messages will appear here -->
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Pagination Controls -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Matches pagination" class="mt-4">
            <ul class="pagination justify-content-center">
                <!-- Previous page -->
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">Previous</a>
                    </li>
                <?php else: ?>
                    <li class="page-item disabled">
                        <span class="page-link">Previous</span>
                    </li>
                <?php endif; ?>
                
                <!-- Page numbers -->
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">1</a>
                    </li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>"><?= $totalPages ?></a>
                    </li>
                <?php endif; ?>
                
                <!-- Next page -->
                <?php if ($page < $totalPages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next</a>
                    </li>
                <?php else: ?>
                    <li class="page-item disabled">
                        <span class="page-link">Next</span>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
        
        <!-- Results info -->
        <div class="text-center text-muted mt-2">
            Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalMatches) ?> of <?= $totalMatches ?> matches
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add New Match Modal -->
<div class="modal fade" id="addMatchModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-xl" role="document" style="max-width: 1200px;">
        <div class="modal-content add-match-modal">
            <div class="modal-header">
                <h4 class="modal-title">Add New Match</h4>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="addMatchForm" class="add-match-form">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Season *</label>
                                <input type="number" name="season" class="form-control form-control-lg" required min="1" value="<?php echo !empty($seasons) ? $seasons[0] : '9'; ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tournament Code</label>
                                <select name="t_code" class="form-control form-control-lg">
                                    <option value="">Select Code</option>
                                    <?php foreach ($tournamentCodes as $code => $name): ?>
                                        <option value="<?php echo $code; ?>" <?php echo ($code == 'A') ? 'selected' : ''; ?>><?php echo htmlspecialchars($name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Season Extra Info</label>
                                <input type="text" name="season_extra_info" class="form-control form-control-lg" maxlength="100" placeholder="Team League - Group Stage" value="Team League - Group Stage">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Best Of *</label>
                                <input type="number" name="best_of" class="form-control form-control-lg" required min="1" value="3">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <input type="text" name="notes" class="form-control form-control-lg" maxlength="255" placeholder="Code A: Last Fantasy, Tokamak">
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Player A</h6>
                            <div class="form-group">
                                <label>Player *</label>
                                <select name="player_a_id" class="form-control form-control-lg player-dropdown" required>
                                    <option value="">Select Player</option>
                                    <?php foreach ($players as $id => $name): ?>
                                        <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Race *</label>
                                <select name="player_a_race" class="form-control form-control-lg" required>
                                    <option value="">Select Race</option>
                                    <?php foreach ($races as $race => $name): ?>
                                        <option value="<?php echo $race; ?>"><?php echo htmlspecialchars($name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Score A *</label>
                                <input type="number" name="score_a" class="form-control form-control-lg" required min="0" value="2" onchange="updateModalWinnerIndicator()">
                            </div>
                            <div class="form-group">
                                <label>Team A</label>
                                <select name="team_a_id" class="form-control form-control-lg modal-team-dropdown">
                                    <option value="">—</option>
                                    <?php foreach ($teams as $tid => $tname): ?>
                                        <option value="<?php echo $tid; ?>"><?php echo htmlspecialchars($tname); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6>Player B</h6>
                            <div class="form-group">
                                <label>Player *</label>
                                <select name="player_b_id" class="form-control form-control-lg player-dropdown" required>
                                    <option value="">Select Player</option>
                                    <?php foreach ($players as $id => $name): ?>
                                        <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Race *</label>
                                <select name="player_b_race" class="form-control form-control-lg" required>
                                    <option value="">Select Race</option>
                                    <?php foreach ($races as $race => $name): ?>
                                        <option value="<?php echo $race; ?>"><?php echo htmlspecialchars($name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Score B *</label>
                                <input type="number" name="score_b" class="form-control form-control-lg" required min="0" value="1" onchange="updateModalWinnerIndicator()">
                            </div>
                            <div class="form-group">
                                <label>Team B</label>
                                <select name="team_b_id" class="form-control form-control-lg modal-team-dropdown">
                                    <option value="">—</option>
                                    <?php foreach ($teams as $tid => $tname): ?>
                                        <option value="<?php echo $tid; ?>"><?php echo htmlspecialchars($tname); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label id="modal-winner-indicator" style="color: #00d4ff; font-weight: bold; margin-top: 10px;"></label>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Source URL</label>
                                <input type="url" name="source" class="form-control form-control-lg" maxlength="255" placeholder="https://docs.google.com/..." value="https://www.">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>VOD URL</label>
                                <input type="url" name="vod" class="form-control form-control-lg" maxlength="255" placeholder="https://www.youtube.com/..." value="https://www.">
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="addNewMatch()">Add Match</button>
            </div>
        </div>
    </div>
</div>

<script>
    // Player ID -> Team ID for auto-fill when player selection changes
    var playerToTeam = <?php echo json_encode($playerToTeam); ?>;
    
    // Update winner indicator based on scores
    function updateWinnerIndicator(input) {
        const row = input.closest('tr');
        const matchId = row.getAttribute('data-match-id');
        const scoreA = parseInt(row.querySelector('input[name="score_a"]').value) || 0;
        const scoreB = parseInt(row.querySelector('input[name="score_b"]').value) || 0;
        const winnerCell = document.getElementById(`winner-display-${matchId}`);
        
        if (winnerCell) {
            const winnerText = winnerCell.querySelector('.winner-text');
            if (winnerText) {
                if (scoreA > scoreB) {
                    winnerText.textContent = 'A Wins';
                    winnerText.style.color = '#28a745';
                    winnerText.style.fontWeight = 'bold';
                } else if (scoreA < scoreB) {
                    winnerText.textContent = 'B Wins';
                    winnerText.style.color = '#28a745';
                    winnerText.style.fontWeight = 'bold';
                } else {
                    winnerText.textContent = 'Tie (A)';
                    winnerText.style.color = '#ffc107';
                    winnerText.style.fontWeight = 'bold';
                }
            }
        }
    }
    
    // Update winner indicator in modal
    function updateModalWinnerIndicator() {
        const form = document.getElementById('addMatchForm');
        const scoreA = parseInt(form.querySelector('input[name="score_a"]').value) || 0;
        const scoreB = parseInt(form.querySelector('input[name="score_b"]').value) || 0;
        const indicator = document.getElementById('modal-winner-indicator');
        
        if (scoreA > scoreB) {
            indicator.textContent = 'Player A Wins';
            indicator.style.color = '#28a745';
        } else if (scoreA < scoreB) {
            indicator.textContent = 'Player B Wins';
            indicator.style.color = '#28a745';
        } else {
            indicator.textContent = 'Tie (Player A wins by default)';
            indicator.style.color = '#ffc107';
        }
    }
    
    // When winner (Player A) or loser (Player B) changes, set team dropdown from player's current team
    document.getElementById('matches-table').addEventListener('change', function(e) {
        if (e.target.matches('select[name="player_a_id"]')) {
            var row = e.target.closest('tr');
            var matchId = row.getAttribute('data-match-id');
            var secondRow = document.querySelector('tr[data-match-id="' + matchId + '"].match-row-2');
            if (secondRow) {
                var teamSelect = secondRow.querySelector('select[name="winner_team_id"]');
                var pid = e.target.value;
                teamSelect.value = (playerToTeam[pid] !== undefined && playerToTeam[pid] !== '') ? String(playerToTeam[pid]) : '';
            }
        }
        if (e.target.matches('select[name="player_b_id"]')) {
            var row = e.target.closest('tr');
            var matchId = row.getAttribute('data-match-id');
            var secondRow = document.querySelector('tr[data-match-id="' + matchId + '"].match-row-2');
            if (secondRow) {
                var teamSelect = secondRow.querySelector('select[name="loser_team_id"]');
                var pid = e.target.value;
                teamSelect.value = (playerToTeam[pid] !== undefined && playerToTeam[pid] !== '') ? String(playerToTeam[pid]) : '';
            }
        }
    });
    
    // Initialize winner indicators on page load
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('input[name="score_a"], input[name="score_b"]').forEach(input => {
            updateWinnerIndicator(input);
        });
    });
    
    // Save match
    function saveMatch(button) {
        // Find the match row container
        const row = button.closest('tr');
        const matchId = row.getAttribute('data-match-id');
        
        // Find all rows with this match ID (there are 2 rows per match)
        const allMatchRows = document.querySelectorAll(`tr[data-match-id="${matchId}"]`);
        const firstRow = allMatchRows[0]; // Player entry row
        const secondRow = allMatchRows[1]; // Metadata row
        
        // Find status cell
        const statusCell = secondRow.querySelector('.status-cell');
        
        // Create status element
        const statusEl = document.createElement('div');
        statusEl.classList.add('status-message');
        if (statusCell) {
            statusCell.innerHTML = '';
            statusCell.appendChild(statusEl);
        }
        statusEl.textContent = 'Saving...';
        statusEl.style.color = '#00d4ff';
        
        // Collect form data from both rows
        const formData = new FormData();
        formData.append('action', 'update_match');
        formData.append('match_id', matchId);
        formData.append('player_a_id', firstRow.querySelector('select[name="player_a_id"]').value);
        formData.append('player_a_race', firstRow.querySelector('select[name="player_a_race"]').value);
        formData.append('score_a', firstRow.querySelector('input[name="score_a"]').value);
        formData.append('player_b_id', firstRow.querySelector('select[name="player_b_id"]').value);
        formData.append('player_b_race', firstRow.querySelector('select[name="player_b_race"]').value);
        formData.append('score_b', firstRow.querySelector('input[name="score_b"]').value);
        formData.append('best_of', firstRow.querySelector('select[name="best_of"]').value);
        formData.append('season', secondRow.querySelector('select[name="season"]').value);
        formData.append('season_extra_info', secondRow.querySelector('input[name="season_extra_info"]').value);
        formData.append('notes', secondRow.querySelector('input[name="notes"]').value);
        formData.append('t_code', secondRow.querySelector('select[name="t_code"]').value);
        formData.append('source', secondRow.querySelector('input[name="source"]').value);
        formData.append('vod', secondRow.querySelector('input[name="vod"]').value);
        formData.append('winner_team_id', secondRow.querySelector('select[name="winner_team_id"]').value);
        formData.append('loser_team_id', secondRow.querySelector('select[name="loser_team_id"]').value);
        
        // Send AJAX request
        fetch('edit_fsl_matches_handler.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                statusEl.textContent = '✓ Saved';
                statusEl.style.color = '#28a745';
                statusEl.style.fontWeight = 'bold';
            } else {
                statusEl.textContent = `Error: ${data.message}`;
                statusEl.style.color = '#dc3545';
                statusEl.style.fontWeight = 'bold';
            }
            
            setTimeout(() => {
                if (statusCell) {
                    statusCell.innerHTML = '';
                }
            }, 3000);
        })
        .catch(error => {
            console.error('Error:', error);
            statusEl.textContent = `Error: ${error.message}`;
            statusEl.style.color = '#dc3545';
            statusEl.style.fontWeight = 'bold';
            
            setTimeout(() => {
                if (statusCell) {
                    statusCell.innerHTML = '';
                }
            }, 3000);
        });
    }
    
    // Delete match
    function deleteMatch(button) {
        const row = button.closest('tr');
        const matchId = row.getAttribute('data-match-id');
        
        if (!confirm('Are you sure you want to delete this match? This action cannot be undone.')) {
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'delete_match');
        formData.append('match_id', matchId);
        
        fetch('edit_fsl_matches_handler.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                // Remove both rows for this match
                const allMatchRows = document.querySelectorAll(`tr[data-match-id="${matchId}"]`);
                allMatchRows.forEach(r => r.remove());
            } else {
                alert(`Error: ${data.message}`);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert(`Error: ${error.message}`);
        });
    }
    
    // Show add match form
    function showAddMatchForm() {
        $('#addMatchModal').modal('show');
    }
    
    // When Add Match modal player A/B changes, set Team A/B from player's current team
    var addMatchForm = document.getElementById('addMatchForm');
    if (addMatchForm) {
        addMatchForm.querySelector('select[name="player_a_id"]').addEventListener('change', function() {
            var tid = playerToTeam[this.value];
            addMatchForm.querySelector('select[name="team_a_id"]').value = (tid !== undefined && tid !== '') ? String(tid) : '';
        });
        addMatchForm.querySelector('select[name="player_b_id"]').addEventListener('change', function() {
            var tid = playerToTeam[this.value];
            addMatchForm.querySelector('select[name="team_b_id"]').value = (tid !== undefined && tid !== '') ? String(tid) : '';
        });
    }
    
    // Add new match
    function addNewMatch() {
        const form = document.getElementById('addMatchForm');
        const formData = new FormData(form);
        formData.append('action', 'add_match');
        
        // Validate required fields
        const requiredFields = ['season', 'player_a_id', 'player_a_race', 'player_b_id', 'player_b_race', 'best_of', 'score_a', 'score_b'];
        for (let field of requiredFields) {
            if (!formData.get(field)) {
                alert(`Please fill in the ${field.replace('_', ' ')} field.`);
                return;
            }
        }
        
        // Validate that Player A and Player B are different
        if (formData.get('player_a_id') === formData.get('player_b_id')) {
            alert('Player A and Player B must be different players.');
            return;
        }
        
        fetch('edit_fsl_matches_handler.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                $('#addMatchModal').modal('hide');
                window.location.reload();
            } else {
                alert(`Error: ${data.message}`);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert(`Error: ${error.message}`);
        });
    }
</script>

<style>
    body {
        font-family: 'Inter', sans-serif;
        background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
        color: #e0e0e0;
        margin: 0;
        padding: 0;
        line-height: 1.4;
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
    
    /* Container and layout improvements */
    .container-fluid {
        max-width: 95%;
        margin: 0 auto;
    }
    
    /* Table improvements - working with Bootstrap's dark theme */
    .table-dark {
        background-color: rgba(0, 0, 0, 0.2);
        color: #e0e0e0;
    }
    
    .table-dark th {
        background: rgba(0, 212, 255, 0.1);
        color: #00d4ff;
        font-weight: 600;
        text-align: center;
        padding: 8px 5px;
        border: 1px solid rgba(0, 212, 255, 0.2);
        font-size: 12px;
        white-space: nowrap;
    }
    
    .table-dark td {
        vertical-align: middle;
        padding: 5px 4px;
        border: 1px solid rgba(0, 212, 255, 0.2);
        background: rgba(0, 0, 0, 0.3);
        color: #e0e0e0;
    }
    
    .table-dark.table-striped tbody tr:nth-of-type(odd) {
        background-color: rgba(255, 255, 255, 0.05);
    }
    
    .table-dark.table-hover tbody tr:hover {
        background-color: rgba(255, 255, 255, 0.1);
    }
    
    .match-id-cell {
        text-align: center;
        font-weight: 600;
        background: rgba(0, 212, 255, 0.1);
        color: #00d4ff;
        font-size: 13px;
    }
    
    /* Match row styling for better visual separation */
    .match-row-1 {
        border-bottom: 1px solid rgba(0, 212, 255, 0.2);
    }
    
    .match-row-2 {
        border-bottom: 2px solid rgba(0, 212, 255, 0.4);
        margin-bottom: 8px;
    }
    
    /* Add spacing between match groups */
    .match-row-2:not(:last-of-type) {
        margin-bottom: 0;
    }
    
    /* Winner cell styling */
    .winner-cell {
        text-align: center;
        vertical-align: middle;
    }
    
    .winner-text {
        font-size: 13px;
        font-weight: bold;
    }
    
    /* Metadata group styling */
    .metadata-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    
    .metadata-label {
        font-size: 11px;
        color: #00d4ff;
        font-weight: 600;
        margin-bottom: 2px;
    }
    
    .metadata-input {
        width: 100%;
        font-size: 12px;
    }
    
    .status-cell {
        text-align: center;
        vertical-align: middle;
        font-size: 12px;
    }
    
    /* Form control improvements */
    .editor-input, .editor-select {
        font-size: 13px !important;
        padding: 6px 8px !important;
        height: 34px !important;
        border-radius: 4px !important;
        border: 1px solid rgba(0, 212, 255, 0.3) !important;
        width: 100% !important;
        background: rgba(0, 0, 0, 0.5) !important;
        color: #e0e0e0 !important;
        box-sizing: border-box !important;
    }
    
    /* Ensure select dropdowns show full text */
    .editor-select {
        overflow: visible;
    }
    
    .editor-select option {
        white-space: normal;
        padding: 4px;
    }
    
    .editor-input:focus, .editor-select:focus {
        border-color: #00d4ff !important;
        box-shadow: 0 0 10px rgba(0, 212, 255, 0.3) !important;
        background: rgba(0, 0, 0, 0.7) !important;
    }
    
    .editor-select {
        background: rgba(0, 0, 0, 0.5) !important;
        cursor: pointer !important;
    }
    
    /* Score input styling - compact */
    .score-input-group {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        flex-wrap: wrap;
    }
    
    .score-input {
        width: 42px;
        text-align: center;
        font-size: 13px;
        padding: 5px 3px;
        height: 32px;
        border: 1px solid rgba(0, 212, 255, 0.3);
        border-radius: 4px;
        background: rgba(0, 0, 0, 0.5);
        color: #e0e0e0;
        box-sizing: border-box;
    }
    
    .score-separator {
        font-weight: bold;
        color: #00d4ff;
        min-width: 8px;
        text-align: center;
        font-size: 14px;
    }
    
    .winner-indicator {
        font-size: 11px;
        font-weight: bold;
        white-space: nowrap;
        flex-basis: 100%;
        text-align: center;
        margin-top: 2px;
    }
    
    /* Action buttons */
    .action-buttons {
        display: flex;
        flex-direction: column;
        gap: 4px;
        align-items: center;
    }
    
    .btn-action {
        font-size: 12px;
        padding: 5px 10px;
        border-radius: 3px;
        font-weight: 500;
        min-width: 70px;
        width: 100%;
    }
    
    /* Status messages */
    .status-message {
        margin-top: 5px;
        font-weight: bold;
        font-size: 11px;
        text-align: center;
    }
    
    .text-success {
        color: #28a745;
    }
    
    .text-danger {
        color: #dc3545;
    }
    
    /* Row styling */
    .match-row {
        background: rgba(0, 0, 0, 0.3);
        color: #e0e0e0;
    }
    
    .match-row:hover {
        background: rgba(0, 212, 255, 0.1);
    }
    
    /* Ensure rowspan cells align properly */
    .match-id-cell {
        vertical-align: middle;
    }
    
    /* Ensure table doesn't break on wide screens */
    .table-responsive {
        overflow-x: auto;
    }
    
    /* Score input styling - compact */
    .score-input-group {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        flex-wrap: wrap;
    }
    
    .score-input {
        width: 42px;
        text-align: center;
        font-size: 13px;
        padding: 5px 3px;
        height: 32px;
    }
    
    .winner-indicator {
        font-size: 11px;
        font-weight: bold;
        white-space: nowrap;
        flex-basis: 100%;
        text-align: center;
        margin-top: 2px;
    }
    
    .table-striped tbody tr:nth-of-type(odd) {
        background: rgba(0, 212, 255, 0.05);
    }
    
    /* Modal improvements */
    .add-match-modal .modal-content {
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        background: rgba(0, 0, 0, 0.8);
        border: 1px solid rgba(0, 212, 255, 0.2);
    }
    
    .add-match-modal .modal-header {
        background: rgba(0, 212, 255, 0.1);
        color: #00d4ff;
        border-radius: 8px 8px 0 0;
        padding: 15px 20px;
        border-bottom: 1px solid rgba(0, 212, 255, 0.2);
    }
    
    .add-match-modal .modal-title {
        font-weight: 600;
        font-size: 1.3rem;
    }
    
    .add-match-modal .modal-body {
        padding: 25px;
        background: rgba(0, 0, 0, 0.8);
        color: #e0e0e0;
    }
    
    .add-match-form .form-group {
        margin-bottom: 20px;
    }
    
    .add-match-form label {
        font-weight: 600;
        color: #00d4ff;
        margin-bottom: 8px;
        font-size: 15px;
    }
    
    .add-match-form .form-control {
        font-size: 15px;
        padding: 12px 15px;
        border-radius: 5px;
        border: 1px solid rgba(0, 212, 255, 0.3);
        background: rgba(0, 0, 0, 0.5);
        color: #e0e0e0;
        min-height: 48px;
    }
    
    .add-match-form .form-control-lg {
        font-size: 16px;
        padding: 15px 18px;
        min-height: 52px;
        border-radius: 6px;
    }
    
    .add-match-form .player-dropdown {
        min-width: 280px;
        max-width: 100%;
    }
    
    .add-match-form .form-control:focus {
        border-color: #00d4ff;
        box-shadow: 0 0 10px rgba(0, 212, 255, 0.3);
        background: rgba(0, 0, 0, 0.7);
        color: #e0e0e0;
    }
    
    .add-match-form .form-control::placeholder {
        color: rgba(224, 224, 224, 0.6);
        font-size: 14px;
    }
    
    .add-match-form select.form-control {
        cursor: pointer;
        padding-right: 35px;
    }
    
    .add-match-form select.form-control option {
        padding: 8px 12px;
        font-size: 15px;
        color: #e0e0e0;
        background: rgba(0, 0, 0, 0.8);
    }
    
    .add-match-form h6 {
        color: #00d4ff;
        font-weight: 600;
        margin-bottom: 15px;
        font-size: 16px;
        border-bottom: 2px solid rgba(0, 212, 255, 0.3);
        padding-bottom: 5px;
    }
    
    .modal-footer {
        background: rgba(0, 0, 0, 0.8);
        border-radius: 0 0 8px 8px;
        padding: 15px 20px;
        border-top: 1px solid rgba(0, 212, 255, 0.2);
    }
    
    /* Ensure dropdowns have enough space */
    #addMatchModal .form-control {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    #addMatchModal select.form-control {
        white-space: normal;
    }
    
    #addMatchModal select option {
        white-space: normal;
        word-wrap: break-word;
    }
    
    /* Search and filter improvements */
    .mb-4 .row {
        align-items: end;
    }
    
    .form-control {
        font-size: 14px;
        background: rgba(0, 0, 0, 0.5);
        border: 1px solid rgba(0, 212, 255, 0.3);
        color: #e0e0e0;
    }
    
    .form-control:focus {
        background: rgba(0, 0, 0, 0.7);
        border-color: #00d4ff;
        color: #e0e0e0;
        box-shadow: 0 0 10px rgba(0, 212, 255, 0.3);
    }
    
    .form-control::placeholder {
        color: rgba(224, 224, 224, 0.6);
    }
    
    /* Button improvements */
    .btn {
        font-weight: 500;
    }
    
    .btn-success {
        background: #28a745;
        border: none;
        color: white;
    }
    
    .btn-danger {
        background: #dc3545;
        border: none;
        color: white;
    }
    
    .btn-primary {
        background: #00d4ff;
        border: none;
        color: #0f0c29;
    }
    
    .btn-secondary {
        background: #6c757d;
        border: none;
        color: white;
    }
    
    /* Pagination styling */
    .pagination {
        margin: 0;
    }
    
    .page-link {
        background-color: rgba(0, 0, 0, 0.5);
        border-color: rgba(0, 212, 255, 0.3);
        color: #00d4ff;
        padding: 8px 12px;
        margin: 0 2px;
        border-radius: 5px;
        transition: all 0.3s ease;
    }
    
    .page-link:hover {
        background-color: rgba(0, 212, 255, 0.2);
        border-color: #00d4ff;
        color: #ffffff;
        text-decoration: none;
    }
    
    .page-item.active .page-link {
        background-color: #00d4ff;
        border-color: #00d4ff;
        color: #0f0c29;
        font-weight: bold;
    }
    
    .page-item.disabled .page-link {
        background-color: rgba(0, 0, 0, 0.3);
        border-color: rgba(0, 212, 255, 0.1);
        color: rgba(0, 212, 255, 0.5);
        cursor: not-allowed;
    }
    
    .text-muted {
        color: rgba(224, 224, 224, 0.6) !important;
    }
    
    /* Responsive improvements */
    @media (max-width: 1200px) {
        .container-fluid {
            max-width: 100%;
            padding: 10px;
        }
        
        .matches-editor-table {
            font-size: 12px;
        }
        
        .editor-input, .editor-select {
            font-size: 12px;
            padding: 4px 6px;
        }
        
        /* Ensure table doesn't overflow on smaller screens */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
    }
    
    @media (max-width: 768px) {
        .container-fluid {
            padding: 5px;
        }
        
        .matches-editor-table {
            font-size: 11px;
        }
        
        .editor-input, .editor-select {
            font-size: 11px;
            padding: 3px 5px;
        }
        
        .score-input {
            width: 40px;
            font-size: 11px;
        }
        
        .btn-action {
            font-size: 11px;
            padding: 3px 6px;
            min-width: 50px;
        }
    }
</style>

<?php include 'includes/footer.php'; ?> 