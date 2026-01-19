<?php
/**
 * Enhanced Player Statistics Editor (eps.php)
 * A clean, efficient implementation for editing player statistics
 * that properly handles composite keys and relationships
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
$required_permission = 'edit player, team, stats';
include 'includes/check_permission_updated.php';

// Connect to database
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Function to get dropdown options
function getDropdownOptions($db, $table, $valueColumn, $textColumn) {
    $query = "SELECT DISTINCT $valueColumn, $textColumn FROM $table ORDER BY $textColumn";
    $stmt = $db->query($query);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Get dropdown options
$teams = getDropdownOptions($db, 'Teams', 'Team_ID', 'Team_Name');
$teams = ['' => 'No Team'] + $teams; // Add empty option

// Get races
$races = [
    'P' => 'Protoss',
    'T' => 'Terran',
    'Z' => 'Zerg',
    'R' => 'Random'
];

// Get divisions
$divisions = [
    'A' => 'A',
    'B' => 'B',
    'C' => 'C',
    'S' => 'S'
];

// Process search query and pagination
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

$searchCondition = '';
$params = [];

if (!empty($searchQuery)) {
    $searchCondition = "WHERE p.Real_Name LIKE :search OR pa.Alias_Name LIKE :search";
    $params[':search'] = "%{$searchQuery}%";
}

// Get total count for pagination
$countQuery = "
    SELECT COUNT(DISTINCT p.Player_ID) as total
    FROM Players p
    LEFT JOIN Player_Aliases pa ON p.Player_ID = pa.Player_ID
    $searchCondition
";

$countStmt = $db->prepare($countQuery);
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalPlayers = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalPlayers / $perPage);

// Get players with their aliases and statistics (paginated)
$query = "
    SELECT 
        p.Player_ID,
        p.Real_Name,
        p.Team_ID,
        p.Championship_Record,
        p.TeamLeague_Championship_Record,
        p.Teams_History,
        pa.Alias_ID,
        pa.Alias_Name,
        fs.Division,
        fs.Race,
        fs.MapsW,
        fs.MapsL,
        fs.SetsW,
        fs.SetsL,
        t.Team_Name,
        p.Status
    FROM 
        Players p
    LEFT JOIN 
        Player_Aliases pa ON p.Player_ID = pa.Player_ID
    LEFT JOIN 
        FSL_STATISTICS fs ON p.Player_ID = fs.Player_ID AND pa.Alias_ID = fs.Alias_ID
    LEFT JOIN 
        Teams t ON p.Team_ID = t.Team_ID
    $searchCondition
    ORDER BY 
        p.Real_Name, pa.Alias_Name
    LIMIT $perPage OFFSET $offset
";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group results by player
$players = [];
foreach ($results as $row) {
    $playerId = $row['Player_ID'];
    
    if (!isset($players[$playerId])) {
        $players[$playerId] = [
            'Player_ID' => $playerId,
            'Real_Name' => $row['Real_Name'],
            'Team_ID' => $row['Team_ID'],
            'Team_Name' => $row['Team_Name'],
            'Championship_Record' => $row['Championship_Record'],
            'TeamLeague_Championship_Record' => $row['TeamLeague_Championship_Record'],
            'Teams_History' => $row['Teams_History'],
            'aliases' => [],
            'Status' => $row['Status']
        ];
    }
    
    if (!empty($row['Alias_ID'])) {
        $aliasId = $row['Alias_ID'];
        
        if (!isset($players[$playerId]['aliases'][$aliasId])) {
            $players[$playerId]['aliases'][$aliasId] = [
                'Alias_ID' => $aliasId,
                'Alias_Name' => $row['Alias_Name'],
                'stats' => []
            ];
        }
        
        if (!empty($row['Division']) && !empty($row['Race'])) {
            $statKey = $row['Division'] . '-' . $row['Race'];
            $players[$playerId]['aliases'][$aliasId]['stats'][$statKey] = [
                'Division' => $row['Division'],
                'Race' => $row['Race'],
                'MapsW' => $row['MapsW'],
                'MapsL' => $row['MapsL'],
                'SetsW' => $row['SetsW'],
                'SetsL' => $row['SetsL']
            ];
        }
    }
}

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <div class="admin-header">
        <h1><i class="fas fa-chart-bar"></i> Enhanced Player Statistics Editor</h1>
        <div class="admin-user-info">
            <span>Logged in as: <?= htmlspecialchars($_SESSION['username'] ?? 'Unknown') ?></span>
            <a href="logout.php" class="btn btn-logout">Logout</a>
        </div>
    </div>
    
    <!-- Search Form -->
    <form method="GET" action="" class="mb-4">
        <div class="input-group">
            <input type="text" name="search" class="form-control" placeholder="Search by player name or alias" value="<?php echo htmlspecialchars($searchQuery); ?>">
            <div class="input-group-append">
                <button type="submit" class="btn btn-primary">Search</button>
                <a href="eps.php" class="btn btn-secondary">Clear</a>
            </div>
        </div>
    </form>
    
    <!-- Player List -->
    <div class="table-responsive">
        <table id="player-stats-table" class="table table-striped table-bordered">
            <thead class="thead-dark">
                <tr>
                    <th>Player Name</th>
                    <th>Team</th>
                    <th>Alias</th>
                    <th>Division</th>
                    <th>Race</th>
                    <th>Maps W/L</th>
                    <th>Sets W/L</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($players as $player): ?>
                    <?php 
                    $hasAliases = !empty($player['aliases']);
                    $aliasCount = count($player['aliases']);
                    $rowspan = max(1, $aliasCount);
                    
                    // Calculate total stats for all aliases
                    $totalMapsW = 0;
                    $totalMapsL = 0;
                    $totalSetsW = 0;
                    $totalSetsL = 0;
                    
                    foreach ($player['aliases'] as $alias) {
                        foreach ($alias['stats'] as $stat) {
                            $totalMapsW += $stat['MapsW'];
                            $totalMapsL += $stat['MapsL'];
                            $totalSetsW += $stat['SetsW'];
                            $totalSetsL += $stat['SetsL'];
                        }
                    }
                    ?>
                    
                    <!-- Player row -->
                    <tr class="player-row" data-player-id="<?php echo $player['Player_ID']; ?>"<?php if ($hasAliases) { $firstAlias = array_values($player['aliases'])[0]; echo ' data-alias-id="' . $firstAlias['Alias_ID'] . '"'; } ?>>
                        <td rowspan="<?php echo $rowspan; ?>">
                            <input type="text" name="real_name" class="form-control" value="<?php echo htmlspecialchars($player['Real_Name']); ?>">
                            <button type="button" class="btn btn-sm btn-info mt-2" onclick="addNewAlias(this)">Add Alias</button>
                        </td>
                        <td rowspan="<?php echo $rowspan; ?>">
                            <select name="team_id" class="form-control">
                                <?php foreach ($teams as $id => $name): ?>
                                    <option value="<?php echo $id; ?>" <?php echo ($player['Team_ID'] == $id) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select name="status" class="form-control">
                                <option value="active" <?php echo ($player['Status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($player['Status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                <option value="banned" <?php echo ($player['Status'] == 'banned') ? 'selected' : ''; ?>>Banned</option>
                                <option value="other" <?php echo ($player['Status'] == 'other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </td>
                        
                        <?php if ($hasAliases): ?>
                            <?php $firstAlias = true; ?>
                            <?php foreach ($player['aliases'] as $alias): ?>
                                <?php if (!$firstAlias): ?>
                                    <tr class="alias-row" data-player-id="<?php echo $player['Player_ID']; ?>" data-alias-id="<?php echo $alias['Alias_ID']; ?>">
                                <?php endif; ?>
                                
                                <td>
                                    <input type="text" name="alias_name" class="form-control" value="<?php echo htmlspecialchars($alias['Alias_Name']); ?>" readonly>
                                    <input type="hidden" name="alias_id" value="<?php echo $alias['Alias_ID']; ?>">
                                </td>
                                
                                <?php if (!empty($alias['stats'])): ?>
                                    <?php $firstStat = true; ?>
                                    <?php foreach ($alias['stats'] as $stat): ?>
                                        <?php if (!$firstStat): ?>
                                            </tr>
                                            <tr class="stat-row" data-player-id="<?php echo $player['Player_ID']; ?>" data-alias-id="<?php echo $alias['Alias_ID']; ?>">
                                                <td colspan="2"></td> <!-- Empty cells for player and team -->
                                                <td></td> <!-- Empty cell for alias -->
                                        <?php endif; ?>
                                        
                                        <td>
                                            <select name="division" class="form-control">
                                                <?php foreach ($divisions as $id => $name): ?>
                                                    <option value="<?php echo $id; ?>" <?php echo ($stat['Division'] == $id) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($name); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="race" class="form-control">
                                                <?php foreach ($races as $id => $name): ?>
                                                    <option value="<?php echo $id; ?>" <?php echo ($stat['Race'] == $id) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($name); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <div class="input-group">
                                                <input type="number" name="maps_w" class="form-control" value="<?php echo $stat['MapsW']; ?>" min="0">
                                                <div class="input-group-prepend input-group-append">
                                                    <span class="input-group-text">/</span>
                                                </div>
                                                <input type="number" name="maps_l" class="form-control" value="<?php echo $stat['MapsL']; ?>" min="0">
                                            </div>
                                        </td>
                                        <td>
                                            <div class="input-group">
                                                <input type="number" name="sets_w" class="form-control" value="<?php echo $stat['SetsW']; ?>" min="0">
                                                <div class="input-group-prepend input-group-append">
                                                    <span class="input-group-text">/</span>
                                                </div>
                                                <input type="number" name="sets_l" class="form-control" value="<?php echo $stat['SetsL']; ?>" min="0">
                                            </div>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-primary btn-save" onclick="saveStatistics(this)">Save</button>
                                        </td>
                                        
                                        <?php $firstStat = false; ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <!-- No stats for this alias, show empty form -->
                                    <td>
                                        <select name="division" class="form-control">
                                            <option value="">Select Division</option>
                                            <?php foreach ($divisions as $id => $name): ?>
                                                <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="race" class="form-control">
                                            <option value="">Select Race</option>
                                            <?php foreach ($races as $id => $name): ?>
                                                <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <div class="input-group">
                                            <input type="number" name="maps_w" class="form-control" value="0" min="0">
                                            <div class="input-group-prepend input-group-append">
                                                <span class="input-group-text">/</span>
                                            </div>
                                            <input type="number" name="maps_l" class="form-control" value="0" min="0">
                                        </div>
                                    </td>
                                    <td>
                                        <div class="input-group">
                                            <input type="number" name="sets_w" class="form-control" value="0" min="0">
                                            <div class="input-group-prepend input-group-append">
                                                <span class="input-group-text">/</span>
                                            </div>
                                            <input type="number" name="sets_l" class="form-control" value="0" min="0">
                                        </div>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-primary btn-save" onclick="saveStatistics(this)">Save</button>
                                    </td>
                                <?php endif; ?>
                                
                                <?php $firstAlias = false; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <!-- No aliases for this player -->
                            <td colspan="6" class="text-center">
                                <p>No aliases found for this player. Please add an alias first.</p>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                
                <!-- Add new player form -->
                <tr class="new-player-row">
                    <td>
                        <input type="text" name="real_name" class="form-control" placeholder="New Player Name">
                    </td>
                    <td>
                        <select name="team_id" class="form-control">
                            <?php foreach ($teams as $id => $name): ?>
                                <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="text" name="alias_name" class="form-control" placeholder="New Alias">
                    </td>
                    <td>
                        <select name="division" class="form-control">
                            <option value="">Select Division</option>
                            <?php foreach ($divisions as $id => $name): ?>
                                <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="race" class="form-control">
                            <option value="">Select Race</option>
                            <?php foreach ($races as $id => $name): ?>
                                <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <div class="input-group">
                            <input type="number" name="maps_w" class="form-control" value="0" min="0">
                            <div class="input-group-prepend input-group-append">
                                <span class="input-group-text">/</span>
                            </div>
                            <input type="number" name="maps_l" class="form-control" value="0" min="0">
                        </div>
                    </td>
                    <td>
                        <div class="input-group">
                            <input type="number" name="sets_w" class="form-control" value="0" min="0">
                            <div class="input-group-prepend input-group-append">
                                <span class="input-group-text">/</span>
                            </div>
                            <input type="number" name="sets_l" class="form-control" value="0" min="0">
                        </div>
                    </td>
                    <td>
                        <button type="button" class="btn btn-success" onclick="addNewPlayer(this)">Add Player</button>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <!-- Pagination Controls -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Players pagination" class="mt-4">
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
            Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalPlayers) ?> of <?= $totalPlayers ?> players
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // Save player statistics
    function saveStatistics(button) {
        const row = button.closest('tr');
        const playerId = row.getAttribute('data-player-id');
        let aliasId = row.getAttribute('data-alias-id');
        
        // If no alias ID in data attribute, try to find it in a hidden input field
        if (!aliasId) {
            const aliasInput = row.querySelector('input[name="alias_id"]');
            if (aliasInput) {
                aliasId = aliasInput.value;
            }
        }
        
        if (!playerId) {
            alert('Player ID is required');
            return;
        }
        
        // Create status element
        const statusEl = document.createElement('div');
        statusEl.classList.add('status-message');
        row.querySelector('td:last-child').appendChild(statusEl);
        statusEl.textContent = 'Saving...';
        
        // Collect form data
        const formData = new FormData();
        formData.append('action', 'update_statistics');
        formData.append('player_id', playerId);
        formData.append('real_name', row.querySelector('input[name="real_name"]').value);
        formData.append('team_id', row.querySelector('select[name="team_id"]').value);
        formData.append('status', row.querySelector('select[name="status"]').value);
        
        // Add statistics data if we have an alias_id (meaning this is a statistics row)
        if (aliasId) {
            formData.append('alias_id', aliasId);
            formData.append('division', row.querySelector('select[name="division"]').value);
            formData.append('race', row.querySelector('select[name="race"]').value);
            formData.append('maps_w', row.querySelector('input[name="maps_w"]').value);
            formData.append('maps_l', row.querySelector('input[name="maps_l"]').value);
            formData.append('sets_w', row.querySelector('input[name="sets_w"]').value);
            formData.append('sets_l', row.querySelector('input[name="sets_l"]').value);
        }
        
        // Log what we're saving
        console.log(`Saving player data for player ID: ${playerId}`);
        console.log(`Alias ID found: ${aliasId}`);
        console.log(`Real name: ${formData.get('real_name')}`);
        console.log(`Team ID: ${formData.get('team_id')}`);
        console.log(`Status: ${formData.get('status')}`);
        if (aliasId) {
            console.log(`--- Statistics data ---`);
            console.log(`Division: ${formData.get('division')}`);
            console.log(`Race: ${formData.get('race')}`);
            console.log(`Maps: ${formData.get('maps_w')}/${formData.get('maps_l')}`);
            console.log(`Sets: ${formData.get('sets_w')}/${formData.get('sets_l')}`);
            console.log(`--- End statistics data ---`);
        } else {
            console.log(`No alias ID found - statistics data will not be sent`);
        }
        
        // Send AJAX request
        fetch('eps_handler.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log(`Response status: ${response.status}`);
            
            if (!response.ok) {
                return response.text().then(text => {
                    throw new Error(`HTTP error ${response.status}: ${text}`);
                });
            }
            
            return response.json();
        })
        .then(data => {
            console.log('Response data:', data);
            
            if (data.status === 'success') {
                statusEl.textContent = 'Saved successfully';
                statusEl.classList.add('text-success');
            } else {
                statusEl.textContent = `Error: ${data.message}`;
                statusEl.classList.add('text-danger');
            }
            
            // Remove status after 3 seconds
            setTimeout(() => {
                statusEl.remove();
            }, 3000);
        })
        .catch(error => {
            console.error('Error:', error);
            statusEl.textContent = `Error: ${error.message}`;
            statusEl.classList.add('text-danger');
            
            // Remove status after 3 seconds
            setTimeout(() => {
                statusEl.remove();
            }, 3000);
        });
    }
    
    // Add new player
    function addNewPlayer(button) {
        const row = button.closest('tr');
        
        // Validate required fields
        const realName = row.querySelector('input[name="real_name"]').value.trim();
        const aliasName = row.querySelector('input[name="alias_name"]').value.trim();
        
        if (!realName || !aliasName) {
            alert('Player name and alias are required');
            return;
        }
        
        // Create status element
        const statusEl = document.createElement('div');
        statusEl.classList.add('status-message');
        row.querySelector('td:last-child').appendChild(statusEl);
        statusEl.textContent = 'Adding player...';
        
        // Collect form data
        const formData = new FormData();
        formData.append('action', 'add_player');
        formData.append('real_name', realName);
        formData.append('team_id', row.querySelector('select[name="team_id"]').value);
        formData.append('alias_name', aliasName);
        
        // Get division and race
        const division = row.querySelector('select[name="division"]').value;
        const race = row.querySelector('select[name="race"]').value;
        
        // Add statistics if division and race are provided
        if (division && race) {
            formData.append('division', division);
            formData.append('race', race);
            formData.append('maps_w', row.querySelector('input[name="maps_w"]').value);
            formData.append('maps_l', row.querySelector('input[name="maps_l"]').value);
            formData.append('sets_w', row.querySelector('input[name="sets_w"]').value);
            formData.append('sets_l', row.querySelector('input[name="sets_l"]').value);
        }
        
        // Log what we're adding
        console.log('Adding new player:');
        console.log(`Real name: ${formData.get('real_name')}`);
        console.log(`Alias: ${formData.get('alias_name')}`);
        
        // Send AJAX request
        fetch('eps_handler.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log(`Response status: ${response.status}`);
            
            if (!response.ok) {
                return response.text().then(text => {
                    throw new Error(`HTTP error ${response.status}: ${text}`);
                });
            }
            
            return response.json();
        })
        .then(data => {
            console.log('Response data:', data);
            
            if (data.status === 'success') {
                statusEl.textContent = 'Player added successfully';
                statusEl.classList.add('text-success');
                
                // Reload the page to show the new player
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                statusEl.textContent = `Error: ${data.message}`;
                statusEl.classList.add('text-danger');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            statusEl.textContent = `Error: ${error.message}`;
            statusEl.classList.add('text-danger');
        });
    }
    
    // Add new alias for a player
    function addNewAlias(button) {
        const row = button.closest('tr');
        const playerId = row.getAttribute('data-player-id');
        
        if (!playerId) {
            alert('Player ID is required');
            return;
        }
        
        // Get the alias name
        const aliasName = prompt('Enter new alias name:');
        if (!aliasName || aliasName.trim() === '') {
            return;
        }
        
        // Create status element
        const statusEl = document.createElement('div');
        statusEl.classList.add('status-message');
        row.querySelector('td:last-child').appendChild(statusEl);
        statusEl.textContent = 'Adding alias...';
        
        // Collect form data
        const formData = new FormData();
        formData.append('action', 'add_alias');
        formData.append('player_id', playerId);
        formData.append('alias_name', aliasName.trim());
        
        // Send AJAX request
        fetch('eps_handler.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log(`Response status: ${response.status}`);
            
            if (!response.ok) {
                return response.text().then(text => {
                    throw new Error(`HTTP error ${response.status}: ${text}`);
                });
            }
            
            return response.json();
        })
        .then(data => {
            console.log('Response data:', data);
            
            if (data.status === 'success') {
                statusEl.textContent = 'Alias added successfully';
                statusEl.classList.add('text-success');
                
                // Reload the page to show the new alias
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                statusEl.textContent = `Error: ${data.message}`;
                statusEl.classList.add('text-danger');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            statusEl.textContent = `Error: ${error.message}`;
            statusEl.classList.add('text-danger');
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
    
    .form-control {
        background-color: rgba(0, 0, 0, 0.5);
        border: 1px solid rgba(0, 212, 255, 0.3);
        color: #e0e0e0;
        border-radius: 5px;
        padding: 8px 12px;
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
    }
    
    .btn-secondary {
        background: #6c757d;
        color: white;
    }
    
    .btn-info {
        background: #17a2b8;
        color: white;
    }
    
    .btn-success {
        background: #28a745;
        color: white;
    }
    
    .btn-danger {
        background: #dc3545;
        color: white;
    }
    
    .btn:hover {
        opacity: 0.8;
        transform: translateY(-1px);
    }
    
    .btn-sm {
        padding: 4px 8px;
        font-size: 0.8em;
    }
    
    .btn-logout {
        background: #dc3545;
        color: white;
    }
    
    .table {
        background: rgba(0, 0, 0, 0.3);
        color: #e0e0e0;
        border: 1px solid rgba(0, 212, 255, 0.2);
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.3);
    }
    
    .table th {
        background: rgba(0, 212, 255, 0.1);
        color: #00d4ff;
        border-color: rgba(0, 212, 255, 0.2);
        font-weight: bold;
        padding: 12px 8px;
        text-align: center;
    }
    
    .table td {
        border-color: rgba(0, 212, 255, 0.2);
        vertical-align: middle;
        padding: 8px 6px;
        background: rgba(0, 0, 0, 0.3);
        color: #e0e0e0;
    }
    
    .table tbody tr {
        background: rgba(0, 0, 0, 0.3);
        color: #e0e0e0;
    }
    
    .table-striped tbody tr:nth-of-type(odd) {
        background-color: rgba(0, 212, 255, 0.05);
    }
    
    .table-striped tbody tr:nth-of-type(even) {
        background-color: rgba(0, 0, 0, 0.4);
    }
    
    .table-bordered {
        border: 1px solid rgba(0, 212, 255, 0.2);
    }
    
    .table-bordered th,
    .table-bordered td {
        border: 1px solid rgba(0, 212, 255, 0.2);
    }
    
    /* Override any Bootstrap default table styling */
    .table tbody tr td {
        background: inherit !important;
        color: #e0e0e0 !important;
    }
    
    .table tbody tr:hover {
        background: rgba(0, 212, 255, 0.1) !important;
    }
    
    /* Force dark theme on all table elements */
    .table tbody tr,
    .table tbody tr td,
    .table tbody tr th {
        background-color: rgba(0, 0, 0, 0.3) !important;
        color: #e0e0e0 !important;
    }
    
    /* Ensure form controls in table cells are also dark */
    .table .form-control {
        background: rgba(0, 0, 0, 0.5) !important;
        color: #e0e0e0 !important;
        border: 1px solid rgba(0, 212, 255, 0.3) !important;
    }
    
    .table .form-control:focus {
        background: rgba(0, 0, 0, 0.7) !important;
        color: #e0e0e0 !important;
        border-color: #00d4ff !important;
    }
    
    .input-group {
        display: flex;
        align-items: center;
    }
    
    .input-group-text {
        background-color: rgba(0, 212, 255, 0.1);
        border-color: rgba(0, 212, 255, 0.3);
        color: #00d4ff;
        padding: 8px 12px;
        border-radius: 5px;
        margin: 0 2px;
    }
    
    .input-group .form-control {
        border-radius: 5px;
        margin: 0 2px;
    }
    
    .input-group-prepend .input-group-text {
        border-top-right-radius: 0;
        border-bottom-right-radius: 0;
        margin-right: 0;
    }
    
    .input-group-append .input-group-text {
        border-top-left-radius: 0;
        border-bottom-left-radius: 0;
        margin-left: 0;
    }
    
    .input-group .form-control:first-child {
        border-top-left-radius: 5px;
        border-bottom-left-radius: 5px;
        border-top-right-radius: 0;
        border-bottom-right-radius: 0;
        margin-left: 0;
    }
    
    .input-group .form-control:last-child {
        border-top-right-radius: 5px;
        border-bottom-right-radius: 5px;
        border-top-left-radius: 0;
        border-bottom-left-radius: 0;
        margin-right: 0;
    }
    
    .status-message {
        margin-top: 5px;
        font-weight: bold;
        font-size: 12px;
        text-align: center;
    }
    
    .text-success {
        color: #28a745;
    }
    
    .text-danger {
        color: #dc3545;
    }
    
    .new-player-row {
        background-color: rgba(40, 167, 69, 0.2);
        border-left: 4px solid #28a745;
        color: #e0e0e0;
    }
    
    .player-row {
        background-color: rgba(0, 212, 255, 0.2);
        border-left: 4px solid #00d4ff;
        color: #e0e0e0;
    }
    
    .alias-row {
        background-color: rgba(108, 117, 125, 0.2);
        border-left: 4px solid #6c757d;
        color: #e0e0e0;
    }
    
    .stat-row {
        background-color: rgba(0, 0, 0, 0.4);
        border-left: 4px solid rgba(0, 212, 255, 0.3);
        color: #e0e0e0;
    }
    
    .mb-4 {
        margin-bottom: 1.5rem;
    }
    
    .mt-2 {
        margin-top: 0.5rem;
    }
    
    .table-responsive {
        border-radius: 10px;
        overflow: hidden;
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
    
    /* Responsive design */
    @media (max-width: 768px) {
        .container {
            padding: 10px;
        }
        
        .admin-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
        
        h1 {
            font-size: 2em;
        }
        
        .table {
            font-size: 0.9em;
        }
        
        .btn-sm {
            padding: 3px 6px;
            font-size: 0.7em;
        }
    }
</style>

<?php include 'includes/footer.php'; ?> 
