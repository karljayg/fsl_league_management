<?php
/**
 * Player Rankings Page
 * Display and manage player power rankings
 */

ob_start();
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/voting_logic.php';

$rankingsFile = __DIR__ . '/rankings.json';
$configFile = __DIR__ . '/rankings_config.json';

$canEdit = false;
$canVote = false;
if (isset($_SESSION['user_id'])) {
    $uid = (string) $_SESSION['user_id'];
    $canEdit = rankings_user_has_permission($db, $uid, RANKINGS_PERM_SUPER);
    $canVote = rankings_user_has_permission($db, $uid, RANKINGS_PERM_VOTE);
}

$votingSession = rankings_voting_load_session();
$canonicalLocked = rankings_voting_blocks_canonical_edit($votingSession);
$votingCollectingNow = rankings_voting_is_collecting_now($votingSession);
$canEditCanonical = $canEdit && !$canonicalLocked;

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    $votingActions = [
        'voting_start', 'voting_close', 'voting_submit_ballot', 'voting_apply',
        'voting_publish', 'voting_discard_preview', 'voting_cancel',
    ];

    if (in_array($action, $votingActions, true)) {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            exit;
        }
        $uid = (string) $_SESSION['user_id'];
        $username = (string) ($_SESSION['username'] ?? $uid);

        $jsonFail = static function (string $msg): void {
            echo json_encode(['success' => false, 'error' => $msg]);
            exit;
        };

        if ($action === 'voting_start') {
            if (!rankings_user_has_permission($db, $uid, RANKINGS_PERM_SUPER)) {
                $jsonFail('Permission denied');
            }
            if (rankings_voting_load_session() !== null) {
                $jsonFail('A voting session is already active. Close, publish, or cancel it first.');
            }
            $opensRaw = trim((string) ($input['opens_at'] ?? ''));
            $duration = (float) ($input['duration_hours'] ?? 24);
            if ($opensRaw === '' || $duration <= 0 || $duration > 8760) {
                $jsonFail('Invalid opens_at or duration_hours');
            }
            $opensTs = strtotime($opensRaw);
            if ($opensTs === false) {
                $jsonFail('Could not parse opens_at');
            }
            $closesTs = (int) round($opensTs + (float) $duration * 3600);
            $baseline = json_decode((string) file_get_contents($rankingsFile), true);
            if (!is_array($baseline) || $baseline === []) {
                $jsonFail('No baseline rankings to vote on');
            }
            $sid = bin2hex(random_bytes(8));
            $session = [
                'id' => $sid,
                'status' => 'collecting',
                'opens_at' => gmdate('c', $opensTs),
                'closes_at' => gmdate('c', $closesTs),
                'duration_hours' => $duration,
                'baseline' => $baseline,
                'preview_rankings' => null,
                'created_at' => gmdate('c'),
            ];
            if (!rankings_voting_save_session($session)) {
                $jsonFail('Could not save voting session');
            }
            rankings_voting_ballots_save($sid, []);
            rankings_voting_append_log(
                "SESSION START id={$sid} opens={$session['opens_at']} closes={$session['closes_at']} duration_h={$duration} by user_id={$uid} username={$username}"
            );
            echo json_encode(['success' => true, 'session' => ['id' => $sid, 'status' => 'collecting']]);
            exit;
        }

        if ($action === 'voting_close') {
            if (!rankings_user_has_permission($db, $uid, RANKINGS_PERM_SUPER)) {
                $jsonFail('Permission denied');
            }
            $sess = rankings_voting_load_session();
            if ($sess === null || ($sess['status'] ?? '') !== 'collecting') {
                $jsonFail('No open voting session to close');
            }
            $sess['status'] = 'closed';
            $sess['closes_at'] = gmdate('c', time());
            $sess['closed_at'] = gmdate('c');
            rankings_voting_save_session($sess);
            rankings_voting_append_log(
                'SESSION CLOSED id=' . ($sess['id'] ?? '') . ' by user_id=' . $uid . ' username=' . $username
            );
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'voting_submit_ballot') {
            if (!$canVote) {
                $jsonFail('Permission denied');
            }
            $sess = rankings_voting_load_session();
            if (!rankings_voting_is_collecting_now($sess)) {
                $jsonFail('Voting is not open for ballots right now');
            }
            $ordered = $input['ordered_names'] ?? [];
            if (!is_array($ordered)) {
                $jsonFail('Invalid ballot');
            }
            $ordered = array_map('strval', $ordered);
            $baseline = $sess['baseline'] ?? [];
            if (!is_array($baseline)) {
                $jsonFail('Invalid session baseline');
            }
            $deltas = rankings_voting_deltas_from_order($baseline, $ordered);
            if ($deltas === []) {
                $jsonFail('Ballot does not match the voting list');
            }
            $ballots = rankings_voting_ballots_load((string) $sess['id']);
            $newBallot = [
                'user_id' => $uid,
                'username' => $username,
                'submitted_at' => gmdate('c'),
                'deltas' => $deltas,
            ];
            $filtered = array_values(array_filter($ballots, static function ($b) use ($uid): bool {
                return (string) ($b['user_id'] ?? '') !== $uid;
            }));
            $filtered[] = $newBallot;
            if (!rankings_voting_ballots_save((string) $sess['id'], $filtered)) {
                $jsonFail('Could not save ballot');
            }
            $changesForLog = rankings_voting_nonzero_deltas_only($deltas);
            rankings_voting_append_log(
                'BALLOT user_id=' . $uid . ' username=' . $username . ' session=' . ($sess['id'] ?? '')
                . ' changes=' . json_encode($changesForLog, JSON_UNESCAPED_UNICODE)
            );
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'voting_apply') {
            if (!rankings_user_has_permission($db, $uid, RANKINGS_PERM_SUPER)) {
                $jsonFail('Permission denied');
            }
            $sess = rankings_voting_load_session();
            if ($sess === null || ($sess['status'] ?? '') !== 'closed') {
                $jsonFail('Apply is only available after voting is closed (and preview not yet built)');
            }
            $baseline = $sess['baseline'] ?? [];
            if (!is_array($baseline)) {
                $jsonFail('Missing baseline');
            }
            $ballots = rankings_voting_ballots_load((string) ($sess['id'] ?? ''));
            $agg = rankings_voting_aggregate($baseline, $ballots);
            $preview = rankings_voting_merge_apply_deltas($baseline, $agg['applied']);
            $sess['preview_rankings'] = $preview;
            $sess['status'] = 'preview';
            $sess['applied_deltas'] = $agg['applied'];
            rankings_voting_save_session($sess);

            $logBlock = "APPLY session=" . ($sess['id'] ?? '') . " by user_id={$uid} username={$username}\n";
            $logBlock .= implode("\n", $agg['details']) . "\n";
            $logBlock .= 'Ballots (' . count($ballots) . "), non-zero deltas only:\n";
            foreach ($ballots as $b) {
                $raw = is_array($b['deltas'] ?? null) ? $b['deltas'] : [];
                $nz = rankings_voting_nonzero_deltas_only($raw);
                $logBlock .= '  ' . ($b['username'] ?? '') . ' @ ' . ($b['submitted_at'] ?? '')
                    . ' ' . json_encode($nz, JSON_UNESCAPED_UNICODE) . "\n";
            }
            rankings_voting_append_log($logBlock);

            echo json_encode(['success' => true, 'preview_count' => count($preview)]);
            exit;
        }

        if ($action === 'voting_discard_preview') {
            if (!rankings_user_has_permission($db, $uid, RANKINGS_PERM_SUPER)) {
                $jsonFail('Permission denied');
            }
            $sess = rankings_voting_load_session();
            if ($sess === null || ($sess['status'] ?? '') !== 'preview') {
                $jsonFail('No preview to discard');
            }
            $sess['status'] = 'closed';
            $sess['preview_rankings'] = null;
            unset($sess['applied_deltas']);
            rankings_voting_save_session($sess);
            rankings_voting_append_log('DISCARD PREVIEW session=' . ($sess['id'] ?? '') . " by user_id={$uid}");
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'voting_publish') {
            if (!rankings_user_has_permission($db, $uid, RANKINGS_PERM_SUPER)) {
                $jsonFail('Permission denied');
            }
            $sess = rankings_voting_load_session();
            if ($sess === null || ($sess['status'] ?? '') !== 'preview') {
                $jsonFail('Nothing to publish (apply first)');
            }
            $preview = $sess['preview_rankings'] ?? null;
            if (!is_array($preview) || $preview === []) {
                $jsonFail('Invalid preview');
            }
            $previousPublished = [];
            if (is_readable($rankingsFile)) {
                $prevRaw = file_get_contents($rankingsFile);
                $decoded = is_string($prevRaw) ? json_decode($prevRaw, true) : null;
                if (is_array($decoded)) {
                    $previousPublished = $decoded;
                }
            }
            if (@file_put_contents($rankingsFile, json_encode($preview, JSON_PRETTY_PRINT), LOCK_EX) === false) {
                $jsonFail('Could not write rankings.json');
            }
            $pubAt = gmdate('c');
            rankings_voting_save_snapshot($previousPublished, $pubAt);
            $sid = (string) ($sess['id'] ?? '');
            rankings_voting_append_log("PUBLISH session={$sid} by user_id={$uid} username={$username} at={$pubAt}");

            $dir = rankings_voting_sessions_dir($sid);
            if (is_dir($dir)) {
                $files = glob($dir . '/*') ?: [];
                foreach ($files as $f) {
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
                @rmdir($dir);
            }
            rankings_voting_clear_session();
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'voting_cancel') {
            if (!rankings_user_has_permission($db, $uid, RANKINGS_PERM_SUPER)) {
                $jsonFail('Permission denied');
            }
            $sess = rankings_voting_load_session();
            if ($sess === null) {
                $jsonFail('No active session');
            }
            $sid = (string) ($sess['id'] ?? '');
            rankings_voting_append_log("CANCEL session={$sid} by user_id={$uid} username={$username}");
            $dir = rankings_voting_sessions_dir($sid);
            if (is_dir($dir)) {
                $files = glob($dir . '/*') ?: [];
                foreach ($files as $f) {
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
                @rmdir($dir);
            }
            rankings_voting_clear_session();
            echo json_encode(['success' => true]);
            exit;
        }
    }

    if (!$canEditCanonical) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }

    if ($action === 'move') {
        $fromIndex = $input['from'] ?? -1;
        $toIndex = $input['to'] ?? -1;

        $rankings = json_decode(file_get_contents($rankingsFile), true);

        if ($fromIndex >= 0 && $fromIndex < count($rankings) &&
            $toIndex >= 0 && $toIndex < count($rankings)) {

            $player = array_splice($rankings, $fromIndex, 1)[0];
            $insertIndex = ($fromIndex < $toIndex) ? $toIndex - 1 : $toIndex;
            array_splice($rankings, $insertIndex, 0, [$player]);

            foreach ($rankings as $i => &$p) {
                $p['rank'] = $i + 1;
            }

            file_put_contents($rankingsFile, json_encode($rankings, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
            exit;
        }

        echo json_encode(['success' => false, 'error' => 'Invalid indices']);
        exit;
    }

    if ($action === 'update') {
        $rankings = $input['rankings'] ?? [];
        if (!empty($rankings)) {
            file_put_contents($rankingsFile, json_encode($rankings, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
            exit;
        }
        echo json_encode(['success' => false, 'error' => 'No rankings provided']);
        exit;
    }

    if ($action === 'save_code_tiers') {
        $config = [
            'codeS' => ['minRank' => (int) ($input['codeS_min'] ?? 1), 'maxRank' => (int) ($input['codeS_max'] ?? 24)],
            'codeA' => ['minRank' => (int) ($input['codeA_min'] ?? 25), 'maxRank' => (int) ($input['codeA_max'] ?? 36)],
            'codeB' => ['minRank' => (int) ($input['codeB_min'] ?? 37), 'maxRank' => (int) ($input['codeB_max'] ?? 46)],
        ];
        $result = @file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        if ($result === false) {
            echo json_encode(['success' => false, 'error' => 'Could not save config. Check that rankings folder is writable.']);
            exit;
        }
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// Load rankings
$rankings = [];
if (file_exists($rankingsFile)) {
    $rankings = json_decode(file_get_contents($rankingsFile), true) ?? [];
}

// Load code tier config (Code S, A, B rank ranges)
$totalPlayers = count($rankings);
$third = max(1, (int) ceil($totalPlayers / 3));
$codeTiers = [
    'codeS' => ['minRank' => 1, 'maxRank' => $third],
    'codeA' => ['minRank' => $third + 1, 'maxRank' => 2 * $third],
    'codeB' => ['minRank' => 2 * $third + 1, 'maxRank' => $totalPlayers]
];
if (file_exists($configFile)) {
    $loaded = json_decode(file_get_contents($configFile), true);
    if ($loaded) {
        foreach (['codeS', 'codeA', 'codeB'] as $tier) {
            if (isset($loaded[$tier]['minRank'], $loaded[$tier]['maxRank'])) {
                $codeTiers[$tier] = ['minRank' => (int)$loaded[$tier]['minRank'], 'maxRank' => (int)$loaded[$tier]['maxRank']];
            } elseif (isset($loaded[$tier]['minGroup'], $loaded[$tier]['maxGroup'])) {
                $codeTiers[$tier] = ['minRank' => ($loaded[$tier]['minGroup'] - 1) * 4 + 1, 'maxRank' => $loaded[$tier]['maxGroup'] * 4];
            }
        }
    }
}

$showVoterUi = $canVote && $votingCollectingNow;
$displayRankings = $rankings;
if ($showVoterUi && $votingSession !== null && !empty($votingSession['baseline']) && is_array($votingSession['baseline'])) {
    $displayRankings = $votingSession['baseline'];
}

$snapshotRanks = rankings_voting_load_snapshot_ranks();
$movementByName = rankings_voting_rank_movement_vs_snapshot($rankings, $snapshotRanks);

$ballotCountThisSession = 0;
$userHasBallot = false;
if ($votingSession !== null && !empty($votingSession['id'])) {
    $_ballots = rankings_voting_ballots_load((string) $votingSession['id']);
    $ballotCountThisSession = count($_ballots);
    if (isset($_SESSION['user_id'])) {
        foreach ($_ballots as $_b) {
            if ((string) ($_b['user_id'] ?? '') === (string) $_SESSION['user_id']) {
                $userHasBallot = true;
                break;
            }
        }
    }
}
$sessionStatus = (string) ($votingSession['status'] ?? '');

$raceIcons = [
    'T' => 'terran_icon.png',
    'P' => 'protoss_icon.png', 
    'Z' => 'zerg_icon.png',
    'R' => 'random_icon.png'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../images/favicon.png" type="image/png">
    <title>Player Rankings - FSL</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&family=Exo+2:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        body {
            background: linear-gradient(135deg, #0a0a0f 0%, #1a1a2e 50%, #0a0a0f 100%);
            min-height: 100vh;
            color: #fff;
        }
        
        .rankings-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .rankings-header {
            text-align: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(108, 92, 231, 0.3);
        }
        
        .rankings-header h1 {
            font-family: 'Rajdhani', sans-serif;
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-transform: uppercase;
            letter-spacing: 3px;
            margin: 0;
        }
        
        .rankings-header p {
            color: #888;
            margin-top: 0.5rem;
        }
        
        .rankings-list {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 10px;
            padding: 1rem;
        }
        
        .player-row {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 8px;
            margin-bottom: 0.5rem;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        
        .player-row:hover {
            background: rgba(108, 92, 231, 0.15);
        }
        
        /* Skill tier band - left edge indicator */
        .skill-band {
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 10px;
            border-radius: 8px 0 0 8px;
        }
        
        /* Group colors - gradient from bright to dark */
        .skill-band.g1  { background: linear-gradient(180deg, #00d4aa, #00b894); }
        .skill-band.g2  { background: linear-gradient(180deg, #00b894, #00a187); }
        .skill-band.g3  { background: linear-gradient(180deg, #00a187, #008f7a); }
        .skill-band.g4  { background: linear-gradient(180deg, #55a3ff, #4a90e2); }
        .skill-band.g5  { background: linear-gradient(180deg, #4a90e2, #3d7bc7); }
        .skill-band.g6  { background: linear-gradient(180deg, #3d7bc7, #3066ac); }
        .skill-band.g7  { background: linear-gradient(180deg, #9b7fd4, #8b6fc4); }
        .skill-band.g8  { background: linear-gradient(180deg, #8b6fc4, #7b5fb4); }
        .skill-band.g9  { background: linear-gradient(180deg, #7b5fb4, #6b4fa4); }
        .skill-band.g10 { background: linear-gradient(180deg, #6b6b7b, #5b5b6b); }
        .skill-band.g11 { background: linear-gradient(180deg, #5b5b6b, #4b4b5b); }
        .skill-band.g12 { background: linear-gradient(180deg, #4b4b5b, #3b3b4b); }
        
        .player-row.dragging {
            opacity: 0.5;
            background: rgba(108, 92, 231, 0.3);
        }
        
        .player-row.drag-over {
            border-top: 2px solid #6c5ce7;
        }
        
        .rank-badge {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            border-radius: 50%;
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.1rem;
            font-weight: 700;
            color: #fff;
            margin-right: 1rem;
            flex-shrink: 0;
        }
        
        .player-name {
            font-family: 'Exo 2', sans-serif;
            font-size: 1.2rem;
            font-weight: 600;
            flex: 1;
        }
        
        .player-name a {
            color: #fff;
            text-decoration: none;
        }
        
        .player-name a:hover {
            color: #a29bfe;
        }

        .rank-move-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.15rem;
            margin-left: 0.35rem;
            font-size: 0.8rem;
            font-weight: 600;
            color: rgba(200, 200, 210, 0.55);
            vertical-align: middle;
        }
        .rank-move-indicator i {
            font-size: 0.72rem;
            opacity: 0.95;
        }

        .voting-admin-panel {
            background: rgba(0, 0, 0, 0.35);
            border-radius: 10px;
            border: 1px solid rgba(108, 92, 231, 0.25);
        }
        .voting-voter-banner {
            background: rgba(0, 184, 148, 0.12);
            border: 1px solid rgba(0, 184, 148, 0.35);
            border-radius: 10px;
            color: #b2dfdb;
            font-size: 0.95rem;
        }
        .player-row.vote-row-changed {
            box-shadow: inset 0 0 0 1px rgba(108, 92, 231, 0.35);
        }
        
        .race-icon {
            width: 28px;
            height: 28px;
            margin-right: 1rem;
        }
        
        .group-badge {
            font-size: 0.85rem;
            color: #6c5ce7;
            background: rgba(108, 92, 231, 0.2);
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            margin-right: 0.5rem;
        }
        
        .move-buttons {
            display: flex;
            gap: 0.25rem;
        }
        
        .move-btn {
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(108, 92, 231, 0.2);
            border: 1px solid rgba(108, 92, 231, 0.4);
            border-radius: 5px;
            color: #a29bfe;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .move-btn:hover {
            background: rgba(108, 92, 231, 0.4);
            color: #fff;
        }
        
        .move-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        
        .drag-handle {
            cursor: grab;
            padding: 0 0.5rem;
            color: #555;
            margin-right: 0.5rem;
        }
        
        .drag-handle:active {
            cursor: grabbing;
        }
        
        .edit-mode-toggle {
            text-align: center;
            margin-bottom: 1rem;
        }
        
        .edit-btn {
            padding: 0.5rem 1.5rem;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            border: none;
            border-radius: 5px;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .edit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(108, 92, 231, 0.4);
        }
        
        .rankings-with-indicator {
            display: flex;
            gap: 0;
            align-items: stretch;
        }
        
        .code-tier-strip {
            width: 28px;
            min-width: 28px;
            border-radius: 8px 0 0 8px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        .code-zone {
            flex: 1;
            min-height: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        
        .code-zone.code-s { background: linear-gradient(180deg, #ffd700, #ffb347); }
        .code-zone.code-a { background: linear-gradient(180deg, #c0c0c0, #a8a8a8); }
        .code-zone.code-b { background: linear-gradient(180deg, #cd7f32, #a0522d); }
        
        .code-zone-label {
            display: block;
            text-align: center;
            font-size: 1rem;
            font-weight: 800;
            color: rgba(0,0,0,0.85);
            line-height: 1.15;
            letter-spacing: 0.5px;
        }
        
        .code-tier-edit-btn {
            margin-left: 0.5rem;
            padding: 0.25rem 0.5rem;
            font-size: 0.85rem;
            background: rgba(108, 92, 231, 0.2);
            border: 1px solid rgba(108, 92, 231, 0.4);
            border-radius: 4px;
            color: #a29bfe;
            cursor: pointer;
        }
        
        .code-tier-edit-btn:hover { background: rgba(108, 92, 231, 0.4); color: #fff; }
        
        .code-tier-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.6);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .code-tier-modal.show { display: flex; }
        
        .code-tier-modal-inner {
            background: #1a1a2e;
            padding: 1.5rem;
            border-radius: 10px;
            border: 1px solid rgba(108, 92, 231, 0.3);
            min-width: 320px;
        }
        
        .code-tier-modal h3 { margin-top: 0; color: #a29bfe; }
        
        .code-tier-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; }
        
        .code-tier-row label { min-width: 70px; color: #ccc; }
        
        .code-tier-row input { width: 50px; padding: 0.35rem; background: #0a0a0f; border: 1px solid #333; color: #fff; border-radius: 4px; }
        
        .code-tier-modal-actions { margin-top: 1.5rem; display: flex; gap: 0.5rem; justify-content: flex-end; }
        
        .player-row[data-code="S"] { border-left: 3px solid #ffd700; }
        .player-row[data-code="A"] { border-left: 3px solid #c0c0c0; }
        .player-row[data-code="B"] { border-left: 3px solid #cd7f32; }
        
        .save-indicator {
            position: fixed;
            bottom: 1rem;
            right: 1rem;
            padding: 0.5rem 1rem;
            background: rgba(0, 184, 148, 0.9);
            border-radius: 5px;
            color: #fff;
            display: none;
        }
        
        .save-indicator.show {
            display: block;
            animation: fadeInOut 2s forwards;
        }
        
        @keyframes fadeInOut {
            0% { opacity: 0; transform: translateY(10px); }
            20% { opacity: 1; transform: translateY(0); }
            80% { opacity: 1; }
            100% { opacity: 0; }
        }
        
        
        @media (max-width: 768px) {
            .rankings-container { padding: 1rem; }
            .player-row { padding: 0.5rem; }
            .rank-badge { width: 32px; height: 32px; font-size: 0.9rem; }
            .player-name { font-size: 1rem; }
            .move-buttons { display: none; }
            .move-buttons.vote-move-buttons { display: flex; }
        }
    </style>
</head>
<body>
    <?php include_once '../includes/nav.php'; ?>
    
    <div class="rankings-container">
        <div class="rankings-header">
            <h1>KJ's Power Rankings</h1>
            <p>Code S · Code A · Code B
                <?php if ($canEditCanonical): ?>
                <button type="button" class="code-tier-edit-btn" onclick="openCodeTierModal()" title="Edit Code tier ranges"><i class="fas fa-cog"></i> Edit ranges</button>
                <?php endif; ?>
            </p>
        </div>

        <?php if ($canonicalLocked && $canEdit): ?>
        <p class="text-center text-warning small mb-2">
            <i class="fas fa-lock"></i> Official list is frozen while a community vote session is active (close, cancel, or publish to unlock).
        </p>
        <?php endif; ?>

        <?php if ($canEdit): ?>
        <div class="voting-admin-panel mb-3 p-3">
            <h5 class="text-secondary mb-2"><i class="fas fa-users"></i> Community vote</h5>
            <?php if ($votingSession === null): ?>
            <p class="small text-muted mb-2">Start a window. Voters with the &quot;rankings community vote&quot; permission can reorder a copy and submit a ballot. Nothing publishes until you apply and publish.</p>
            <div class="form-row align-items-end">
                <div class="col-md-5 mb-2">
                    <label class="small text-muted d-block">Opens (local time)</label>
                    <input type="datetime-local" class="form-control form-control-sm bg-dark text-white border-secondary" id="voteOpensAt" value="<?= htmlspecialchars(date('Y-m-d\TH:i')) ?>">
                </div>
                <div class="col-md-3 mb-2">
                    <label class="small text-muted d-block">Duration (hours)</label>
                    <input type="number" class="form-control form-control-sm bg-dark text-white border-secondary" id="voteDurationH" value="24" min="0.25" max="8760" step="0.25">
                </div>
                <div class="col-md-4 mb-2">
                    <button type="button" class="edit-btn btn-sm" onclick="votingStart()"><i class="fas fa-play"></i> Open voting window</button>
                </div>
            </div>
            <?php else: ?>
            <p class="small mb-2">
                <strong>Status:</strong> <?= htmlspecialchars($sessionStatus) ?>
                &middot; <strong>Session:</strong> <?= htmlspecialchars((string) ($votingSession['id'] ?? '')) ?>
                &middot; <strong>Ballots:</strong> <?= (int) $ballotCountThisSession ?>
                <?php if (!empty($votingSession['opens_at'])): ?>
                &middot; Opens <?= htmlspecialchars((string) $votingSession['opens_at']) ?>
                <?php endif; ?>
                <?php if (!empty($votingSession['closes_at'])): ?>
                &middot; Closes <?= htmlspecialchars((string) $votingSession['closes_at']) ?>
                <?php endif; ?>
            </p>
            <p class="small text-muted mb-2">Full audit: <code>rankings/voting_log.txt</code></p>
            <?php if ($sessionStatus === 'collecting' && !$votingCollectingNow && !empty($votingSession['opens_at']) && strtotime((string) $votingSession['opens_at']) > time()): ?>
            <p class="small text-info mb-2">Ballots are not accepted yet: opens time is still in the future.</p>
            <?php endif; ?>
            <?php if ($sessionStatus === 'preview'): ?>
            <p class="small text-warning mb-2">Preview is stored in the session (not live yet). This page still shows the published list until you publish.</p>
            <?php endif; ?>
            <div class="d-flex flex-wrap mt-2">
                <?php if ($sessionStatus === 'collecting'): ?>
                <button type="button" class="btn btn-sm btn-outline-warning mr-2 mb-2" onclick="votingClose()"><i class="fas fa-stop"></i> Close voting</button>
                <?php endif; ?>
                <?php if ($sessionStatus === 'closed'): ?>
                <button type="button" class="btn btn-sm btn-outline-info mr-2 mb-2" onclick="votingApply()"><i class="fas fa-calculator"></i> Apply (build preview)</button>
                <?php endif; ?>
                <?php if ($sessionStatus === 'preview'): ?>
                <button type="button" class="btn btn-sm btn-success mr-2 mb-2" onclick="votingPublish()"><i class="fas fa-check"></i> Publish to site</button>
                <button type="button" class="btn btn-sm btn-outline-secondary mr-2 mb-2" onclick="votingDiscardPreview()"><i class="fas fa-undo"></i> Discard preview</button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-danger mb-2" onclick="votingCancel()"><i class="fas fa-times"></i> Cancel session</button>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($showVoterUi): ?>
        <div class="voting-voter-banner mb-3 p-3">
            <strong><i class="fas fa-pen"></i> Voting is open.</strong>
            You are adjusting a <em>copy</em> for your ballot only; the public list does not change until the admin publishes.
            Drag rows or use arrows, then <strong>Send vote</strong>. You will not see others&apos; votes or results until they are official.
            <?php if ($userHasBallot): ?>
            <span class="d-block mt-1 text-white-50 small">You have already submitted a ballot; sending again replaces it.</span>
            <?php endif; ?>
        </div>
        <div class="edit-mode-toggle mb-3">
            <button type="button" class="edit-btn" id="sendVoteBtn" onclick="submitVoteBallot()"><i class="fas fa-paper-plane"></i> Send vote</button>
        </div>
        <?php endif; ?>
        
        <?php if ($canEditCanonical): ?>
        <div class="edit-mode-toggle">
            <button class="edit-btn" id="editModeBtn" onclick="toggleEditMode()">
                <i class="fas fa-edit"></i> Enable Edit Mode
            </button>
        </div>
        <?php endif; ?>
        
        <?php
        $sRows = $aRows = $bRows = 0;
        foreach ($displayRankings as $player) {
            $rank = (int) $player['rank'];
            if ($rank >= $codeTiers['codeS']['minRank'] && $rank <= $codeTiers['codeS']['maxRank']) {
                $sRows++;
            } elseif ($rank >= $codeTiers['codeA']['minRank'] && $rank <= $codeTiers['codeA']['maxRank']) {
                $aRows++;
            } else {
                $bRows++;
            }
        }
        $displayCount = count($displayRankings);
        ?>
        <div class="rankings-with-indicator">
            <div class="code-tier-strip" title="Code S: #<?= $codeTiers['codeS']['minRank'] ?>–<?= $codeTiers['codeS']['maxRank'] ?> · Code A: #<?= $codeTiers['codeA']['minRank'] ?>–<?= $codeTiers['codeA']['maxRank'] ?> · Code B: #<?= $codeTiers['codeB']['minRank'] ?>–<?= $codeTiers['codeB']['maxRank'] ?>">
                <div class="code-zone code-s" style="flex: <?= $sRows ?>"><span class="code-zone-label"><?= implode('<br>', str_split('Code')) ?><br><br>S</span></div>
                <div class="code-zone code-a" style="flex: <?= $aRows ?>"><span class="code-zone-label"><?= implode('<br>', str_split('Code')) ?><br><br>A</span></div>
                <div class="code-zone code-b" style="flex: <?= $bRows ?>"><span class="code-zone-label"><?= implode('<br>', str_split('Code')) ?><br><br>B</span></div>
            </div>
        <div class="rankings-list" id="rankingsList">
            <?php 
            foreach ($displayRankings as $index => $player):
                $rank = (int) $player['rank'];
                $group = (int) ceil($rank / 4);
                $code = ($rank >= $codeTiers['codeS']['minRank'] && $rank <= $codeTiers['codeS']['maxRank']) ? 'S' :
                    (($rank >= $codeTiers['codeA']['minRank'] && $rank <= $codeTiers['codeA']['maxRank']) ? 'A' : 'B');
                $pname = (string) $player['name'];
                $mvInt = isset($movementByName[$pname]) ? (int) $movementByName[$pname] : 0;
            ?>
                <div class="player-row" data-index="<?= $index ?>" data-code="<?= $code ?>" data-player-name="<?= htmlspecialchars($pname) ?>" draggable="false">
                    <div class="skill-band g<?= $group ?>"></div>
                    <?php if ($canEditCanonical): ?>
                    <span class="drag-handle edit-only" style="display: none;"><i class="fas fa-grip-vertical"></i></span>
                    <?php endif; ?>
                    <?php if ($showVoterUi): ?>
                    <span class="drag-handle vote-only" style="display: inline-block;"><i class="fas fa-grip-vertical"></i></span>
                    <?php endif; ?>
                    <span class="rank-badge"><?= $player['rank'] ?></span>
                    <img src="../images/<?= $raceIcons[$player['race']] ?? 'random_icon.png' ?>" alt="<?= htmlspecialchars((string) $player['race']) ?>" class="race-icon">
                    <span class="player-name">
                        <a href="../view_player.php?name=<?= urlencode($pname) ?>"><?= htmlspecialchars($pname) ?></a><?php
                        if ($mvInt !== 0) {
                            $up = $mvInt > 0;
                            echo '<span class="rank-move-indicator" title="Since last published rankings"><i class="fas ' . ($up ? 'fa-arrow-up' : 'fa-arrow-down') . '"></i><span>'
                                . ($up ? '+' . $mvInt : (string) $mvInt) . '</span></span>';
                        }
                        ?>
                    </span>
                    <span class="group-badge">G<?= $group ?></span>
                    <?php if ($canEditCanonical): ?>
                    <div class="move-buttons edit-only" style="display: none;">
                        <button class="move-btn move-up" data-index="<?= $index ?>" <?= $index === 0 ? 'disabled' : '' ?>>
                            <i class="fas fa-chevron-up"></i>
                        </button>
                        <button class="move-btn move-down" data-index="<?= $index ?>" <?= $index === $displayCount - 1 ? 'disabled' : '' ?>>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                    <?php if ($showVoterUi): ?>
                    <div class="move-buttons vote-move-buttons vote-only" style="display: flex;">
                        <button type="button" class="move-btn move-up" data-index="<?= $index ?>" <?= $index === 0 ? 'disabled' : '' ?>>
                            <i class="fas fa-chevron-up"></i>
                        </button>
                        <button type="button" class="move-btn move-down" data-index="<?= $index ?>" <?= $index === $displayCount - 1 ? 'disabled' : '' ?>>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        </div>
    </div>
    
    <div class="code-tier-modal" id="codeTierModal">
        <div class="code-tier-modal-inner">
            <h3>Code Tier Ranges</h3>
            <p class="text-muted" style="font-size: 0.9rem; margin-bottom: 1rem;">Use player rank numbers (1–<?= $totalPlayers ?>). Code S ends at rank 24 = players ranked 1–24.</p>
            <div class="code-tier-row">
                <label>Code S:</label>
                <span>rank</span><input type="number" id="codeS_min" min="1" max="<?= $totalPlayers ?>" value="<?= $codeTiers['codeS']['minRank'] ?>">
                <span>to</span><input type="number" id="codeS_max" min="1" max="<?= $totalPlayers ?>" value="<?= $codeTiers['codeS']['maxRank'] ?>">
            </div>
            <div class="code-tier-row">
                <label>Code A:</label>
                <span>rank</span><input type="number" id="codeA_min" min="1" max="<?= $totalPlayers ?>" value="<?= $codeTiers['codeA']['minRank'] ?>">
                <span>to</span><input type="number" id="codeA_max" min="1" max="<?= $totalPlayers ?>" value="<?= $codeTiers['codeA']['maxRank'] ?>">
            </div>
            <div class="code-tier-row">
                <label>Code B:</label>
                <span>rank</span><input type="number" id="codeB_min" min="1" max="<?= $totalPlayers ?>" value="<?= $codeTiers['codeB']['minRank'] ?>">
                <span>to</span><input type="number" id="codeB_max" min="1" max="<?= $totalPlayers ?>" value="<?= $codeTiers['codeB']['maxRank'] ?>">
            </div>
            <div class="code-tier-modal-actions">
                <button type="button" class="edit-btn" style="background: rgba(255,255,255,0.1);" onclick="closeCodeTierModal()">Cancel</button>
                <button type="button" class="edit-btn" onclick="saveCodeTiers()"><i class="fas fa-save"></i> Save</button>
            </div>
        </div>
    </div>
    
    <div class="save-indicator" id="saveIndicator">
        <i class="fas fa-check"></i> Saved
    </div>
    
    <script>
        let editMode = false;
        const codeTiers = <?= json_encode($codeTiers) ?>;
        const voteMode = <?= $showVoterUi ? 'true' : 'false' ?>;
        const voteBaselineNames = <?= $showVoterUi ? json_encode(array_values(array_column($displayRankings, 'name'))) : '[]' ?>;
        
        function getCodeForRank(rank) {
            if (rank >= codeTiers.codeS.minRank && rank <= codeTiers.codeS.maxRank) return 'S';
            if (rank >= codeTiers.codeA.minRank && rank <= codeTiers.codeA.maxRank) return 'A';
            return 'B';
        }

        function highlightVoteDiffs() {
            if (!voteMode || !voteBaselineNames.length) return;
            const rows = Array.from(document.querySelectorAll('#rankingsList .player-row'));
            rows.forEach((row, i) => {
                const name = row.dataset.playerName;
                const baseIdx = voteBaselineNames.indexOf(name);
                row.classList.toggle('vote-row-changed', baseIdx !== i);
            });
        }
        
        function toggleEditMode() {
            const btn = document.getElementById('editModeBtn');
            if (!btn) return;
            editMode = !editMode;
            const editElements = document.querySelectorAll('.edit-only');
            const rows = document.querySelectorAll('.player-row');
            const list = document.getElementById('rankingsList');
            
            if (editMode) {
                btn.innerHTML = '<i class="fas fa-eye"></i> View Mode';
                btn.style.background = 'linear-gradient(135deg, #00b894, #55efc4)';
                editElements.forEach(el => { el.style.display = el.classList.contains('move-buttons') ? 'flex' : 'inline-block'; });
                rows.forEach(row => {
                    row.draggable = true;
                    row.addEventListener('dragstart', handleDragStart);
                    row.addEventListener('dragend', handleDragEnd);
                    row.addEventListener('dragover', handleDragOver);
                    row.addEventListener('drop', handleDrop);
                    row.addEventListener('dragleave', handleDragLeave);
                });
                list.addEventListener('click', handleMoveButtonClick);
            } else {
                btn.innerHTML = '<i class="fas fa-edit"></i> Enable Edit Mode';
                btn.style.background = 'linear-gradient(135deg, #6c5ce7, #a29bfe)';
                editElements.forEach(el => el.style.display = 'none');
                rows.forEach(row => {
                    row.draggable = false;
                    row.removeEventListener('dragstart', handleDragStart);
                    row.removeEventListener('dragend', handleDragEnd);
                    row.removeEventListener('dragover', handleDragOver);
                    row.removeEventListener('drop', handleDrop);
                    row.removeEventListener('dragleave', handleDragLeave);
                });
                list.removeEventListener('click', handleMoveButtonClick);
            }
        }
        
        function handleMoveButtonClick(e) {
            const upBtn = e.target.closest('.move-up');
            const downBtn = e.target.closest('.move-down');
            if (upBtn && !upBtn.disabled) movePlayer(parseInt(upBtn.dataset.index), -1);
            if (downBtn && !downBtn.disabled) movePlayer(parseInt(downBtn.dataset.index), 1);
        }
        
        let draggedIndex = null;
        
        function handleDragStart(e) {
            draggedIndex = parseInt(this.dataset.index);
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', draggedIndex);
        }
        
        function handleDragEnd(e) {
            this.classList.remove('dragging');
            document.querySelectorAll('.player-row').forEach(row => {
                row.classList.remove('drag-over');
            });
        }
        
        function handleDragOver(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            this.classList.add('drag-over');
        }
        
        function handleDragLeave(e) {
            this.classList.remove('drag-over');
        }
        
        function handleDrop(e) {
            e.preventDefault();
            const row = e.currentTarget;
            const toIndex = parseInt(row.dataset.index);
            if (draggedIndex !== null && draggedIndex !== toIndex) {
                movePlayerByIndex(draggedIndex, toIndex);
            }
            row.classList.remove('drag-over');
        }
        
        function movePlayer(fromIndex, direction) {
            movePlayerByIndex(fromIndex, fromIndex + direction);
        }
        
        function movePlayerByIndex(fromIndex, toIndex) {
            if (fromIndex === toIndex) return;
            if (voteMode) {
                updateDOMAfterMove(fromIndex, toIndex);
                highlightVoteDiffs();
                return;
            }
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'move',
                    from: fromIndex,
                    to: toIndex
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showSaveIndicator();
                    updateDOMAfterMove(fromIndex, toIndex);
                }
            });
        }
        
        function updateDOMAfterMove(fromIndex, toIndex) {
            const list = document.getElementById('rankingsList');
            const rows = Array.from(list.querySelectorAll('.player-row'));
            const movedRow = rows[fromIndex];
            rows.splice(fromIndex, 1);
            rows.splice(toIndex, 0, movedRow);
            list.innerHTML = '';
            rows.forEach(row => list.appendChild(row));
            rows.forEach((row, i) => {
                row.dataset.index = i;
                const rank = i + 1;
                const group = Math.ceil(rank / 4);
                row.querySelector('.rank-badge').textContent = rank;
                row.querySelector('.group-badge').textContent = 'G' + group;
                const band = row.querySelector('.skill-band');
                band.className = 'skill-band g' + group;
                row.dataset.code = getCodeForRank(rank);
                const moveBtns = row.querySelectorAll('.move-btn');
                if (moveBtns[0]) { moveBtns[0].disabled = (i === 0); moveBtns[0].dataset.index = i; }
                if (moveBtns[1]) { moveBtns[1].disabled = (i === rows.length - 1); moveBtns[1].dataset.index = i; }
            });
        }
        
        function showSaveIndicator() {
            const indicator = document.getElementById('saveIndicator');
            indicator.classList.remove('show');
            void indicator.offsetWidth; // Trigger reflow
            indicator.classList.add('show');
        }
        
        function openCodeTierModal() {
            document.getElementById('codeTierModal').classList.add('show');
        }
        
        function closeCodeTierModal() {
            document.getElementById('codeTierModal').classList.remove('show');
        }
        
        function saveCodeTiers() {
            const data = {
                action: 'save_code_tiers',
                codeS_min: parseInt(document.getElementById('codeS_min').value) || 1,
                codeS_max: parseInt(document.getElementById('codeS_max').value) || 24,
                codeA_min: parseInt(document.getElementById('codeA_min').value) || 25,
                codeA_max: parseInt(document.getElementById('codeA_max').value) || 36,
                codeB_min: parseInt(document.getElementById('codeB_min').value) || 37,
                codeB_max: parseInt(document.getElementById('codeB_max').value) || 46
            };
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    showSaveIndicator();
                    closeCodeTierModal();
                    location.reload();
                }
            });
        }
        
        document.getElementById('codeTierModal').addEventListener('click', function(e) {
            if (e.target === this) closeCodeTierModal();
        });

        function postVoting(body) {
            return fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(body)
            }).then(r => r.json());
        }
        function votingStart() {
            const opens = document.getElementById('voteOpensAt').value;
            const dur = parseFloat(document.getElementById('voteDurationH').value);
            postVoting({ action: 'voting_start', opens_at: opens, duration_hours: dur })
                .then(d => { if (d.success) location.reload(); else alert(d.error || 'Failed'); });
        }
        function votingClose() {
            if (!confirm('Close voting? No more ballots will be accepted.')) return;
            postVoting({ action: 'voting_close' })
                .then(d => { if (d.success) location.reload(); else alert(d.error || 'Failed'); });
        }
        function votingApply() {
            postVoting({ action: 'voting_apply' })
                .then(d => { if (d.success) location.reload(); else alert(d.error || 'Failed'); });
        }
        function votingPublish() {
            if (!confirm('Publish preview to the live rankings file?')) return;
            postVoting({ action: 'voting_publish' })
                .then(d => { if (d.success) location.reload(); else alert(d.error || 'Failed'); });
        }
        function votingDiscardPreview() {
            postVoting({ action: 'voting_discard_preview' })
                .then(d => { if (d.success) location.reload(); else alert(d.error || 'Failed'); });
        }
        function votingCancel() {
            if (!confirm('Cancel this voting session? Ballots will be discarded; live rankings stay unchanged.')) return;
            postVoting({ action: 'voting_cancel' })
                .then(d => { if (d.success) location.reload(); else alert(d.error || 'Failed'); });
        }
        function submitVoteBallot() {
            const names = Array.from(document.querySelectorAll('#rankingsList .player-row')).map(r => r.dataset.playerName);
            postVoting({ action: 'voting_submit_ballot', ordered_names: names })
                .then(d => {
                    if (d.success) {
                        showSaveIndicator();
                        alert('Your vote was saved.');
                    } else {
                        alert(d.error || 'Failed');
                    }
                });
        }

        if (voteMode) {
            const list = document.getElementById('rankingsList');
            list.querySelectorAll('.player-row').forEach(row => {
                row.draggable = true;
                row.addEventListener('dragstart', handleDragStart);
                row.addEventListener('dragend', handleDragEnd);
                row.addEventListener('dragover', handleDragOver);
                row.addEventListener('drop', handleDrop);
                row.addEventListener('dragleave', handleDragLeave);
            });
            list.addEventListener('click', handleMoveButtonClick);
            highlightVoteDiffs();
        }
    </script>
</body>
</html>
