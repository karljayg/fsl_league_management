<?php
/**
 * TEMPORARY bulk helper: suggests site users → FSL players (unlinked only) by name match.
 * Same permission + link semantics as admin_link_user_player.php.
 *
 * Delete this file from the server when you are done.
 */

session_start();
require_once __DIR__ . '/includes/db.php';

function bulk_temp_can_link(PDO $db, string $uid): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM ws_user_roles ur
         JOIN ws_role_permissions rp ON ur.role_id = rp.role_id
         JOIN ws_permissions p ON rp.permission_id = p.permission_id
         WHERE ur.user_id = ? AND p.permission_name = 'edit player, team, stats'"
    );
    $stmt->execute([$uid]);
    return (int) $stmt->fetchColumn() > 0;
}

/** Normalize for loose equality (trim, lower, collapse spaces). */
function bulk_temp_norm(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/\s+/', ' ', $s);

    return $s;
}

/**
 * Keys to try against Real_Name / aliases: full username, and Battletag base before #.
 *
 * @return list<string>
 */
function bulk_temp_username_keys(string $username): array
{
    $keys = [bulk_temp_norm($username)];
    if (strpos($username, '#') !== false) {
        $base = bulk_temp_norm(explode('#', $username, 2)[0]);
        if ($base !== '') {
            $keys[] = $base;
        }
    }

    return array_values(array_unique(array_filter($keys, static function ($k) {
        return $k !== '';
    })));
}

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    die('Permission denied: user not logged in.');
}

