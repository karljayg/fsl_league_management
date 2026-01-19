<?php
/**
 * Draft Admin View
 * Setup, controls, team/player management
 * Requires "edit player, team, stats" permission
 */

session_start();

require_once __DIR__ . '/../includes/data.php';
require_once __DIR__ . '/../includes/draft_logic.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

// Check permission using RBAC system
$hasPermission = false;
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt
            FROM ws_user_roles ur
            JOIN ws_role_permissions rp ON ur.role_id = rp.role_id
            JOIN ws_permissions p ON rp.permission_id = p.permission_id
            WHERE ur.user_id = ? AND p.permission_name = ?
        ");
        $stmt->execute([$_SESSION['user_id'], 'edit player, team, stats']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['cnt'] > 0) {
            $hasPermission = true;
        }
    } catch (PDOException $e) {
        error_log("Permission check failed in draft admin: " . $e->getMessage());
    }
}

// Redirect to login if not authorized
if (!$hasPermission) {
    header('Location: ../../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// Get or create session
$session = get_session();
$token = $_GET['token'] ?? '';

// If no session exists and no token, show setup form
if (!$session) {
    // Initialize new session if form submitted
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['init_draft'])) {
        $name = trim($_POST['draft_name'] ?? 'FSL Draft');
        $seconds = intval($_POST['seconds_per_pick'] ?? 120);
        $session = init_session($name, $seconds);
        
        // Initialize teams with default names and logos
        $teams = [
            ['id' => 1, 'name' => 'Infinite Cyclists', 'draft_position' => 1, 'token' => generate_team_token(), 'logo' => 'images/FSL_team_square_logo_Infinite_Cyclists_256px.png'],
            ['id' => 2, 'name' => 'Rages Raiders', 'draft_position' => 2, 'token' => generate_team_token(), 'logo' => 'images/FSL_team_square_logo_Rages_Raiders_256px.png'],
            ['id' => 3, 'name' => 'Angry Space Hares', 'draft_position' => 3, 'token' => generate_team_token(), 'logo' => 'images/FSL_team_square_logo_Angry_Space_Hares_256px.png'],
            ['id' => 4, 'name' => 'PulledTheBoys', 'draft_position' => 4, 'token' => generate_team_token(), 'logo' => 'images/FSL_team_square_logo_PulledTheBoys_256px.png'],
        ];
        save_teams($teams);
        save_players([]);
        save_events([]);
        draft_write_json('audit.json', []);
        
        // Redirect to admin with token
        header('Location: ?token=' . $session['admin_token']);
        exit;
    }
    
    // Show init form
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Initialize Draft</title>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="../../css/styles.css">
        <link rel="stylesheet" href="../css/draft.css">
    </head>
    <body>
        <div class="draft-container" style="max-width: 500px; margin-top: 5rem;">
            <div class="draft-panel">
                <h2>Initialize New Draft</h2>
                <form method="POST">
                    <div class="form-group">
                        <label>Draft Name</label>
                        <input type="text" name="draft_name" value="FSL Season 10 Draft" required>
                    </div>
                    <div class="form-group">
                        <label>Seconds Per Pick</label>
                        <input type="number" name="seconds_per_pick" value="120" min="30" max="600" required>
                    </div>
                    <button type="submit" name="init_draft" class="admin-btn primary" style="width: 100%;">Create Draft</button>
                </form>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Admin access - no token required (admin URL is not public)
// Token validation removed for easier access

// Check timer on every load
check_timer();
$session = get_session(); // Refresh after timer check

$teams = get_teams();
$players = get_players();
$events = get_events();

// Get current team name
$currentTeamName = '';
if ($session['current_team_id']) {
    $currentTeam = get_team_by_id($session['current_team_id']);
    $currentTeamName = $currentTeam ? $currentTeam['name'] : '';
}

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
           '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - <?= htmlspecialchars($session['name']) ?></title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../css/styles.css">
    <link rel="stylesheet" href="../css/draft.css">
</head>
<body>
    <div class="draft-container">
        <div class="draft-header">
            <a href="../../index.php"><img src="../../images/fsl_sc2_logo.png" alt="FSL" style="height: 80px; margin-bottom: 0.5rem;"></a>
            <h1><?= htmlspecialchars($session['name']) ?> - Admin</h1>
            <span class="draft-status <?= $session['status'] ?>"><?= ucfirst($session['status']) ?></span>
        </div>

        <?php if ($session['status'] === 'live' || $session['status'] === 'paused'): ?>
        <div class="draft-timer">
            <div class="on-the-clock">On the clock: <span class="team-name"><?= htmlspecialchars($currentTeamName) ?></span></div>
            <div class="timer-display" id="timer">--:--</div>
        </div>
        <?php endif; ?>

        <!-- Draft Name Editor -->
        <div class="draft-panel">
            <h2>Draft Name</h2>
            <form id="draft-name-form" style="display: flex; gap: 0.5rem; align-items: flex-end;">
                <div style="flex: 1;">
                    <input type="text" id="draft-name-input" value="<?= htmlspecialchars($session['name']) ?>" 
                           style="width: 100%; padding: 0.5rem; font-size: 1rem;" required>
                </div>
                <button type="submit" class="admin-btn secondary">
                    <i class="fas fa-save"></i> Save Name
                </button>
            </form>
        </div>

        <!-- Admin Controls -->
        <div class="draft-panel">
            <h2>Draft Controls</h2>
            <div class="admin-controls">
                <?php if ($session['status'] === 'setup'): ?>
                    <button class="admin-btn primary" onclick="adminAction('start')">
                        <i class="fas fa-play"></i> Start Draft
                    </button>
                <?php elseif ($session['status'] === 'live'): ?>
                    <button class="admin-btn secondary" onclick="adminAction('pause')">
                        <i class="fas fa-pause"></i> Pause
                    </button>
                    <button class="admin-btn secondary" onclick="adminAction('restart_timer')">
                        <i class="fas fa-redo"></i> Restart Timer
                    </button>
                    <button class="admin-btn danger" onclick="adminAction('skip')">
                        <i class="fas fa-forward"></i> Skip Team
                    </button>
                <?php elseif ($session['status'] === 'paused'): ?>
                    <button class="admin-btn primary" onclick="adminAction('resume')">
                        <i class="fas fa-play"></i> Resume
                    </button>
                <?php endif; ?>
                
                <?php if ($session['status'] !== 'completed' && count($events) > 0): ?>
                    <button class="admin-btn secondary" onclick="adminAction('undo')">
                        <i class="fas fa-undo"></i> Undo Last
                    </button>
                <?php endif; ?>
                
                <?php if ($session['status'] !== 'completed' && $session['status'] !== 'setup'): ?>
                    <button class="admin-btn danger" onclick="if(confirm('End draft now?')) adminAction('end')">
                        <i class="fas fa-stop"></i> End Draft
                    </button>
                <?php endif; ?>
                
                <button class="admin-btn danger" onclick="if(confirm('Reset entire draft? This cannot be undone!')) { adminAction('reset'); setTimeout(() => window.location.href = window.location.pathname, 500); }">
                    <i class="fas fa-trash-alt"></i> Reset Draft
                </button>
            </div>
        </div>

        <div class="draft-grid">
            <!-- Teams Panel -->
            <div class="draft-panel">
                <h2>Teams</h2>
                <?php foreach ($teams as $team): ?>
                    <?php $roster = get_team_roster($team['id']); ?>
                    <div class="team-card <?= $team['id'] === $session['current_team_id'] ? 'on-clock' : '' ?>">
                        <div class="team-card-header">
                            <a href="../../view_team.php?name=<?= urlencode($team['name']) ?>" class="team-name team-link" target="_blank"><?= htmlspecialchars($team['name']) ?></a>
                            <span class="team-pick-count"><?= count($roster) ?> picks</span>
                        </div>
                        <div class="team-roster">
                            <?php foreach ($roster as $p): ?>
                                <div class="player-entry">
                                    <a href="../../view_player.php?name=<?= urlencode($p['display_name']) ?>" class="player-link" target="_blank"><?= htmlspecialchars($p['display_name']) ?></a><?= get_role_marker($p) ?>
                                    <span class="player-bucket">G<?= $p['bucket_index'] ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($session['status'] === 'setup'): ?>
                        <div style="margin-top: 0.5rem; font-size: 0.75rem; color: var(--text-secondary);">
                            Token: <code style="font-size: 0.7rem;"><?= substr($team['token'], 0, 8) ?>...</code>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                
                <div style="margin-top: 1rem;">
                    <h3 style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 0.5rem;">Team Links</h3>
                    <?php foreach ($teams as $team): ?>
                        <div style="margin-bottom: 0.5rem; font-size: 0.8rem;">
                            <strong><?= htmlspecialchars($team['name']) ?>:</strong><br>
                            <input type="text" readonly value="<?= $baseUrl ?>/team/?token=<?= $team['token'] ?>" 
                                   style="width: 100%; font-size: 0.75rem; padding: 0.3rem;" onclick="this.select()">
                        </div>
                    <?php endforeach; ?>
                    <div style="margin-top: 0.5rem; font-size: 0.8rem;">
                        <strong>Public View:</strong><br>
                        <input type="text" readonly value="<?= $baseUrl ?>/public/" 
                               style="width: 100%; font-size: 0.75rem; padding: 0.3rem;" onclick="this.select()">
                    </div>
                </div>
            </div>

            <!-- Players Panel -->
            <div class="draft-panel">
                <h2>Players (<?= count(array_filter($players, fn($p) => $p['status'] === 'available')) ?> available)</h2>
                
                <?php if ($session['status'] === 'setup'): ?>
                <!-- Import Form -->
                <div class="import-area">
                    <form id="import-form">
                        <textarea id="player-import" placeholder="Paste player list here:
Rank,Name,Race,Notes
1,PlayerOne,T,Notes here
2,PlayerTwo,P,
3,PlayerThree,Z,Some notes"></textarea>
                        <div class="import-help">
                            CSV format: Rank, Name, Race (T/P/Z/R), Notes (optional)<br>
                            One player per line.
                        </div>
                        <button type="submit" class="admin-btn primary" style="margin-top: 0.5rem;">
                            <i class="fas fa-upload"></i> Import Players
                        </button>
                    </form>
                </div>
                <?php endif; ?>

                <div class="player-list" style="margin-top: 1rem;">
                    <?php 
                    usort($players, fn($a, $b) => $a['ranking'] - $b['ranking']);
                    foreach ($players as $player): 
                    ?>
                        <div class="player-card <?= $player['status'] !== 'available' ? 'drafted' : '' ?>">
                            <div class="player-info">
                                <span class="player-rank"><?= $player['ranking'] ?></span>
                                <a href="../../view_player.php?name=<?= urlencode($player['display_name']) ?>" class="player-name player-link" target="_blank"><?= htmlspecialchars($player['display_name']) ?></a>
                            </div>
                            <div class="player-meta">
                                <?php
                                $raceIcons = ['T' => 'terran_icon.png', 'P' => 'protoss_icon.png', 'Z' => 'zerg_icon.png', 'R' => 'random_icon.png'];
                                $icon = $raceIcons[$player['race']] ?? 'random_icon.png';
                                ?><img src="../../images/<?= $icon ?>" alt="<?= $player['race'] ?>" class="race-icon">
                                <span class="player-bucket">G<?= $player['bucket_index'] ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- History Panel -->
            <div class="draft-panel">
                <h2>Pick History</h2>
                <div class="history-list">
                    <?php 
                    $reversedEvents = array_reverse($events);
                    foreach ($reversedEvents as $event): 
                        $team = get_team_by_id($event['team_id']);
                        $player = $event['player_id'] ? get_player_by_id($event['player_id']) : null;
                    ?>
                        <div class="history-entry <?= $event['result'] === 'SKIP' ? 'skip' : '' ?>">
                            <span class="pick-num">#<?= $event['pick_number'] ?? '-' ?></span>
                            <a href="../../view_team.php?name=<?= urlencode($team['name'] ?? '') ?>" class="team team-link" target="_blank"><?= htmlspecialchars($team['name'] ?? 'Unknown') ?></a>
                            <span class="player">
                                <?php if ($event['result'] === 'SKIP'): ?>
                                    Skipped (<?= $event['skip_reason'] ?? '' ?>)
                                <?php elseif ($player): ?>
                                    <a href="../../view_player.php?name=<?= urlencode($player['display_name']) ?>" class="player-link" target="_blank"><?= htmlspecialchars($player['display_name']) ?></a>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($events)): ?>
                        <p style="color: var(--text-secondary); text-align: center;">No picks yet</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($session['status'] === 'setup'): ?>
        <!-- Team Name Editor -->
        <div class="draft-panel" style="margin-top: 1.5rem;">
            <h2>Edit Teams</h2>
            <form id="teams-form">
                <?php foreach ($teams as $team): ?>
                    <div class="form-row" style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(255,255,255,0.1);">
                        <div class="form-group">
                            <label>Team <?= $team['draft_position'] ?> Name</label>
                            <input type="text" name="team_<?= $team['id'] ?>" value="<?= htmlspecialchars($team['name']) ?>">
                        </div>
                        <div class="form-group">
                            <label>Logo Path (relative to fsl/)</label>
                            <input type="text" name="logo_<?= $team['id'] ?>" value="<?= htmlspecialchars($team['logo'] ?? '') ?>" placeholder="images/FSL_team_square_logo_...">
                        </div>
                    </div>
                <?php endforeach; ?>
                <button type="submit" class="admin-btn secondary">
                    <i class="fas fa-save"></i> Save Teams
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <script>
        const adminToken = '<?= $token ?>';
        const deadline = <?= $session['pick_deadline_at'] ? "'" . $session['pick_deadline_at'] . "'" : 'null' ?>;
        const status = '<?= $session['status'] ?>';
        
        // Timer
        function updateTimer() {
            const timerEl = document.getElementById('timer');
            if (!timerEl || !deadline) return;
            
            const now = Date.now();
            const end = new Date(deadline).getTime();
            const diff = Math.max(0, Math.floor((end - now) / 1000));
            
            const mins = Math.floor(diff / 60);
            const secs = diff % 60;
            timerEl.textContent = `${mins}:${secs.toString().padStart(2, '0')}`;
            
            if (diff <= 10) {
                timerEl.classList.add('urgent');
            }
            
            if (diff === 0 && status === 'live') {
                setTimeout(() => location.reload(), 1500);
            }
        }
        
        if (deadline) {
            updateTimer();
            setInterval(updateTimer, 1000);
        }
        
        // Admin actions
        function adminAction(action) {
            fetch('../ajax/admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action, token: adminToken })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.error || 'Action failed');
                }
            });
        }
        
        // Update draft name
        document.getElementById('draft-name-form')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const draftName = document.getElementById('draft-name-input').value.trim();
            
            if (!draftName) {
                alert('Draft name cannot be empty');
                return;
            }
            
            fetch('../ajax/admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'update_draft_name', token: adminToken, draft_name: draftName })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.error || 'Failed to update draft name');
                }
            });
        });
        
        // Import players
        document.getElementById('import-form')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const text = document.getElementById('player-import').value;
            
            fetch('../ajax/admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'import_players', token: adminToken, data: text })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.error || 'Import failed');
                }
            });
        });
        
        // Save team names
        document.getElementById('teams-form')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const teams = {};
            const logos = {};
            for (let [key, value] of formData) {
                const parts = key.split('_');
                const type = parts[0];
                const id = parseInt(parts[1]);
                if (type === 'team') {
                    teams[id] = value;
                } else if (type === 'logo') {
                    logos[id] = value;
                }
            }
            
            fetch('../ajax/admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'update_teams', token: adminToken, teams, logos })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.error || 'Save failed');
                }
            });
        });
        
        // Smart refresh - only reload when data changes
        let currentVersion = '<?= get_data_version() ?>';
        
        async function checkForUpdates() {
            try {
                const response = await fetch('../ajax/state.php?check_only=1');
                const data = await response.json();
                if (data.version && data.version !== currentVersion) {
                    location.reload();
                }
            } catch (e) {
                // Silent fail
            }
        }
        
        // Poll when draft is live or paused
        if (status === 'live' || status === 'paused') {
            setInterval(checkForUpdates, 3000);
        }
    </script>
</body>
</html>
