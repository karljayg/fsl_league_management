<?php
/**
 * Admin: edit basic FSL player profile fields (not auto-calculated statistics).
 * Access: admin role only (role_id 1 / role_name admin).
 */

session_start();

require_once __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) {
    die('Permission denied: user not logged in.');
}

function editPlayerUserHasAdminRole(PDO $db, $userId): bool
{
    if ($userId === null || $userId === '' || $userId === 0 || $userId === '0') {
        return false;
    }

    $stmt = $db->prepare("
        SELECT COUNT(*) AS c
        FROM ws_user_roles ur
        JOIN ws_roles r ON ur.role_id = r.role_id
        WHERE ur.user_id = ? AND (r.role_id = 1 OR r.role_name = 'admin')
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row && (int) $row['c'] > 0;
}

$userId = $_SESSION['user_id'];
if (!editPlayerUserHasAdminRole($db, $userId)) {
    error_log('Permission denied for user ' . $userId . ': edit_player.php requires admin role');
    die('Permission denied: You do not have permission to access this page.');
}

$races = [
    'P' => 'Protoss',
    'T' => 'Terran',
    'Z' => 'Zerg',
    'R' => 'Random',
];

$statusOptions = ['active', 'inactive', 'banned', 'other'];

function editPlayerJsonValid(?string $value): bool
{
    $value = trim((string) $value);
    if ($value === '') {
        return true;
    }
    json_decode($value);
    return json_last_error() === JSON_ERROR_NONE;
}

function editPlayerPrimaryAliasId(PDO $db, int $playerId, string $realName): ?int
{
    $stmt = $db->prepare(
        'SELECT Alias_ID FROM Player_Aliases
         WHERE Player_ID = ? AND Alias_Name = ?
         LIMIT 1'
    );
    $stmt->execute([$playerId, $realName]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    $stmt = $db->prepare(
        'SELECT Alias_ID FROM Player_Aliases WHERE Player_ID = ? ORDER BY Alias_ID LIMIT 1'
    );
    $stmt->execute([$playerId]);
    $id = $stmt->fetchColumn();

    return $id !== false ? (int) $id : null;
}

function editPlayerGetDisplayRace(PDO $db, int $playerId): ?string
{
    $stmt = $db->prepare(
        'SELECT Race FROM FSL_STATISTICS
         WHERE Player_ID = ?
         ORDER BY (MapsW + MapsL) DESC, Player_Record_ID ASC
         LIMIT 1'
    );
    $stmt->execute([$playerId]);
    $race = $stmt->fetchColumn();

    return $race !== false ? (string) $race : null;
}

/**
 * Set display race on existing stats rows, or seed a zeroed row when none exist.
 * Does not touch MapsW/L or SetsW/L.
 */
function editPlayerSetDisplayRace(PDO $db, int $playerId, string $realName, string $race): void
{
    if (!isset(['P' => 1, 'T' => 1, 'Z' => 1, 'R' => 1][$race])) {
        throw new InvalidArgumentException('Invalid race.');
    }

    $countStmt = $db->prepare('SELECT COUNT(*) FROM FSL_STATISTICS WHERE Player_ID = ?');
    $countStmt->execute([$playerId]);
    if ((int) $countStmt->fetchColumn() === 0) {
        $aliasId = editPlayerPrimaryAliasId($db, $playerId, $realName);
        if ($aliasId === null) {
            throw new RuntimeException('Player has no aliases; add an alias before setting race.');
        }
        $insert = $db->prepare(
            'INSERT INTO FSL_STATISTICS (Player_ID, Alias_ID, Division, Race, MapsW, MapsL, SetsW, SetsL)
             VALUES (?, ?, ?, ?, 0, 0, 0, 0)'
        );
        $insert->execute([$playerId, $aliasId, 'B', $race]);
        return;
    }

    $rowsStmt = $db->prepare(
        'SELECT Player_Record_ID, Alias_ID, Division, Race
         FROM FSL_STATISTICS WHERE Player_ID = ?'
    );
    $rowsStmt->execute([$playerId]);
    $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        if ($row['Race'] === $race) {
            continue;
        }

        $conflict = $db->prepare(
            'SELECT Player_Record_ID FROM FSL_STATISTICS
             WHERE Player_ID = ? AND Alias_ID = ? AND Division = ? AND Race = ?
             LIMIT 1'
        );
        $conflict->execute([$playerId, $row['Alias_ID'], $row['Division'], $race]);
        if ($conflict->fetchColumn()) {
            throw new RuntimeException(
                'Cannot change race to ' . $race . ' for division '
                . $row['Division'] . ': a stats row with that race already exists. Use Edit Player Statistics to merge rows.'
            );
        }

        $update = $db->prepare(
            'UPDATE FSL_STATISTICS SET Race = ? WHERE Player_Record_ID = ?'
        );
        $update->execute([$race, $row['Player_Record_ID']]);
    }
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $db->beginTransaction();

        switch ($_POST['action']) {
            case 'update_player':
                $playerId = isset($_POST['player_id']) ? (int) $_POST['player_id'] : 0;
                if ($playerId <= 0) {
                    throw new InvalidArgumentException('Player ID is required.');
                }

                $oldStmt = $db->prepare('SELECT Real_Name FROM Players WHERE Player_ID = ?');
                $oldStmt->execute([$playerId]);
                $oldRow = $oldStmt->fetch(PDO::FETCH_ASSOC);
                if (!$oldRow) {
                    throw new InvalidArgumentException('Player not found.');
                }

                $realName = trim((string) ($_POST['real_name'] ?? ''));
                if ($realName === '') {
                    throw new InvalidArgumentException('Player name is required.');
                }

                $teamId = $_POST['team_id'] ?? '';
                $teamId = ($teamId === '' || $teamId === null) ? null : (int) $teamId;
                $status = $_POST['status'] ?? 'active';
                if (!in_array($status, $statusOptions, true)) {
                    throw new InvalidArgumentException('Invalid status.');
                }

                $introUrl = trim((string) ($_POST['intro_url'] ?? ''));
                $introUrl = $introUrl === '' ? null : $introUrl;

                $championshipRecord = trim((string) ($_POST['championship_record'] ?? ''));
                $teamLeagueRecord = trim((string) ($_POST['teamleague_championship_record'] ?? ''));
                $teamsHistory = trim((string) ($_POST['teams_history'] ?? ''));

                foreach ([
                    'Championship Record' => $championshipRecord,
                    'Team League Championship Record' => $teamLeagueRecord,
                    'Teams History' => $teamsHistory,
                ] as $label => $json) {
                    if (!editPlayerJsonValid($json)) {
                        throw new InvalidArgumentException($label . ' is not valid JSON.');
                    }
                }

                $dup = $db->prepare(
                    'SELECT COUNT(*) FROM Players WHERE Real_Name = ? AND Player_ID <> ?'
                );
                $dup->execute([$realName, $playerId]);
                if ((int) $dup->fetchColumn() > 0) {
                    throw new InvalidArgumentException('Another player already uses that name.');
                }

                $update = $db->prepare(
                    'UPDATE Players SET
                        Real_Name = :real_name,
                        Team_ID = :team_id,
                        Status = :status,
                        Intro_Url = :intro_url,
                        Championship_Record = :championship_record,
                        TeamLeague_Championship_Record = :teamleague_championship_record,
                        Teams_History = :teams_history
                     WHERE Player_ID = :player_id'
                );
                $update->execute([
                    ':real_name' => $realName,
                    ':team_id' => $teamId,
                    ':status' => $status,
                    ':intro_url' => $introUrl,
                    ':championship_record' => $championshipRecord === '' ? null : $championshipRecord,
                    ':teamleague_championship_record' => $teamLeagueRecord === '' ? null : $teamLeagueRecord,
                    ':teams_history' => $teamsHistory === '' ? null : $teamsHistory,
                    ':player_id' => $playerId,
                ]);

                if ($oldRow['Real_Name'] !== $realName) {
                    $syncAlias = $db->prepare(
                        'UPDATE Player_Aliases SET Alias_Name = ?
                         WHERE Player_ID = ? AND Alias_Name = ?'
                    );
                    $syncAlias->execute([$realName, $playerId, $oldRow['Real_Name']]);
                }

                $race = $_POST['race'] ?? 'R';
                if (!isset($races[$race])) {
                    throw new InvalidArgumentException('Invalid race.');
                }
                $previousDisplayRace = editPlayerGetDisplayRace($db, $playerId);
                if ($previousDisplayRace === null || $race !== $previousDisplayRace) {
                    editPlayerSetDisplayRace($db, $playerId, $realName, $race);
                }

                $db->commit();
                $message = 'Player updated.';
                $messageType = 'success';
                header('Location: edit_player.php?player_id=' . $playerId . '&saved=1');
                exit;

            case 'add_alias':
                $playerId = isset($_POST['player_id']) ? (int) $_POST['player_id'] : 0;
                $aliasName = trim((string) ($_POST['alias_name'] ?? ''));
                if ($playerId <= 0 || $aliasName === '') {
                    throw new InvalidArgumentException('Player and alias name are required.');
                }
                $dup = $db->prepare('SELECT COUNT(*) FROM Player_Aliases WHERE Alias_Name = ?');
                $dup->execute([$aliasName]);
                if ((int) $dup->fetchColumn() > 0) {
                    throw new InvalidArgumentException('That alias name is already in use.');
                }
                $insert = $db->prepare(
                    'INSERT INTO Player_Aliases (Player_ID, Alias_Name) VALUES (?, ?)'
                );
                $insert->execute([$playerId, $aliasName]);
                $db->commit();
                $message = 'Alias added.';
                $messageType = 'success';
                header('Location: edit_player.php?player_id=' . $playerId . '&saved=1');
                exit;

            case 'update_alias':
                $aliasId = isset($_POST['alias_id']) ? (int) $_POST['alias_id'] : 0;
                $playerId = isset($_POST['player_id']) ? (int) $_POST['player_id'] : 0;
                $aliasName = trim((string) ($_POST['alias_name'] ?? ''));
                if ($aliasId <= 0 || $playerId <= 0 || $aliasName === '') {
                    throw new InvalidArgumentException('Alias ID, player, and name are required.');
                }
                $dup = $db->prepare(
                    'SELECT COUNT(*) FROM Player_Aliases WHERE Alias_Name = ? AND Alias_ID <> ?'
                );
                $dup->execute([$aliasName, $aliasId]);
                if ((int) $dup->fetchColumn() > 0) {
                    throw new InvalidArgumentException('That alias name is already in use.');
                }
                $update = $db->prepare(
                    'UPDATE Player_Aliases SET Alias_Name = ? WHERE Alias_ID = ? AND Player_ID = ?'
                );
                $update->execute([$aliasName, $aliasId, $playerId]);
                $db->commit();
                $message = 'Alias updated.';
                $messageType = 'success';
                header('Location: edit_player.php?player_id=' . $playerId . '&saved=1');
                exit;

            case 'delete_alias':
                $aliasId = isset($_POST['alias_id']) ? (int) $_POST['alias_id'] : 0;
                $playerId = isset($_POST['player_id']) ? (int) $_POST['player_id'] : 0;
                if ($aliasId <= 0 || $playerId <= 0) {
                    throw new InvalidArgumentException('Alias ID is required.');
                }
                $count = $db->prepare(
                    'SELECT COUNT(*) FROM Player_Aliases WHERE Player_ID = ?'
                );
                $count->execute([$playerId]);
                if ((int) $count->fetchColumn() <= 1) {
                    throw new InvalidArgumentException('Cannot delete the only alias for a player.');
                }
                $delete = $db->prepare(
                    'DELETE FROM Player_Aliases WHERE Alias_ID = ? AND Player_ID = ?'
                );
                $delete->execute([$aliasId, $playerId]);
                $db->commit();
                $message = 'Alias deleted (any linked FSL_STATISTICS rows for that alias were removed by DB cascade).';
                $messageType = 'success';
                header('Location: edit_player.php?player_id=' . $playerId . '&saved=1');
                exit;

            case 'add_player':
                $realName = trim((string) ($_POST['real_name'] ?? ''));
                $aliasName = trim((string) ($_POST['alias_name'] ?? ''));
                if ($realName === '' || $aliasName === '') {
                    throw new InvalidArgumentException('Player name and primary alias are required.');
                }
                $teamId = $_POST['team_id'] ?? '';
                $teamId = ($teamId === '' || $teamId === null) ? null : (int) $teamId;
                $status = $_POST['status'] ?? 'active';
                if (!in_array($status, $statusOptions, true)) {
                    throw new InvalidArgumentException('Invalid status.');
                }
                $introUrl = trim((string) ($_POST['intro_url'] ?? ''));
                $introUrl = $introUrl === '' ? null : $introUrl;
                $race = $_POST['race'] ?? 'R';
                if (!isset($races[$race])) {
                    $race = 'R';
                }

                foreach (['real_name' => $realName, 'alias_name' => $aliasName] as $label => $name) {
                    if ($label === 'real_name') {
                        $dup = $db->prepare('SELECT COUNT(*) FROM Players WHERE Real_Name = ?');
                        $dup->execute([$name]);
                    } else {
                        $dup = $db->prepare('SELECT COUNT(*) FROM Player_Aliases WHERE Alias_Name = ?');
                        $dup->execute([$name]);
                    }
                    if ((int) $dup->fetchColumn() > 0) {
                        throw new InvalidArgumentException('Name already exists: ' . $name);
                    }
                }

                $insert = $db->prepare(
                    'INSERT INTO Players (Real_Name, Team_ID, Status, Intro_Url)
                     VALUES (?, ?, ?, ?)'
                );
                $insert->execute([$realName, $teamId, $status, $introUrl]);
                $playerId = (int) $db->lastInsertId();

                $aliasInsert = $db->prepare(
                    'INSERT INTO Player_Aliases (Player_ID, Alias_Name) VALUES (?, ?)'
                );
                $aliasInsert->execute([$playerId, $aliasName]);

                editPlayerSetDisplayRace($db, $playerId, $realName, $race);

                $db->commit();
                header('Location: edit_player.php?player_id=' . $playerId . '&saved=1');
                exit;

            default:
                throw new InvalidArgumentException('Unknown action.');
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

if (isset($_GET['saved'])) {
    $message = 'Changes saved.';
    $messageType = 'success';
}

$teams = $db->query('SELECT Team_ID, Team_Name FROM Teams ORDER BY Team_Name')
    ->fetchAll(PDO::FETCH_KEY_PAIR);

$searchQuery = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 25;
$offset = ($page - 1) * $perPage;
$editPlayerId = isset($_GET['player_id']) ? (int) $_GET['player_id'] : 0;

$searchCondition = '';
$searchParams = [];
if ($searchQuery !== '') {
    $searchCondition = 'WHERE p.Real_Name LIKE :search OR pa.Alias_Name LIKE :search';
    $searchParams[':search'] = '%' . $searchQuery . '%';
}

$countSql = "
    SELECT COUNT(DISTINCT p.Player_ID) AS total
    FROM Players p
    LEFT JOIN Player_Aliases pa ON pa.Player_ID = p.Player_ID
    $searchCondition
";
$countStmt = $db->prepare($countSql);
foreach ($searchParams as $k => $v) {
    $countStmt->bindValue($k, $v);
}
$countStmt->execute();
$totalPlayers = (int) $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = max(1, (int) ceil($totalPlayers / $perPage));

$listSql = "
    SELECT
        p.Player_ID,
        p.Real_Name,
        p.Status,
        p.Team_ID,
        t.Team_Name,
        p.Intro_Url,
        (SELECT fs.Race FROM FSL_STATISTICS fs
         WHERE fs.Player_ID = p.Player_ID
         ORDER BY (fs.MapsW + fs.MapsL) DESC, fs.Player_Record_ID ASC
         LIMIT 1) AS Race
    FROM Players p
    LEFT JOIN Player_Aliases pa ON pa.Player_ID = p.Player_ID
    LEFT JOIN Teams t ON t.Team_ID = p.Team_ID
    $searchCondition
    GROUP BY p.Player_ID, p.Real_Name, p.Status, p.Team_ID, t.Team_Name, p.Intro_Url
    ORDER BY p.Real_Name
    LIMIT $perPage OFFSET $offset
";
$listStmt = $db->prepare($listSql);
foreach ($searchParams as $k => $v) {
    $listStmt->bindValue($k, $v);
}
$listStmt->execute();
$playerList = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$editPlayer = null;
$editAliases = [];
$editRace = null;

if ($editPlayerId > 0) {
    $playerStmt = $db->prepare(
        'SELECT p.*, t.Team_Name, u.username AS linked_username
         FROM Players p
         LEFT JOIN Teams t ON t.Team_ID = p.Team_ID
         LEFT JOIN users u ON u.id = p.User_ID
         WHERE p.Player_ID = ?'
    );
    $playerStmt->execute([$editPlayerId]);
    $editPlayer = $playerStmt->fetch(PDO::FETCH_ASSOC);

    if ($editPlayer) {
        $aliasStmt = $db->prepare(
            'SELECT Alias_ID, Alias_Name FROM Player_Aliases
             WHERE Player_ID = ? ORDER BY Alias_Name'
        );
        $aliasStmt->execute([$editPlayerId]);
        $editAliases = $aliasStmt->fetchAll(PDO::FETCH_ASSOC);
        $editRace = editPlayerGetDisplayRace($db, $editPlayerId);
    }
}

$pageTitle = 'Edit FSL Players';
require_once __DIR__ . '/includes/header.php';

$editIntroUrl = $editPlayer ? trim((string) ($editPlayer['Intro_Url'] ?? '')) : '';
?>

<div class="edit-player-admin">
    <div class="admin-header">
        <div>
            <h1><i class="fas fa-user-edit"></i> Edit FSL Players</h1>
            <p class="page-lead">
                Name, race, aliases, intro video, team, status, championship JSON.
                Stats: <a href="edit_player_statistics.php">Edit Player Statistics</a>.
                Account links: <a href="admin_link_user_player.php">Link user to FSL player</a>.
            </p>
        </div>
        <div class="admin-user-info">
            <span>Logged in as: <?= htmlspecialchars($_SESSION['username'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></span>
            <a href="logout.php" class="btn btn-logout">Logout</a>
        </div>
    </div>

    <?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'error'; ?>">
        <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php endif; ?>

    <?php if ($editPlayer): ?>
    <div class="admin-panel">
        <div class="panel-header">
            <h2>Edit <?php echo htmlspecialchars($editPlayer['Real_Name'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <a href="view_player.php?name=<?php echo urlencode($editPlayer['Real_Name']); ?>" class="btn btn-link">View profile</a>
        </div>

        <form method="post">
            <input type="hidden" name="action" value="update_player">
            <input type="hidden" name="player_id" value="<?php echo (int) $editPlayer['Player_ID']; ?>">

            <h3 class="section-title">Basic info</h3>
            <div class="form-row">
                <div class="form-group">
                    <label for="real_name">Canonical name</label>
                    <input type="text" id="real_name" name="real_name" required
                           value="<?php echo htmlspecialchars($editPlayer['Real_Name'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-group">
                    <label for="race">Race</label>
                    <select id="race" name="race">
                        <?php foreach ($races as $code => $label): ?>
                        <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php echo ($editRace ?? 'R') === $code ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small>Only applied when you change this dropdown. Does not touch win/loss counts.</small>
                </div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <?php foreach ($statusOptions as $opt): ?>
                        <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php echo $editPlayer['Status'] === $opt ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(ucfirst($opt), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="team_id">Team</label>
                    <select id="team_id" name="team_id">
                        <option value="">No team</option>
                        <?php foreach ($teams as $tid => $tname): ?>
                        <option value="<?php echo (int) $tid; ?>"
                            <?php echo (string) $editPlayer['Team_ID'] === (string) $tid ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($tname, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php if (!empty($editPlayer['linked_username'])): ?>
            <p class="panel-note">
                Linked site user: <strong><?php echo htmlspecialchars($editPlayer['linked_username'], ENT_QUOTES, 'UTF-8'); ?></strong>
                — change on <a href="admin_link_user_player.php">Link user to FSL player</a>.
            </p>
            <?php endif; ?>

            <div class="intro-url-block">
                <h3 class="section-title">Intro video URL</h3>
                <p class="panel-note">Saved to <code>Players.Intro_Url</code>. Used on the player profile intro / thumbnail.</p>
                <div class="form-group">
                    <label for="intro_url">Intro URL</label>
                    <input type="text" id="intro_url" name="intro_url"
                           value="<?php echo htmlspecialchars($editIntroUrl, ENT_QUOTES, 'UTF-8'); ?>"
                           placeholder="https://psistorm.com/stream_production/production_files/video/…">
                    <small>Full URL to .mp4 or other intro media. Leave blank to clear.</small>
                </div>
                <?php if ($editIntroUrl !== ''): ?>
                <p class="intro-preview">
                    Current:
                    <a href="<?php echo htmlspecialchars($editIntroUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                        <?php echo htmlspecialchars($editIntroUrl, ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </p>
                <?php else: ?>
                <p class="intro-preview intro-preview-empty">No intro URL set for this player.</p>
                <?php endif; ?>
            </div>

            <h3 class="section-title">Championship records (JSON)</h3>
            <div class="form-group">
                <label for="championship_record">Championship record</label>
                <textarea id="championship_record" name="championship_record" rows="3" class="json-field"><?php echo htmlspecialchars((string) ($editPlayer['Championship_Record'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <div class="form-group">
                <label for="teamleague_championship_record">Team league championship record</label>
                <textarea id="teamleague_championship_record" name="teamleague_championship_record" rows="3" class="json-field"><?php echo htmlspecialchars((string) ($editPlayer['TeamLeague_Championship_Record'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <div class="form-group">
                <label for="teams_history">Teams history</label>
                <textarea id="teams_history" name="teams_history" rows="3" class="json-field"><?php echo htmlspecialchars((string) ($editPlayer['Teams_History'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save player</button>
                <a href="edit_player.php" class="btn btn-secondary">Back to list</a>
            </div>
        </form>
    </div>

    <div class="admin-panel">
        <h2 class="panel-only-title">Aliases</h2>
        <?php if (empty($editAliases)): ?>
        <p class="panel-note">No aliases yet.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Alias</th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($editAliases as $alias): ?>
                    <tr>
                        <td>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="action" value="update_alias">
                                <input type="hidden" name="player_id" value="<?php echo (int) $editPlayer['Player_ID']; ?>">
                                <input type="hidden" name="alias_id" value="<?php echo (int) $alias['Alias_ID']; ?>">
                                <input type="text" name="alias_name" class="input-sm"
                                       value="<?php echo htmlspecialchars($alias['Alias_Name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                                <button type="submit" class="btn btn-edit btn-sm">Rename</button>
                            </form>
                        </td>
                        <td>
                            <form method="post" class="inline-form" onsubmit="return confirm('Delete this alias? Any FSL_STATISTICS rows tied to it will be removed.');">
                                <input type="hidden" name="action" value="delete_alias">
                                <input type="hidden" name="player_id" value="<?php echo (int) $editPlayer['Player_ID']; ?>">
                                <input type="hidden" name="alias_id" value="<?php echo (int) $alias['Alias_ID']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <form method="post" class="inline-form add-alias-form">
            <input type="hidden" name="action" value="add_alias">
            <input type="hidden" name="player_id" value="<?php echo (int) $editPlayer['Player_ID']; ?>">
            <input type="text" name="alias_name" class="input-sm" placeholder="New alias name" required>
            <button type="submit" class="btn btn-link btn-sm">Add alias</button>
        </form>
    </div>

    <?php elseif ($editPlayerId > 0): ?>
    <div class="alert alert-error">Player not found.</div>
    <?php endif; ?>

    <?php if (!$editPlayer): ?>
    <div class="admin-panel">
        <h2 class="panel-only-title">Add player</h2>
        <form method="post">
            <input type="hidden" name="action" value="add_player">
            <div class="form-row">
                <div class="form-group">
                    <label for="new_real_name">Name</label>
                    <input type="text" id="new_real_name" name="real_name" required>
                </div>
                <div class="form-group">
                    <label for="new_alias_name">Primary alias</label>
                    <input type="text" id="new_alias_name" name="alias_name" required>
                </div>
                <div class="form-group">
                    <label for="new_race">Race</label>
                    <select id="new_race" name="race">
                        <?php foreach ($races as $code => $label): ?>
                        <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php echo $code === 'R' ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="new_status">Status</label>
                    <select id="new_status" name="status">
                        <?php foreach ($statusOptions as $opt): ?>
                        <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucfirst($opt), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="new_team_id">Team</label>
                    <select id="new_team_id" name="team_id">
                        <option value="">No team</option>
                        <?php foreach ($teams as $tid => $tname): ?>
                        <option value="<?php echo (int) $tid; ?>"><?php echo htmlspecialchars($tname, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="intro-url-block">
                <label for="new_intro_url">Intro video URL</label>
                <input type="text" id="new_intro_url" name="intro_url" placeholder="https://…">
            </div>
            <button type="submit" class="btn btn-primary">Add player</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="admin-panel">
        <h2 class="panel-only-title">All players</h2>
        <form method="get" class="search-form">
            <?php if ($editPlayerId > 0): ?>
            <input type="hidden" name="player_id" value="<?php echo $editPlayerId; ?>">
            <?php endif; ?>
            <div class="search-row">
                <input type="text" name="search" placeholder="Search name or alias"
                       value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="btn btn-primary">Search</button>
                <a href="edit_player.php" class="btn btn-secondary">Clear</a>
            </div>
        </form>

        <?php if (empty($playerList)): ?>
        <p class="panel-note">No players found.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Team</th>
                        <th>Race</th>
                        <th>Intro</th>
                        <th>Status</th>
                        <th class="col-actions"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($playerList as $row): ?>
                    <?php
                    $rowIntro = trim((string) ($row['Intro_Url'] ?? ''));
                    $isEditingRow = $editPlayerId === (int) $row['Player_ID'];
                    ?>
                    <tr class="<?php echo $isEditingRow ? 'row-active' : ''; ?>">
                        <td><?php echo htmlspecialchars($row['Real_Name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['Team_Name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['Race'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="intro-cell">
                            <?php if ($rowIntro !== ''): ?>
                            <a href="<?php echo htmlspecialchars($rowIntro, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" title="<?php echo htmlspecialchars($rowIntro, ENT_QUOTES, 'UTF-8'); ?>">Set</a>
                            <?php else: ?>
                            <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars(ucfirst((string) $row['Status']), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="col-actions">
                            <a href="edit_player.php?player_id=<?php echo (int) $row['Player_ID']; ?>" class="btn btn-edit btn-sm">Edit</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav class="pagination-nav" aria-label="Player pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a class="page-num <?php echo $i === $page ? 'active' : ''; ?>"
               href="edit_player.php?page=<?php echo $i; ?>&amp;search=<?php echo urlencode($searchQuery); ?><?php echo $editPlayerId > 0 ? '&amp;player_id=' . $editPlayerId : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<style>
    .edit-player-admin {
        max-width: 1200px;
        margin: 0 auto;
    }

    .edit-player-admin .admin-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 20px;
        margin-bottom: 24px;
    }

    .edit-player-admin h1 {
        color: #00d4ff;
        text-shadow: 0 0 15px rgba(0, 212, 255, 0.4);
        font-size: 2rem;
        margin: 0 0 8px;
    }

    .edit-player-admin .page-lead,
    .edit-player-admin .panel-note {
        color: #ccc;
        margin: 0;
        font-size: 0.95rem;
    }

    .edit-player-admin .page-lead a,
    .edit-player-admin .panel-note a {
        color: #00d4ff;
    }

    .edit-player-admin .admin-user-info {
        display: flex;
        align-items: center;
        gap: 12px;
        color: #ccc;
        flex-shrink: 0;
    }

    .edit-player-admin .admin-panel {
        background: rgba(255, 255, 255, 0.08);
        border: 1px solid rgba(0, 212, 255, 0.25);
        border-radius: 10px;
        padding: 24px;
        margin-bottom: 24px;
    }

    .edit-player-admin .panel-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-bottom: 20px;
    }

    .edit-player-admin h2,
    .edit-player-admin .panel-only-title {
        color: #00d4ff;
        font-size: 1.35rem;
        margin: 0 0 16px;
    }

    .edit-player-admin .panel-header h2 {
        margin: 0;
    }

    .edit-player-admin .section-title {
        color: #7ee8ff;
        font-size: 1rem;
        margin: 20px 0 12px;
        padding-bottom: 6px;
        border-bottom: 1px solid rgba(0, 212, 255, 0.2);
    }

    .edit-player-admin .intro-url-block {
        background: rgba(0, 0, 0, 0.35);
        border: 1px solid rgba(0, 212, 255, 0.35);
        border-radius: 8px;
        padding: 16px 18px;
        margin: 8px 0 20px;
    }

    .edit-player-admin .intro-url-block .section-title {
        margin-top: 0;
        border-bottom: none;
        padding-bottom: 0;
    }

    .edit-player-admin .intro-preview {
        margin: 10px 0 0;
        font-size: 0.9rem;
        word-break: break-all;
    }

    .edit-player-admin .intro-preview a {
        color: #ffc107;
    }

    .edit-player-admin .intro-preview-empty {
        color: #888;
        font-style: italic;
    }

    .edit-player-admin .form-row {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
    }

    .edit-player-admin .form-group {
        flex: 1;
        min-width: 180px;
        margin-bottom: 16px;
    }

    .edit-player-admin label {
        display: block;
        margin-bottom: 6px;
        color: #00d4ff;
        font-weight: 600;
    }

    .edit-player-admin input[type="text"],
    .edit-player-admin input[type="url"],
    .edit-player-admin select,
    .edit-player-admin textarea {
        width: 100%;
        padding: 10px 12px;
        border-radius: 4px;
        border: 1px solid rgba(0, 212, 255, 0.35);
        background: rgba(0, 0, 0, 0.45);
        color: #f0f0f0;
        box-sizing: border-box;
    }

    .edit-player-admin select option {
        background: #1a1a2e;
        color: #f0f0f0;
    }

    .edit-player-admin textarea.json-field {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        font-size: 0.88rem;
    }

    .edit-player-admin small {
        display: block;
        margin-top: 6px;
        color: #999;
        font-size: 0.85rem;
    }

    .edit-player-admin code {
        color: #ffc107;
        background: rgba(0, 0, 0, 0.3);
        padding: 1px 4px;
        border-radius: 3px;
    }

    .edit-player-admin .form-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 8px;
    }

    .edit-player-admin .btn {
        padding: 8px 16px;
        border-radius: 4px;
        border: none;
        cursor: pointer;
        font-weight: 600;
        text-decoration: none;
        display: inline-block;
    }

    .edit-player-admin .btn-primary { background: #00d4ff; color: #0f0c29; }
    .edit-player-admin .btn-secondary { background: #6c757d; color: #fff; }
    .edit-player-admin .btn-edit { background: #ffc107; color: #0f0c29; }
    .edit-player-admin .btn-link { background: #28a745; color: #fff; }
    .edit-player-admin .btn-danger { background: #dc3545; color: #fff; }
    .edit-player-admin .btn-logout { background: #dc3545; color: #fff; }
    .edit-player-admin .btn-sm { padding: 5px 10px; font-size: 0.85rem; }

    .edit-player-admin .alert {
        padding: 14px 16px;
        margin-bottom: 20px;
        border-radius: 6px;
    }

    .edit-player-admin .alert-success {
        background: rgba(40, 167, 69, 0.2);
        border: 1px solid #28a745;
        color: #6fcf97;
    }

    .edit-player-admin .alert-error {
        background: rgba(220, 53, 69, 0.2);
        border: 1px solid #dc3545;
        color: #f5a5ad;
    }

    .edit-player-admin .table-wrap {
        overflow-x: auto;
        margin-bottom: 16px;
    }

    .edit-player-admin .admin-table {
        width: 100%;
        border-collapse: collapse;
        color: #e0e0e0;
    }

    .edit-player-admin .admin-table th,
    .edit-player-admin .admin-table td {
        padding: 10px 12px;
        border: 1px solid rgba(0, 212, 255, 0.2);
        vertical-align: middle;
    }

    .edit-player-admin .admin-table th {
        background: rgba(0, 212, 255, 0.12);
        color: #00d4ff;
        text-align: left;
    }

    .edit-player-admin .admin-table tbody tr:nth-child(even) {
        background: rgba(0, 0, 0, 0.2);
    }

    .edit-player-admin .admin-table tr.row-active {
        background: rgba(0, 212, 255, 0.12);
    }

    .edit-player-admin .col-actions {
        width: 100px;
        text-align: right;
    }

    .edit-player-admin .inline-form {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }

    .edit-player-admin .input-sm {
        flex: 1;
        min-width: 140px;
        padding: 6px 10px;
        border-radius: 4px;
        border: 1px solid rgba(0, 212, 255, 0.35);
        background: rgba(0, 0, 0, 0.45);
        color: #f0f0f0;
    }

    .edit-player-admin .add-alias-form {
        margin-top: 12px;
    }

    .edit-player-admin .search-form {
        margin-bottom: 16px;
    }

    .edit-player-admin .search-row {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .edit-player-admin .search-row input[type="text"] {
        flex: 1;
        min-width: 200px;
        padding: 10px 12px;
        border-radius: 4px;
        border: 1px solid rgba(0, 212, 255, 0.35);
        background: rgba(0, 0, 0, 0.45);
        color: #f0f0f0;
    }

    .edit-player-admin .intro-cell a {
        color: #ffc107;
    }

    .edit-player-admin .muted {
        color: #777;
    }

    .edit-player-admin .pagination-nav {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 12px;
    }

    .edit-player-admin .page-num {
        padding: 6px 12px;
        border-radius: 4px;
        background: rgba(0, 0, 0, 0.35);
        border: 1px solid rgba(0, 212, 255, 0.25);
        color: #ccc;
        text-decoration: none;
    }

    .edit-player-admin .page-num.active {
        background: #00d4ff;
        color: #0f0c29;
        border-color: #00d4ff;
    }

    @media (max-width: 768px) {
        .edit-player-admin .admin-header {
            flex-direction: column;
        }
    }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