if (!bulk_temp_can_link($db, $_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    die('Permission denied: You do not have permission to use this tool.');
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'link') {
    try {
        $userId = isset($_POST['user_id']) ? trim((string) $_POST['user_id']) : '';
        $playerId = isset($_POST['player_id']) ? (int) $_POST['player_id'] : 0;

        if ($userId === '' || $playerId <= 0) {
            throw new Exception('Invalid user or player.');
        }

        $checkUser = $db->prepare('SELECT id, username, email FROM users WHERE id = ?');
        $checkUser->execute([$userId]);
        $userRow = $checkUser->fetch(PDO::FETCH_ASSOC);
        if (!$userRow) {
            throw new Exception('That site user does not exist.');
        }

        $checkPlayer = $db->prepare('SELECT Player_ID, Real_Name, User_ID FROM Players WHERE Player_ID = ?');
        $checkPlayer->execute([$playerId]);
        $playerRow = $checkPlayer->fetch(PDO::FETCH_ASSOC);
        if (!$playerRow) {
            throw new Exception('That FSL player does not exist.');
        }
        if ($playerRow['User_ID'] !== null && (string) $playerRow['User_ID'] !== '') {
            throw new Exception('That player is already linked to another account. Unlink first if you meant to move it.');
        }

        $already = $db->prepare('SELECT Player_ID FROM Players WHERE User_ID = ? LIMIT 1');
        $already->execute([$userId]);
        if ($already->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception('That site user is already linked to an FSL player. Unlink on the main link page first if you meant to change it.');
        }

        $db->beginTransaction();
        $clear = $db->prepare('UPDATE Players SET User_ID = NULL WHERE User_ID = ? AND Player_ID <> ?');
        $clear->execute([$userId, $playerId]);
        $assign = $db->prepare('UPDATE Players SET User_ID = ? WHERE Player_ID = ?');
        $assign->execute([$userId, $playerId]);
        $db->commit();

        $message = 'Linked '
            . htmlspecialchars($userRow['username'], ENT_QUOTES, 'UTF-8')
            . ' → '
            . htmlspecialchars($playerRow['Real_Name'], ENT_QUOTES, 'UTF-8')
            . '.';
        $messageType = 'success';
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

// --- Build suggestions (only users with no link yet; only players with User_ID NULL) ---

$usersStmt = $db->query(
    "SELECT u.id, u.username, u.email
     FROM users u
     WHERE NOT EXISTS (SELECT 1 FROM Players p WHERE p.User_ID = u.id)
     ORDER BY u.username"
);
$unlinkedUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

$playersStmt = $db->query(
    'SELECT p.Player_ID, p.Real_Name FROM Players p WHERE p.User_ID IS NULL ORDER BY p.Real_Name'
);
$unlinkedPlayers = $playersStmt->fetchAll(PDO::FETCH_ASSOC);

$aliasStmt = $db->query('SELECT Player_ID, Alias_Name FROM Player_Aliases');
$aliasesByPlayer = [];
while ($row = $aliasStmt->fetch(PDO::FETCH_ASSOC)) {
    $pid = (int) $row['Player_ID'];
    if (!isset($aliasesByPlayer[$pid])) {
        $aliasesByPlayer[$pid] = [];
    }
    $aliasesByPlayer[$pid][] = (string) $row['Alias_Name'];
}

$playerIndex = [];
foreach ($unlinkedPlayers as $p) {
    $pid = (int) $p['Player_ID'];
    $playerIndex[$pid] = [
        'Player_ID' => $pid,
        'Real_Name' => (string) $p['Real_Name'],
        'norm_real' => bulk_temp_norm((string) $p['Real_Name']),
        'norm_aliases' => array_map('bulk_temp_norm', $aliasesByPlayer[$pid] ?? []),
    ];
}

$suggestions = [];

foreach ($unlinkedUsers as $u) {
    $uid = (string) $u['id'];
    $uname = (string) $u['username'];
    $keys = bulk_temp_username_keys($uname);

    foreach ($playerIndex as $pid => $info) {
        $reasons = [];
        foreach ($keys as $k) {
            if ($k !== '' && $k === $info['norm_real']) {
                $reasons['real_name'] = true;
            }
            foreach ($info['norm_aliases'] as $na) {
                if ($k !== '' && $k === $na) {
                    $reasons['alias'] = true;
                }
            }
        }
        if ($reasons === []) {
            continue;
        }
        $rank = isset($reasons['real_name']) ? 0 : 1;
        $label = isset($reasons['real_name']) ? 'Real name' : 'Alias';
        if (isset($reasons['real_name']) && isset($reasons['alias'])) {
            $label = 'Real name + alias';
        }
        $suggestions[] = [
            'user_id' => $uid,
            'username' => $uname,
            'email' => (string) $u['email'],
            'player_id' => $pid,
            'real_name' => $info['Real_Name'],
            'match_label' => $label,
            'rank' => $rank,
        ];
    }
}

usort($suggestions, static function ($a, $b) {
    $c = strcmp($a['username'], $b['username']);
    if ($c !== 0) {
        return $c;
    }
    if ($a['rank'] !== $b['rank']) {
        return $a['rank'] <=> $b['rank'];
    }

    return $a['player_id'] <=> $b['player_id'];
});

$pageTitle = 'Bulk link suggestions (temp)';
require_once __DIR__ . '/includes/header.php';
?>

<div class="alert alert-warning" role="alert">
    <strong>Temporary tool.</strong> Delete <code>admin_link_user_player_bulk_temp.php</code> from the server when you are finished.
    Regular linking is unchanged: <a href="admin_link_user_player.php">admin_link_user_player.php</a>.
</div>

<div class="mb-3">
    <h1 class="h3">Suggested user → player links</h1>
    <p class="text-muted mb-0">
        Only <strong>users not yet linked</strong> to any player, matched to <strong>players with no <code>User_ID</code></strong>,
        by normalized username vs <code>Real_Name</code> or <code>Player_Aliases</code> (also tries Battletag-style <code>Name#123</code> as <code>Name</code>).
        Each row is one possible link; click <strong>OK</strong> to apply it.
    </p>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>" role="alert">
        <?php echo $messageType === 'success' ? $message : htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Suggestions (<?php echo count($suggestions); ?>)</span>
    </div>
    <div class="card-body p-0">
        <?php if ($suggestions === []): ?>
            <p class="p-3 mb-0 text-muted">No automatic matches for unlinked users. Use the manual page or add aliases / align names.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                    <thead>
                        <tr>
                            <th>Site user</th>
                            <th>Email</th>
                            <th>→ FSL player</th>
                            <th>Match</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($suggestions as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="small"><?php echo htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <a href="view_player.php?name=<?php echo urlencode($row['real_name']); ?>">
                                        <?php echo htmlspecialchars($row['real_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </td>
                                <td><span class="badge badge-secondary"><?php echo htmlspecialchars($row['match_label'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td class="text-right">
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="link">
                                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($row['user_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="player_id" value="<?php echo (int) $row['player_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-primary">OK</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<p class="small text-muted mb-0">
    If one username matches several players, you will see multiple rows — only OK the correct one.
    This page refuses to link if the user already has a player or the player already has an account.
</p>

<?php
require_once __DIR__ . '/includes/footer.php';
