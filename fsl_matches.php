<?php
/**
 * FSL Matches Page
 * Displays all FSL matches with sorting functionality
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Include database connection
require_once 'includes/db.php';

// Connect to database
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get URL parameters for sorting
$sortField = isset($_GET['sort']) ? htmlspecialchars($_GET['sort']) : 'fsl_match_id';
$sortDirection = isset($_GET['dir']) ? htmlspecialchars($_GET['dir']) : 'desc';

// Get URL parameters for filtering
$playerFilter = isset($_GET['player']) ? htmlspecialchars($_GET['player']) : '';
$seasonFilter = isset($_GET['season']) ? htmlspecialchars($_GET['season']) : 'all';
$raceFilter = isset($_GET['race']) ? htmlspecialchars($_GET['race']) : 'all';

// Validate sort field
$validSortFields = ['fsl_match_id', 'season', 'winner_name', 'loser_name', 'winner_race', 'loser_race', 'map_win', 'map_loss'];
if (!in_array($sortField, $validSortFields)) {
    $sortField = 'fsl_match_id';
}

// Build the ORDER BY clause
$orderBy = "fm.$sortField $sortDirection";
if ($sortField === 'winner_name') {
    $orderBy = "p_w.Real_Name $sortDirection";
} elseif ($sortField === 'loser_name') {
    $orderBy = "p_l.Real_Name $sortDirection";
}

// Get FSL matches
$query = "SELECT 
    fm.fsl_match_id,
    fm.season,
    fm.season_extra_info,
    fm.notes,
    fm.t_code,
    fm.best_of,
    fm.map_win,
    fm.map_loss,
    fm.winner_race,
    fm.loser_race,
    fm.source,
    fm.vod,
    p_w.Real_Name AS winner_name,
    pa_w.Alias_Name AS winner_alias,
    p_l.Real_Name AS loser_name,
    pa_l.Alias_Name AS loser_alias
FROM fsl_matches fm
JOIN Players p_w 
    ON fm.winner_player_id = p_w.Player_ID
JOIN Players p_l 
    ON fm.loser_player_id = p_l.Player_ID
LEFT JOIN users u_w 
    ON p_w.User_ID = u_w.id
LEFT JOIN users u_l 
    ON p_l.User_ID = u_l.id
LEFT JOIN Player_Aliases pa_w 
    ON p_w.Player_ID = pa_w.Player_ID
LEFT JOIN Player_Aliases pa_l 
    ON p_l.Player_ID = pa_l.Player_ID
ORDER BY $orderBy, fm.fsl_match_id";

try {
    $matches = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Query failed: " . $e->getMessage());
}

// Set page title
$pageTitle = "FSL Matches";

// Include header
include 'includes/header.php';

// Function to get sort URL
function getSortUrl($field) {
    global $sortField, $sortDirection;
    $newDirection = ($field === $sortField && $sortDirection === 'asc') ? 'desc' : 'asc';
    return "?sort={$field}&dir={$newDirection}";
}

// Function to get sort class
function getSortClass($field) {
    global $sortField, $sortDirection;
    return ($field === $sortField) ? $sortDirection : '';
}

// Add a helper function to extract domain name from URL
function getDomainFromUrl($url) {
    if (empty($url)) return '';
    
    // Parse the URL to get the host
    $parsedUrl = parse_url($url);
    
    if (isset($parsedUrl['host'])) {
        // Remove 'www.' if present
        $domain = preg_replace('/^www\./', '', $parsedUrl['host']);
        // Capitalize the first letter
        return ucfirst($domain);
    }
    
    return 'Link'; // Fallback
}
?>

<div class="container mt-4">
    <h1>FSL Matches</h1>
    
    <div class="stats-container">
        <div class="filters">
            <div class="filter-group">
                <label for="season-filter">Season:</label>
                <select id="season-filter">
                    <option value="all" <?= $seasonFilter === 'all' ? 'selected' : '' ?>>All Seasons</option>
                    <?php
                    // Get unique seasons
                    $seasons = [];
                    foreach ($matches as $match) {
                        if (!empty($match['season']) && !in_array($match['season'], $seasons)) {
                            $seasons[] = $match['season'];
                        }
                    }
                    sort($seasons);
                    foreach ($seasons as $season) {
                        $selected = ($seasonFilter === $season) ? 'selected' : '';
                        echo '<option value="' . htmlspecialchars($season) . '" ' . $selected . '>' . htmlspecialchars($season) . '</option>';
                    }
                    ?>
                </select>
            </div>
            <div class="filter-group">
                <label for="race-filter">Race:</label>
                <select id="race-filter">
                    <option value="all" <?= $raceFilter === 'all' ? 'selected' : '' ?>>All Races</option>
                    <option value="T" <?= $raceFilter === 'T' ? 'selected' : '' ?>>Terran</option>
                    <option value="P" <?= $raceFilter === 'P' ? 'selected' : '' ?>>Protoss</option>
                    <option value="Z" <?= $raceFilter === 'Z' ? 'selected' : '' ?>>Zerg</option>
                    <option value="R" <?= $raceFilter === 'R' ? 'selected' : '' ?>>Random</option>
                </select>
            </div>
            <div class="filter-group">
                <label for="player-search">Search Player:</label>
                <input type="text" id="player-search" placeholder="Enter player name..." value="<?= $playerFilter ?>">
            </div>
            <div class="filter-actions">
                <button id="clear-filters" class="btn btn-clear">Clear Filters</button>
            </div>
        </div>
        
        <div class="table-responsive">
            <table id="fsl-matches-table">
                <thead>
                    <tr>
                        <th class="sortable <?= getSortClass('fsl_match_id') ?>" data-sort="fsl_match_id">
                            <a href="<?= getSortUrl('fsl_match_id') ?>">ID</a>
                        </th>
                        <th class="sortable <?= getSortClass('season') ?>" data-sort="season">
                            <a href="<?= getSortUrl('season') ?>">Season</a>
                        </th>
                        <th>Extra Info</th>
                        <th>Notes</th>
                        <th>Tournament Code</th>
                        <th>Best Of</th>
                        <th class="sortable <?= getSortClass('winner_name') ?>" data-sort="winner_name">
                            <a href="<?= getSortUrl('winner_name') ?>">Winner</a>
                        </th>
                        <th>Winner Alias</th>
                        <th class="sortable <?= getSortClass('winner_race') ?>" data-sort="winner_race">
                            <a href="<?= getSortUrl('winner_race') ?>">Winner Race</a>
                        </th>
                        <th class="sortable <?= getSortClass('loser_name') ?>" data-sort="loser_name">
                            <a href="<?= getSortUrl('loser_name') ?>">Loser</a>
                        </th>
                        <th>Loser Alias</th>
                        <th class="sortable <?= getSortClass('loser_race') ?>" data-sort="loser_race">
                            <a href="<?= getSortUrl('loser_race') ?>">Loser Race</a>
                        </th>
                        <th class="sortable <?= getSortClass('map_win') ?>" data-sort="map_win">
                            <a href="<?= getSortUrl('map_win') ?>">Score</a>
                        </th>
                        <th>Source</th>
                        <th>VOD</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($matches as $match): ?>
                        <tr>
                            <td><a href="view_match.php?id=<?= urlencode($match['fsl_match_id']) ?>" class="match-id-link"><?= htmlspecialchars($match['fsl_match_id']) ?></a></td>
                            <td><?= htmlspecialchars($match['season']) ?></td>
                            <td><?= htmlspecialchars($match['season_extra_info'] ?? '') ?></td>
                            <td><?= htmlspecialchars($match['notes'] ?? '') ?></td>
                            <td><?= htmlspecialchars($match['t_code'] ?? '') ?></td>
                            <td><?= htmlspecialchars($match['best_of']) ?></td>
                            <td><a href="view_player.php?name=<?= urlencode($match['winner_name']) ?>" class="player-link"><?= htmlspecialchars($match['winner_name']) ?></a></td>
                            <td><?= htmlspecialchars($match['winner_alias'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($match['winner_race']) ?></td>
                            <td><a href="view_player.php?name=<?= urlencode($match['loser_name']) ?>" class="player-link"><?= htmlspecialchars($match['loser_name']) ?></a></td>
                            <td><?= htmlspecialchars($match['loser_alias'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($match['loser_race']) ?></td>
                            <td><?= $match['map_win'] . '-' . $match['map_loss'] ?></td>
                            <td>
                                <?php if (!empty($match['source'])): ?>
                                    <a href="<?= htmlspecialchars($match['source']) ?>" target="_blank" class="match-link">
                                        <?= htmlspecialchars(getDomainFromUrl($match['source'])) ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($match['vod'])): ?>
                                    <a href="<?= htmlspecialchars($match['vod']) ?>" target="_blank" class="match-link">
                                        <?= htmlspecialchars(getDomainFromUrl($match['vod'])) ?>
                                    </a>
                                <?php endif; ?>
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
        max-width: 100%;
        margin: 20px auto;
        padding: 20px;
        overflow-x: auto;
    }
    
    h1 {
        text-align: center;
        color: #00d4ff;
        text-shadow: 0 0 15px #00d4ff;
        font-size: 2.4em;
        margin-bottom: 30px;
    }
    
    #fsl-matches-table {
        width: 100%;
        border-collapse: collapse;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        overflow: hidden;
        margin-bottom: 20px;
    }
    
    #fsl-matches-table th,
    #fsl-matches-table td {
        padding: 12px 15px;
        text-align: left;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    #fsl-matches-table th {
        background: rgba(0, 0, 0, 0.2);
        color: #00d4ff;
        font-weight: 600;
    }
    
    #fsl-matches-table th a {
        color: #00d4ff;
        text-decoration: none;
    }
    
    #fsl-matches-table th a:hover {
        color: #ffffff;
    }
    
    #fsl-matches-table tr:hover {
        background: rgba(0, 212, 255, 0.1);
    }
    
    .sortable {
        cursor: pointer;
        position: relative;
    }
    
    .sortable.asc::after {
        content: ' ↑';
        color: #00d4ff;
    }
    
    .sortable.desc::after {
        content: ' ↓';
        color: #00d4ff;
    }
    
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin-bottom: 1rem;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.4);
        border-radius: 10px;
    }
    
    @media (max-width: 768px) {
        .container {
            padding: 10px;
        }
        
        #fsl-matches-table th,
        #fsl-matches-table td {
            padding: 8px 10px;
            font-size: 14px;
        }
    }
    
    .match-link {
        color: #00d4ff;
        text-decoration: none;
        padding: 4px 8px;
        border: 1px solid #00d4ff;
        border-radius: 4px;
        transition: all 0.3s ease;
    }
    
    .match-link:hover {
        background: #00d4ff;
        color: #0f0c29;
        text-decoration: none;
    }
    
    .filters {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 20px;
        padding: 15px;
        background: rgba(0, 0, 0, 0.2);
        border-radius: 8px;
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
    }
    
    .filter-group label {
        margin-bottom: 5px;
        font-weight: 600;
        color: #00d4ff;
    }
    
    .filter-group select,
    .filter-group input {
        padding: 8px 12px;
        border-radius: 4px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        background: rgba(0, 0, 0, 0.3);
        color: #e0e0e0;
    }
    
    .filter-actions {
        display: flex;
        align-items: flex-end;
        gap: 10px;
    }
    
    .btn {
        padding: 8px 16px;
        border-radius: 4px;
        border: none;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    
    .btn-clear {
        background-color: #ff6f61;
        color: white;
    }
    
    .btn-clear:hover {
        background-color: #ff5a4a;
    }
    
    .stats-container {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 25px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.4);
    }
    
    @media (max-width: 768px) {
        .filters {
            flex-direction: column;
        }
    }
    
    .player-link {
        color: #00d4ff;
        text-decoration: none;
        transition: all 0.3s ease;
    }
    
    .player-link:hover {
        color: #ffffff;
        text-shadow: 0 0 5px #00d4ff;
    }

    .match-id-link {
        color: #00d4ff;
        text-decoration: none;
        transition: all 0.3s ease;
    }
    
    .match-id-link:hover {
        color: #ffffff;
        text-shadow: 0 0 5px #00d4ff;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Filter functions
        const seasonFilter = document.getElementById('season-filter');
        const raceFilter = document.getElementById('race-filter');
        const playerSearch = document.getElementById('player-search');
        const clearFiltersBtn = document.getElementById('clear-filters');
        const matchRows = document.querySelectorAll('#fsl-matches-table tbody tr');
        
        function applyFilters() {
            const seasonValue = seasonFilter.value;
            const raceValue = raceFilter.value;
            const searchValue = playerSearch.value.toLowerCase();
            
            matchRows.forEach(row => {
                const season = row.children[1].textContent; // Season column
                const winnerName = row.children[6].textContent.toLowerCase(); // Winner column
                const winnerAlias = row.children[7].textContent.toLowerCase(); // Winner Alias column
                const winnerRace = row.children[8].textContent; // Winner Race column
                const loserName = row.children[9].textContent.toLowerCase(); // Loser column
                const loserAlias = row.children[10].textContent.toLowerCase(); // Loser Alias column
                const loserRace = row.children[11].textContent; // Loser Race column
                
                const seasonMatch = seasonValue === 'all' || season === seasonValue;
                const raceMatch = raceValue === 'all' || winnerRace === raceValue || loserRace === raceValue;
                const searchMatch = searchValue === '' || 
                    winnerName.includes(searchValue) || 
                    winnerAlias.includes(searchValue) || 
                    loserName.includes(searchValue) || 
                    loserAlias.includes(searchValue);
                
                if (seasonMatch && raceMatch && searchMatch) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
            
            updateURL();
        }
        
        function updateURL() {
            const url = new URL(window.location);
            
            // Clear existing filter parameters
            url.searchParams.delete('player');
            url.searchParams.delete('season');
            url.searchParams.delete('race');
            
            // Add current filter values
            if (playerSearch.value) {
                url.searchParams.set('player', playerSearch.value);
            }
            
            if (seasonFilter.value !== 'all') {
                url.searchParams.set('season', seasonFilter.value);
            }
            
            if (raceFilter.value !== 'all') {
                url.searchParams.set('race', raceFilter.value);
            }
            
            // Preserve sort parameters
            const currentSort = url.searchParams.get('sort');
            const currentDir = url.searchParams.get('dir');
            
            if (currentSort) {
                url.searchParams.set('sort', currentSort);
            }
            
            if (currentDir) {
                url.searchParams.set('dir', currentDir);
            }
            
            window.history.replaceState({}, '', url);
        }
        
        function clearFilters() {
            seasonFilter.value = 'all';
            raceFilter.value = 'all';
            playerSearch.value = '';
            
            const url = new URL(window.location);
            const currentSort = url.searchParams.get('sort');
            const currentDir = url.searchParams.get('dir');
            
            url.search = '';
            
            if (currentSort) {
                url.searchParams.set('sort', currentSort);
            }
            
            if (currentDir) {
                url.searchParams.set('dir', currentDir);
            }
            
            window.location.href = url.toString();
        }
        
        // Add event listeners
        seasonFilter.addEventListener('change', applyFilters);
        raceFilter.addEventListener('change', applyFilters);
        playerSearch.addEventListener('input', applyFilters);
        clearFiltersBtn.addEventListener('click', clearFilters);
        
        // Initial filter application
        applyFilters();
    });
</script>

<?php include 'includes/footer.php'; ?> 