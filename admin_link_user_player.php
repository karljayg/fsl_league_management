<?php
/**
 * Admin: manually link a registered site user to an FSL player (Players.User_ID).
 * Prevents self-service impersonation; staff verifies identity out of band.
 */

session_start();
require_once __DIR__ . '/includes/db.php';

function admin_can_link_user_player(PDO $db, string $uid): bool
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

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    die('Permission denied: user not logged in.');
}

if (!admin_can_link_user_player($db, $_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    die('Permission denied: You do not have permission to link users to FSL players.');
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $db->beginTransaction();

        if ($_POST['action'] === 'link') {
            $userId = isset($_POST['user_id']) ? trim((string) $_POST['user_id']) : '';
            $playerId = isset($_POST['player_id']) ? (int) $_POST['player_id'] : 0;

            if ($userId === '' || $playerId <= 0) {
                throw new Exception('Choose both a site user and an FSL player.');
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
                throw new Exception('That FSL player is already linked. Unlink them in the table above first.');
            }

            $userLinked = $db->prepare('SELECT Player_ID FROM Players WHERE User_ID = ? LIMIT 1');
            $userLinked->execute([$userId]);
            if ($userLinked->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception('That site user is already linked to a player. Unlink the old link first.');
            }

            // One account → one player row (Players.User_ID is UNIQUE).
            $clear = $db->prepare('UPDATE Players SET User_ID = NULL WHERE User_ID = ? AND Player_ID <> ?');
            $clear->execute([$userId, $playerId]);

            $assign = $db->prepare('UPDATE Players SET User_ID = ? WHERE Player_ID = ?');
            $assign->execute([$userId, $playerId]);

            $db->commit();
            $message = 'Linked ' . htmlspecialchars($userRow['username'], ENT_QUOTES, 'UTF-8')
                . ' to FSL player ' . htmlspecialchars($playerRow['Real_Name'], ENT_QUOTES, 'UTF-8') . '.';
            $messageType = 'success';
        } elseif ($_POST['action'] === 'unlink') {
            $playerId = isset($_POST['player_id']) ? (int) $_POST['player_id'] : 0;
            if ($playerId <= 0) {
                throw new Exception('Invalid player.');
            }
            $stmt = $db->prepare('UPDATE Players SET User_ID = NULL WHERE Player_ID = ?');
            $stmt->execute([$playerId]);
            if ($stmt->rowCount() === 0) {
                throw new Exception('No link removed (player not found or already unlinked).');
            }
            $db->commit();
            $message = 'Player link removed.';
            $messageType = 'success';
        } else {
            throw new Exception('Unknown action.');
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

// Current links (player has User_ID set)
$linksStmt = $db->query(
    "SELECT p.Player_ID, p.Real_Name, p.User_ID, u.username, u.email
     FROM Players p
     INNER JOIN users u ON p.User_ID = u.id
     ORDER BY p.Real_Name"
);
$currentLinks = $linksStmt->fetchAll(PDO::FETCH_ASSOC);

// Dropdowns: only users not linked to any player, only players with no User_ID
$usersStmt = $db->query(
    "SELECT u.id, u.username, u.email
     FROM users u
     WHERE NOT EXISTS (SELECT 1 FROM Players p WHERE p.User_ID = u.id)
     ORDER BY u.username"
);
$dropdownUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

$playersStmt = $db->query(
    "SELECT p.Player_ID, p.Real_Name
     FROM Players p
     WHERE p.User_ID IS NULL
     ORDER BY p.Real_Name"
);
$dropdownPlayers = $playersStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Link site user to FSL player';
require_once __DIR__ . '/includes/header.php';
?>

<div class="mb-3">
    <h1 class="h3">Link site account to FSL player</h1>
    <p class="text-muted mb-0">
        Manually tie a registered user to a row in <code>Players</code> so their account matches league data (e.g. on <code>view_player.php</code>).
        Only staff should use this after verifying the person’s identity.
    </p>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>" role="alert">
        <?php echo $messageType === 'success' ? $message : htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header">Current links</div>
    <div class="card-body p-0">
        <?php if (empty($currentLinks)): ?>
            <p class="p-3 mb-0 text-muted">No players are linked to site accounts yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                    <thead>
                        <tr>
                            <th>FSL player</th>
                            <th>Site user</th>
                            <th>Email</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($currentLinks as $row): ?>
                            <tr>
                                <td>
                                    <a href="view_player.php?name=<?php echo urlencode($row['Real_Name']); ?>">
                                        <?php echo htmlspecialchars($row['Real_Name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-right">
                                    <form method="post" class="d-inline" onsubmit="return confirm('Remove this link?');">
                                        <input type="hidden" name="action" value="unlink">
                                        <input type="hidden" name="player_id" value="<?php echo (int) $row['Player_ID']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Unlink</button>
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

<div class="card">
    <div class="card-header">New link (unlinked only)</div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Lists only site users <strong>not</strong> tied to any player yet, and FSL players with <strong>no</strong> account link.
            To move a link, use <strong>Unlink</strong> in the table above, then link here again.
            Saving sets <code>Players.User_ID</code> on the chosen player row.
        </p>
        <?php if (empty($dropdownUsers) || empty($dropdownPlayers)): ?>
            <p class="text-muted mb-0">
                <?php if (empty($dropdownUsers) && empty($dropdownPlayers)): ?>
                    No unlinked site users and no unlinked players — nothing to pair here.
                <?php elseif (empty($dropdownUsers)): ?>
                    Every site user is already linked to a player. Unlink someone above if you need to reassign.
                <?php else: ?>
                    Every FSL player row has an account link, or there are no player rows. Unlink a player above if you need a name in this list.
                <?php endif; ?>
            </p>
        <?php else: ?>
        <form method="post" onsubmit="return confirm('Confirm: link this site user to this FSL player?');">
            <input type="hidden" name="action" value="link">
            <div class="form-group">
                <label for="user_id">Site user <span class="text-muted font-weight-normal">(unlinked)</span></label>
                <select name="user_id" id="user_id" class="form-control" required>
                    <option value="">— Select user —</option>
                    <?php foreach ($dropdownUsers as $u): ?>
                        <option value="<?php echo htmlspecialchars($u['id'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8'); ?>
                            &lt;<?php echo htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8'); ?>&gt;
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="player_id">FSL player <span class="text-muted font-weight-normal">(unlinked)</span></label>
                <select name="player_id" id="player_id" class="form-control" required>
                    <option value="">— Select player —</option>
                    <?php foreach ($dropdownPlayers as $p): ?>
                        <option value="<?php echo (int) $p['Player_ID']; ?>">
                            <?php echo htmlspecialchars($p['Real_Name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Save link</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
