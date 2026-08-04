<?php
/**
 * FSL Challenger Tournament — signup + live bracket (bottom-up by group).
 */

ob_start();
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/team_logo.php';
require_once __DIR__ . '/voting_logic.php';
require_once __DIR__ . '/tournament_logic.php';

/**
 * Web-root-relative player thumbnail path (images/player_thumbnails/…).
 * Case-insensitive file match so MedicJR ↔ MedicJr works on Linux.
 */
function tournament_player_thumb_path(string $playerName): ?string
{
    $playerName = trim($playerName);
    if ($playerName === '') {
        return null;
    }
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '', str_replace(' ', '_', $playerName));
    if ($safeName === '') {
        return null;
    }
    $dir = dirname(__DIR__) . '/images/player_thumbnails';
    foreach (['.png', '.jpg', '.jpeg'] as $ext) {
        if (is_file($dir . '/' . $safeName . $ext)) {
            return 'images/player_thumbnails/' . $safeName . $ext;
        }
    }
    static $thumbMap = null;
    if ($thumbMap === null) {
        $thumbMap = [];
        $files = glob($dir . '/*.{png,jpg,jpeg}', GLOB_BRACE) ?: [];
        foreach ($files as $file) {
            $thumbMap[strtolower(pathinfo($file, PATHINFO_FILENAME))] = 'images/player_thumbnails/' . basename($file);
        }
    }
    return $thumbMap[strtolower($safeName)] ?? null;
}

$rankingsFile = __DIR__ . '/rankings.json';

$canEdit = false;
$uid = '';
$username = '';
if (isset($_SESSION['user_id'])) {
    $uid = trim((string) $_SESSION['user_id']);
    $username = trim((string) ($_SESSION['username'] ?? ''));
    if ($uid !== '') {
        $canEdit = rankings_user_has_permission($db, $uid, RANKINGS_PERM_SUPER);
    }
}

$rankings = [];
if (is_readable($rankingsFile)) {
    $decoded = json_decode((string) file_get_contents($rankingsFile), true);
    if (is_array($decoded)) {
        $rankings = $decoded;
    }
}
foreach ($rankings as $i => $p) {
    $rank = (int) ($p['rank'] ?? $i + 1);
    $rankings[$i]['rank'] = $rank;
    $rankings[$i]['group'] = isset($p['group']) ? (int) $p['group'] : (int) ceil($rank / 4);
    $rankings[$i]['name'] = trim((string) ($p['name'] ?? ''));
}

$codeTiers = tournament_default_code_tiers(count($rankings));
$configFile = __DIR__ . '/rankings_config.json';
if (is_readable($configFile)) {
    $loaded = json_decode((string) file_get_contents($configFile), true);
    if (is_array($loaded)) {
        foreach (['codeS', 'codeA', 'codeB'] as $tier) {
            if (isset($loaded[$tier]['minRank'], $loaded[$tier]['maxRank'])) {
                $codeTiers[$tier] = [
                    'minRank' => (int) $loaded[$tier]['minRank'],
                    'maxRank' => (int) $loaded[$tier]['maxRank'],
                ];
            }
        }
    }
}

$myPlayerName = null;
if ($username !== '') {
    foreach ($rankings as $p) {
        if (strcasecmp((string) $p['name'], $username) === 0) {
            $myPlayerName = (string) $p['name'];
            break;
        }
    }
}

$raceIcons = [
    'T' => 'terran_icon.png',
    'P' => 'protoss_icon.png',
    'Z' => 'zerg_icon.png',
    'R' => 'random_icon.png',
];

// --- Export JSON download (admin only) ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && (string) ($_GET['action'] ?? '') === 'export_json') {
    ob_end_clean();
    if ($uid === '' || !$canEdit) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Permission denied';
        exit;
    }
    $state = tournament_load_state();
    $payload = tournament_export_payload($state, $rankings);
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Could not encode export';
        exit;
    }
    $fname = 'fsl_challenger_tournament_' . gmdate('Ymd_His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: no-store');
    echo $json;
    exit;
}

// --- AJAX ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $input = json_decode((string) file_get_contents('php://input'), true) ?? [];
    $action = (string) ($input['action'] ?? '');

    $jsonFail = static function (string $msg): void {
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    };
    $jsonOk = static function (array $bracket, array $state): void {
        echo json_encode([
            'success' => true,
            'bracket' => $bracket,
            'state' => [
                'participating' => $state['participating'] ?? [],
                'results' => $state['results'] ?? [],
                'winners' => $state['winners'] ?? [],
                'updated_at' => $state['updated_at'] ?? null,
            ],
        ]);
        exit;
    };

    if ($uid === '') {
        $jsonFail('Not logged in');
    }
    if (!$canEdit) {
        $jsonFail('Permission denied');
    }

    $state = tournament_load_state();
    $rebuild = static function (array $rankings, array &$state) use ($codeTiers): array {
        $bracket = tournament_build_bracket($rankings, $state, $codeTiers);
        $state['results'] = tournament_prune_results($bracket, $state['results'] ?? []);
        $normalized = tournament_normalize_state($state);
        $state['results'] = $normalized['results'];
        $state['winners'] = $normalized['winners'];
        $state['participating'] = $normalized['participating'];
        return tournament_build_bracket($rankings, $state, $codeTiers);
    };

    if ($action === 'toggle_participation') {
        $name = trim((string) ($input['name'] ?? ''));
        $on = !empty($input['participating']);
        if ($name === '') {
            $jsonFail('Missing player name');
        }
        $known = false;
        foreach ($rankings as $p) {
            if ((string) $p['name'] === $name) {
                $known = true;
                break;
            }
        }
        if (!$known) {
            $jsonFail('Unknown player');
        }
        $state['participating'][$name] = $on;
        $bracket = $rebuild($rankings, $state);
        if (!tournament_save_state($state)) {
            $jsonFail('Could not save state');
        }
        $jsonOk($bracket, $state);
    }

    if ($action === 'set_participation_bulk') {
        $on = !empty($input['participating']);
        $groupFilter = isset($input['group']) ? (int) $input['group'] : null;
        foreach ($rankings as $p) {
            $g = (int) $p['group'];
            if ($groupFilter !== null && $g !== $groupFilter) {
                continue;
            }
            $state['participating'][(string) $p['name']] = $on;
        }
        $bracket = $rebuild($rankings, $state);
        if (!tournament_save_state($state)) {
            $jsonFail('Could not save state');
        }
        $jsonOk($bracket, $state);
    }

    if ($action === 'import_bracket') {
        $payload = $input['payload'] ?? null;
        if (!is_array($payload)) {
            $jsonFail('Missing import payload');
        }
        $includeResults = array_key_exists('include_results', $input)
            ? !empty($input['include_results'])
            : true;
        try {
            $applied = tournament_import_payload($state, $payload, $rankings, $includeResults);
        } catch (InvalidArgumentException $e) {
            $jsonFail($e->getMessage());
        }
        $state = $applied['state'];
        $bracket = $rebuild($rankings, $state);
        if (!tournament_save_state($state)) {
            $jsonFail('Could not save state');
        }
        echo json_encode([
            'success' => true,
            'bracket' => $bracket,
            'state' => [
                'participating' => $state['participating'] ?? [],
                'results' => $state['results'] ?? [],
                'winners' => $state['winners'] ?? [],
                'updated_at' => $state['updated_at'] ?? null,
            ],
            'import' => [
                'imported_in' => $applied['imported_in'],
                'imported_out' => $applied['imported_out'],
                'results_replaced' => $applied['results_replaced'],
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'update_scores') {
        $matchId = trim((string) ($input['match_id'] ?? ''));
        if ($matchId === '') {
            $jsonFail('Missing match_id');
        }
        $bracket = tournament_build_bracket($rankings, $state, $codeTiers);
        $index = tournament_index_matches($bracket);
        if (!isset($index[$matchId])) {
            $jsonFail('Unknown match');
        }
        $m = $index[$matchId];
        if (!in_array($m['status'] ?? '', ['ready', 'live', 'done'], true)) {
            $jsonFail('Match is not editable yet');
        }
        $prev = tournament_result_normalize($state['results'][$matchId] ?? null);
        $s1 = array_key_exists('score1', $input) ? $input['score1'] : $prev['score1'];
        $s2 = array_key_exists('score2', $input) ? $input['score2'] : $prev['score2'];
        if ($s1 !== null && $s1 !== '') {
            $s1 = max(0, (int) $s1);
        } else {
            $s1 = null;
        }
        if ($s2 !== null && $s2 !== '') {
            $s2 = max(0, (int) $s2);
        } else {
            $s2 = null;
        }
        // Updating scores reopens the match (does not advance until End)
        $state['results'][$matchId] = [
            'score1' => $s1,
            'score2' => $s2,
            'winner' => null,
            'ended' => false,
            'forfeit' => null,
        ];
        $bracket = $rebuild($rankings, $state);
        if (!tournament_save_state($state)) {
            $jsonFail('Could not save state');
        }
        $jsonOk($bracket, $state);
    }

    if ($action === 'end_match') {
        $matchId = trim((string) ($input['match_id'] ?? ''));
        $winner = trim((string) ($input['winner'] ?? ''));
        if ($matchId === '') {
            $jsonFail('Missing match_id');
        }
        $bracket = tournament_build_bracket($rankings, $state, $codeTiers);
        $index = tournament_index_matches($bracket);
        if (!isset($index[$matchId])) {
            $jsonFail('Unknown match');
        }
        $m = $index[$matchId];
        if (!in_array($m['status'] ?? '', ['ready', 'live', 'done'], true)) {
            $jsonFail('Match is not ready to end');
        }
        $p1 = $m['p1'] ?? null;
        $p2 = $m['p2'] ?? null;
        $prev = tournament_result_normalize($state['results'][$matchId] ?? null);
        [$score1, $score2] = tournament_normalize_score_pair($prev['score1'], $prev['score2']);
        if ($winner === '') {
            $winner = (string) (tournament_infer_winner_from_scores(
                $p1,
                $p2,
                $prev['score1'],
                $prev['score2']
            ) ?? '');
        }
        if ($winner === '' || ($winner !== $p1 && $winner !== $p2)) {
            $jsonFail('Set scores with a leader, or pick a winner');
        }
        $state['results'][$matchId] = [
            'score1' => $score1,
            'score2' => $score2,
            'winner' => $winner,
            'ended' => true,
            'forfeit' => null,
            'p1' => $p1,
            'p2' => $p2,
        ];
        $bracket = $rebuild($rankings, $state);
        if (!tournament_save_state($state)) {
            $jsonFail('Could not save state');
        }
        $jsonOk($bracket, $state);
    }

    if ($action === 'forfeit_match') {
        $matchId = trim((string) ($input['match_id'] ?? ''));
        $forfeitPlayer = trim((string) ($input['player'] ?? ''));
        if ($matchId === '' || $forfeitPlayer === '') {
            $jsonFail('Missing match_id or player');
        }
        $bracket = tournament_build_bracket($rankings, $state, $codeTiers);
        $index = tournament_index_matches($bracket);
        if (!isset($index[$matchId])) {
            $jsonFail('Unknown match');
        }
        $m = $index[$matchId];
        if (!in_array($m['status'] ?? '', ['ready', 'live', 'done'], true)) {
            $jsonFail('Match is not ready');
        }
        $p1 = $m['p1'] ?? null;
        $p2 = $m['p2'] ?? null;
        if ($forfeitPlayer !== $p1 && $forfeitPlayer !== $p2) {
            $jsonFail('Forfeit player must be in the match');
        }
        $winner = ($forfeitPlayer === $p1) ? $p2 : $p1;
        $prev = tournament_result_normalize($state['results'][$matchId] ?? null);
        $state['results'][$matchId] = [
            'score1' => $prev['score1'],
            'score2' => $prev['score2'],
            'winner' => $winner,
            'ended' => true,
            'forfeit' => $forfeitPlayer,
            'p1' => $p1,
            'p2' => $p2,
        ];
        $bracket = $rebuild($rankings, $state);
        if (!tournament_save_state($state)) {
            $jsonFail('Could not save state');
        }
        $jsonOk($bracket, $state);
    }

    if ($action === 'reopen_match') {
        $matchId = trim((string) ($input['match_id'] ?? ''));
        if ($matchId === '') {
            $jsonFail('Missing match_id');
        }
        $prev = tournament_result_normalize($state['results'][$matchId] ?? null);
        $state['results'][$matchId] = [
            'score1' => $prev['score1'],
            'score2' => $prev['score2'],
            'winner' => null,
            'ended' => false,
            'forfeit' => null,
            'p1' => $prev['p1'],
            'p2' => $prev['p2'],
        ];
        $bracket = $rebuild($rankings, $state);
        if (!tournament_save_state($state)) {
            $jsonFail('Could not save state');
        }
        $jsonOk($bracket, $state);
    }

    if ($action === 'clear_match') {
        $matchId = trim((string) ($input['match_id'] ?? ''));
        if ($matchId === '') {
            $jsonFail('Missing match_id');
        }
        unset($state['results'][$matchId], $state['winners'][$matchId]);
        $bracket = $rebuild($rankings, $state);
        if (!tournament_save_state($state)) {
            $jsonFail('Could not save state');
        }
        $jsonOk($bracket, $state);
    }

    if ($action === 'reset_results') {
        $state['results'] = [];
        $state['winners'] = [];
        $bracket = tournament_build_bracket($rankings, $state, $codeTiers);
        if (!tournament_save_state($state)) {
            $jsonFail('Could not save state');
        }
        $jsonOk($bracket, $state);
    }

    // Legacy aliases
    if ($action === 'set_winner') {
        $input['action'] = 'end_match';
        // fall through by re-dispatching simply:
        $matchId = trim((string) ($input['match_id'] ?? ''));
        $winner = trim((string) ($input['winner'] ?? ''));
        $bracket = tournament_build_bracket($rankings, $state, $codeTiers);
        $index = tournament_index_matches($bracket);
        if (!isset($index[$matchId])) {
            $jsonFail('Unknown match');
        }
        $m = $index[$matchId];
        $prev = tournament_result_normalize($state['results'][$matchId] ?? null);
        $state['results'][$matchId] = [
            'score1' => $prev['score1'],
            'score2' => $prev['score2'],
            'winner' => $winner,
            'ended' => true,
            'forfeit' => null,
            'p1' => $m['p1'] ?? null,
            'p2' => $m['p2'] ?? null,
        ];
        if ($winner !== ($m['p1'] ?? null) && $winner !== ($m['p2'] ?? null)) {
            $jsonFail('Winner must be one of the match players');
        }
        $bracket = $rebuild($rankings, $state);
        if (!tournament_save_state($state)) {
            $jsonFail('Could not save state');
        }
        $jsonOk($bracket, $state);
    }

    if ($action === 'clear_winner' || $action === 'reset_winners') {
        if ($action === 'reset_winners') {
            $state['results'] = [];
            $state['winners'] = [];
        } else {
            $matchId = trim((string) ($input['match_id'] ?? ''));
            unset($state['results'][$matchId], $state['winners'][$matchId]);
        }
        $bracket = $rebuild($rankings, $state);
        if (!tournament_save_state($state)) {
            $jsonFail('Could not save state');
        }
        $jsonOk($bracket, $state);
    }

    $jsonFail('Unknown action');
}

$state = tournament_load_state();
$bracket = tournament_build_bracket($rankings, $state, $codeTiers);

$playerTeamLogo = [];
$names = array_values(array_filter(array_map(static function ($p) {
    return trim((string) ($p['name'] ?? ''));
}, $rankings)));
if ($names !== []) {
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    try {
        $stmt = $db->prepare("SELECT p.Real_Name, t.Team_Name FROM Players p JOIN Teams t ON p.Team_ID = t.Team_ID WHERE p.Real_Name IN ($placeholders)");
        $stmt->execute($names);
        $nameToTeam = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $nameToTeam[trim((string) $row['Real_Name'])] = trim((string) $row['Team_Name']);
            $nameToTeam[strtolower(trim((string) $row['Real_Name']))] = trim((string) $row['Team_Name']);
        }
        foreach ($names as $name) {
            $teamName = $nameToTeam[$name] ?? $nameToTeam[strtolower($name)] ?? null;
            if ($teamName) {
                $logo = getTeamLogo($teamName, '256px');
                if ($logo) {
                    $playerTeamLogo[$name] = $logo;
                }
            }
        }
    } catch (PDOException $e) {
        // logos optional
    }
}

$playerThumb = [];
foreach ($names as $name) {
    $thumb = tournament_player_thumb_path($name);
    if ($thumb) {
        $playerThumb[$name] = $thumb;
    }
}

$participatingMap = [];
foreach ($state['participating'] ?? [] as $n => $on) {
    if ($on) {
        $participatingMap[(string) $n] = true;
    }
}

$inCount = (int) ($bracket['participant_count'] ?? 0);
$totalCount = count($rankings);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../images/favicon.png" type="image/png">
    <title>Challenger Bracket - FSL</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&family=Exo+2:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        :root {
            --t-purple: #6c5ce7;
            --t-purple-soft: #a29bfe;
            --t-ready: #00d4aa;
            --t-waiting: #f0b429;
            --t-done: #6b6b7b;
            --t-panel: rgba(0, 0, 0, 0.35);
            --t-border: rgba(108, 92, 231, 0.28);
        }
        body {
            background: linear-gradient(135deg, #0a0a0f 0%, #1a1a2e 50%, #0a0a0f 100%);
            min-height: 100vh;
            color: #fff;
        }
        .tour-page {
            max-width: 1600px;
            margin: 0 auto;
            padding: 1.25rem 1.5rem 2.5rem;
        }
        .tour-header {
            text-align: center;
            margin-bottom: 1.25rem;
            padding-bottom: 0.85rem;
            border-bottom: 2px solid rgba(108, 92, 231, 0.3);
        }
        .tour-header h1 {
            font-family: 'Rajdhani', sans-serif;
            font-size: 2.2rem;
            font-weight: 700;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-transform: uppercase;
            letter-spacing: 3px;
            margin: 0;
        }
        .tour-header p {
            color: #888;
            margin: 0.4rem 0 0;
            font-family: 'Exo 2', sans-serif;
            font-size: 0.95rem;
        }
        .tour-header a.tour-back {
            color: var(--t-purple-soft);
            font-size: 0.85rem;
        }
        .tour-header a.tour-back:hover { color: #fff; text-decoration: none; }

        .tour-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            justify-content: center;
            margin-bottom: 1rem;
        }
        .tour-chip {
            font-family: 'Rajdhani', sans-serif;
            font-weight: 600;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            padding: 0.3rem 0.75rem;
            border-radius: 999px;
            background: rgba(0,0,0,0.35);
            border: 1px solid var(--t-border);
            color: #ccc;
        }
        .tour-chip.ready { border-color: rgba(0, 212, 170, 0.55); color: var(--t-ready); }
        .tour-chip.waiting { border-color: rgba(240, 180, 41, 0.5); color: var(--t-waiting); }
        .tour-chip.done { border-color: rgba(150,150,160,0.4); color: #aaa; }
        .tour-chip.champ { border-color: rgba(255, 215, 0, 0.45); color: #ffd700; }

        .tour-admin-bar {
            background: var(--t-panel);
            border: 1px solid var(--t-border);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
            justify-content: center;
        }
        .tour-admin-bar .hint {
            width: 100%;
            text-align: center;
            color: #777;
            font-size: 0.8rem;
            margin: 0;
        }
        .tour-btn {
            padding: 0.4rem 0.9rem;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            border: none;
            border-radius: 5px;
            color: #fff;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .tour-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(108, 92, 231, 0.35);
            color: #fff;
        }
        .tour-btn.ghost {
            background: rgba(108, 92, 231, 0.15);
            border: 1px solid rgba(108, 92, 231, 0.4);
            color: var(--t-purple-soft);
        }
        .tour-btn.ghost:hover { background: rgba(108, 92, 231, 0.35); color: #fff; }
        .tour-btn.danger {
            background: rgba(214, 48, 49, 0.25);
            border: 1px solid rgba(214, 48, 49, 0.45);
            color: #ff7675;
        }

        .tour-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.85rem;
        }
        .tour-layout {
            display: block;
            width: 100%;
        }
        .tour-layout.roster-open {
            display: grid;
            grid-template-columns: minmax(260px, 320px) 1fr;
            gap: 1rem;
            align-items: start;
        }
        @media (max-width: 992px) {
            .tour-layout.roster-open { grid-template-columns: 1fr; }
        }
        .tour-roster {
            display: none;
            background: var(--t-panel);
            border: 1px solid var(--t-border);
            border-radius: 12px;
            padding: 0.75rem;
            width: 100%;
        }
        .tour-layout.roster-open .tour-roster { display: block; }
        .tour-bracket-panel {
            background: var(--t-panel);
            border: 1px solid var(--t-border);
            border-radius: 12px;
            padding: 0.75rem 1rem 1.25rem;
            min-width: 0;
            width: min(1100px, 100%);
            margin-left: auto;
            margin-right: auto;
            box-sizing: border-box;
            display: block;
        }
        /* keep bracket content visually centered in the panel */
        .tour-bracket-scroll {
            width: 100%;
        }
        .tour-layout.roster-open .tour-bracket-panel {
            width: 100%;
            max-width: none;
            margin-left: 0;
            margin-right: 0;
        }
        .tour-panel-title {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: var(--t-purple-soft);
            margin: 0 0 0.65rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }
        .tour-panel-title span.sub {
            font-size: 0.75rem;
            font-weight: 500;
            letter-spacing: 0;
            text-transform: none;
            color: #777;
        }

        .tour-roster-scroll {
            max-height: calc(100vh - 220px);
            overflow-y: auto;
            padding-right: 0.25rem;
        }
        .tour-group-head {
            font-family: 'Rajdhani', sans-serif;
            font-weight: 700;
            font-size: 0.8rem;
            letter-spacing: 1px;
            color: #888;
            padding: 0.45rem 0.35rem 0.25rem;
            position: sticky;
            top: 0;
            background: #12121c;
            z-index: 2;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .tour-group-head button {
            background: none;
            border: none;
            color: var(--t-purple-soft);
            font-size: 0.7rem;
            cursor: pointer;
            padding: 0;
        }
        .tour-group-head button:hover { color: #fff; }

        .tour-player {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.45rem;
            margin-bottom: 0.25rem;
            border-radius: 8px;
            background: rgba(0,0,0,0.22);
            position: relative;
            overflow: hidden;
            transition: opacity 0.2s, background 0.2s, filter 0.2s;
        }
        .tour-player.out {
            opacity: 0.38;
            filter: grayscale(0.85);
        }
        .tour-player.in {
            background: rgba(108, 92, 231, 0.12);
            box-shadow: inset 0 0 0 1px rgba(108, 92, 231, 0.25);
        }
        .tour-player.me {
            box-shadow: inset 0 0 0 1px rgba(0, 212, 170, 0.45);
        }
        .tour-player .skill-band {
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 8px;
            border-radius: 8px 0 0 8px;
        }
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
        .skill-band.g13,
        .skill-band.g14,
        .skill-band.g15 { background: linear-gradient(180deg, #3b3b4b, #2b2b3b); }

        .tour-rank {
            width: 22px; height: 22px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            font-family: 'Rajdhani', sans-serif;
            font-size: 0.68rem;
            font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            margin-left: 0.35rem;
        }
        .tour-race { width: 18px; height: 18px; flex-shrink: 0; }
        .tour-player-thumb {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            object-fit: cover;
            object-position: center top;
            border: 1px solid rgba(255,255,255,.16);
            background: #0b1723;
            flex-shrink: 0;
        }
        .tour-conn-card .tour-player-thumb,
        .tour-slot-who .tour-player-thumb {
            width: 22px;
            height: 22px;
        }
        .tour-slot-who,
        .tour-pname-wrap {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            min-width: 0;
            max-width: 100%;
        }
        .tour-pname {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .tour-name {
            font-family: 'Exo 2', sans-serif;
            font-size: 0.88rem;
            font-weight: 600;
            flex: 1;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .tour-name a { color: #fff; text-decoration: none; }
        .tour-name a:hover { color: var(--t-purple-soft); }
        .tour-team-logo {
            width: 20px; height: 20px; object-fit: contain;
            border-radius: 3px; border: 1px solid rgba(108,92,231,0.3);
            flex-shrink: 0;
        }
        .tour-toggle {
            width: 36px; height: 20px;
            border-radius: 999px;
            background: #333;
            border: 1px solid #444;
            position: relative;
            cursor: pointer;
            flex-shrink: 0;
            transition: background 0.2s, border-color 0.2s;
            padding: 0;
        }
        .tour-toggle::after {
            content: '';
            position: absolute;
            top: 2px; left: 2px;
            width: 14px; height: 14px;
            border-radius: 50%;
            background: #888;
            transition: transform 0.2s, background 0.2s;
        }
        .tour-toggle.on {
            background: rgba(0, 212, 170, 0.25);
            border-color: rgba(0, 212, 170, 0.55);
        }
        .tour-toggle.on::after {
            transform: translateX(16px);
            background: var(--t-ready);
        }
        .tour-toggle:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .tour-bracket-scroll {
            max-height: calc(100vh - 220px);
            overflow: auto;
            padding: 0.25rem 0.75rem 1rem;
        }
        .tour-group-block .tour-bracket-tree {
            margin: 0 auto;
        }
        .tour-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 0.85rem;
            font-size: 0.78rem;
            color: #999;
            font-family: 'Exo 2', sans-serif;
        }
        .tour-legend i { margin-right: 0.25rem; }
        .tour-legend .ready { color: var(--t-ready); }
        .tour-legend .waiting { color: var(--t-waiting); }
        .tour-legend .done { color: #aaa; }

        .tour-road {
            background: rgba(255, 215, 0, 0.06);
            border: 1px solid rgba(255, 215, 0, 0.22);
            border-radius: 10px;
            padding: 0.75rem;
            margin-bottom: 1rem;
        }
        .tour-road h3 {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1rem;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #ffd700;
            margin: 0 0 0.6rem;
        }
        .tour-asc-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.65rem;
            align-items: stretch;
        }
        .tour-asc-arrow {
            display: flex;
            align-items: center;
            color: rgba(255,215,0,0.45);
            font-size: 1.1rem;
            padding: 0 0.15rem;
        }

        .tour-group-block {
            margin-bottom: 1.1rem;
            padding-bottom: 0.85rem;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .tour-group-block:last-child { border-bottom: none; }
        .tour-group-label {
            font-family: 'Rajdhani', sans-serif;
            font-weight: 700;
            font-size: 0.95rem;
            letter-spacing: 1px;
            color: var(--t-purple-soft);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .tour-group-label .champ-pill {
            font-size: 0.72rem;
            background: rgba(0,212,170,0.15);
            border: 1px solid rgba(0,212,170,0.35);
            color: var(--t-ready);
            padding: 0.1rem 0.45rem;
            border-radius: 999px;
            letter-spacing: 0;
            font-weight: 600;
        }
        .tour-group-label .solo-pill {
            font-size: 0.72rem;
            background: rgba(108,92,231,0.15);
            border: 1px solid rgba(108,92,231,0.35);
            color: var(--t-purple-soft);
            padding: 0.1rem 0.45rem;
            border-radius: 999px;
        }

        .tour-match {
            background: rgba(0,0,0,0.35);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 8px;
            padding: 0.35rem;
            position: relative;
            transition: border-color 0.2s, box-shadow 0.2s, transform 0.15s;
        }
        .tour-match.status-ready {
            border-color: rgba(0, 212, 170, 0.65);
            box-shadow: 0 0 0 1px rgba(0, 212, 170, 0.2), 0 0 18px rgba(0, 212, 170, 0.12);
            animation: tourPulse 2.2s ease-in-out infinite;
        }
        .tour-match.status-live {
            border-color: rgba(85, 163, 255, 0.7);
            box-shadow: 0 0 0 1px rgba(85, 163, 255, 0.25);
        }
        .tour-match.status-waiting {
            border-color: rgba(240, 180, 41, 0.4);
            opacity: 0.85;
        }
        .tour-match.status-done {
            border-color: rgba(150,150,160,0.25);
        }
        .tour-match.status-bye {
            border-color: rgba(108, 92, 231, 0.35);
            opacity: 0.75;
        }
        .tour-match.status-done.forfeit-match {
            border-color: rgba(255, 118, 117, 0.45);
        }
        .tour-score-row {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            flex: 1;
            min-width: 0;
        }
        .tour-score-input {
            width: 2.1rem;
            padding: 0.15rem 0.2rem;
            border-radius: 4px;
            border: 1px solid rgba(108, 92, 231, 0.45);
            background: #0a0a0f;
            color: #fff;
            text-align: center;
            font-weight: 700;
            font-size: 0.85rem;
        }
        .tour-score-input:disabled {
            opacity: 0.7;
            border-color: #333;
        }
        .tour-score-display {
            min-width: 1.25rem;
            text-align: center;
            font-weight: 700;
            color: #ccc;
            font-size: 0.9rem;
        }
        .tour-match-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.3rem;
            margin-top: 0.35rem;
            padding-top: 0.3rem;
            border-top: 1px solid rgba(255,255,255,0.06);
        }
        .tour-match-actions button {
            flex: 1 1 auto;
            min-width: 4.5rem;
            padding: 0.25rem 0.4rem;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            border-radius: 4px;
            cursor: pointer;
            border: 1px solid transparent;
            font-family: 'Rajdhani', sans-serif;
        }
        .tour-match-actions .btn-end {
            background: rgba(0, 212, 170, 0.2);
            border-color: rgba(0, 212, 170, 0.5);
            color: var(--t-ready);
        }
        .tour-match-actions .btn-end:hover { background: rgba(0, 212, 170, 0.35); color: #fff; }
        .tour-match-actions .btn-reopen {
            background: rgba(240, 180, 41, 0.15);
            border-color: rgba(240, 180, 41, 0.45);
            color: var(--t-waiting);
        }
        .tour-match-actions .btn-clear {
            background: rgba(255,255,255,0.04);
            border-color: #444;
            color: #999;
        }
        .tour-ff-btn {
            background: none;
            border: 1px solid rgba(255, 118, 117, 0.4);
            color: #ff7675;
            font-size: 0.6rem;
            font-weight: 700;
            padding: 0.1rem 0.3rem;
            border-radius: 3px;
            cursor: pointer;
            flex-shrink: 0;
            letter-spacing: 0.3px;
        }
        .tour-ff-btn:hover { background: rgba(255, 118, 117, 0.2); color: #fff; }
        .tour-ff-badge {
            font-size: 0.6rem;
            color: #ff7675;
            font-weight: 700;
            margin-left: 0.25rem;
        }
        .tour-chip.live { border-color: rgba(85, 163, 255, 0.55); color: #74b9ff; }
        @keyframes tourPulse {
            0%, 100% { box-shadow: 0 0 0 1px rgba(0, 212, 170, 0.2), 0 0 12px rgba(0, 212, 170, 0.1); }
            50% { box-shadow: 0 0 0 1px rgba(0, 212, 170, 0.45), 0 0 22px rgba(0, 212, 170, 0.22); }
        }
        .tour-match-status {
            position: absolute;
            top: -0.45rem;
            right: 0.4rem;
            font-size: 0.62rem;
            font-family: 'Rajdhani', sans-serif;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            padding: 0.05rem 0.35rem;
            border-radius: 4px;
            background: #1a1a2e;
        }
        .tour-match-shell.status-ready .tour-match-status,
        .tour-match.status-ready .tour-match-status { color: var(--t-ready); }
        .tour-match-shell.status-waiting .tour-match-status,
        .tour-match.status-waiting .tour-match-status { color: var(--t-waiting); }
        .tour-match-shell.status-done .tour-match-status,
        .tour-match.status-done .tour-match-status { color: #999; }
        .tour-match-shell.status-bye .tour-match-status,
        .tour-match.status-bye .tour-match-status { color: var(--t-purple-soft); }
        .tour-match-shell.status-live .tour-match-status { color: #74b9ff; }

        .tour-slot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.35rem;
            padding: 0.28rem 0.4rem;
            border-radius: 5px;
            font-family: 'Exo 2', sans-serif;
            font-size: 0.82rem;
            font-weight: 600;
            color: #ddd;
            min-height: 1.7rem;
        }
        .tour-slot + .tour-slot {
            margin-top: 2px;
            border-top: 1px solid rgba(255,255,255,0.06);
            border-radius: 0 0 5px 5px;
        }
        .tour-slot.tbd { color: #555; font-weight: 500; font-style: italic; }
        .tour-slot.winner {
            color: #fff;
            background: rgba(0, 212, 170, 0.15);
        }
        .tour-slot.loser {
            opacity: 0.45;
            text-decoration: line-through;
        }
        .tour-slot button.pick {
            background: none;
            border: none;
            color: var(--t-ready);
            cursor: pointer;
            font-size: 0.72rem;
            padding: 0;
            white-space: nowrap;
        }
        .tour-slot button.pick:hover { color: #fff; }
        .tour-match .clear-win {
            display: block;
            width: 100%;
            text-align: center;
            background: none;
            border: none;
            color: #666;
            font-size: 0.65rem;
            margin-top: 0.15rem;
            cursor: pointer;
        }
        .tour-match .clear-win:hover { color: #ff7675; }

        .tour-empty {
            text-align: center;
            padding: 2.5rem 1rem;
            color: #777;
            font-family: 'Exo 2', sans-serif;
        }
        .tour-empty i {
            font-size: 2rem;
            color: var(--t-purple);
            margin-bottom: 0.75rem;
            display: block;
        }
        .tour-champ-banner {
            text-align: center;
            padding: 0.75rem;
            margin-bottom: 1rem;
            border-radius: 10px;
            background: linear-gradient(135deg, rgba(255,215,0,0.12), rgba(108,92,231,0.15));
            border: 1px solid rgba(255,215,0,0.35);
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.25rem;
            font-weight: 700;
            letter-spacing: 1px;
            color: #ffd700;
        }

        .tour-feed-hop {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            margin: 0.35rem 0 0.85rem;
            padding: 0.55rem 0.75rem;
            border-radius: 8px;
            background: rgba(0, 212, 170, 0.06);
            border: 1px dashed rgba(0, 212, 170, 0.4);
            color: #9adbc9;
            font-family: 'Exo 2', sans-serif;
            font-size: 0.85rem;
        }
        .tour-feed-hop.tour-feed-wait {
            background: rgba(240, 180, 41, 0.07);
            border-color: rgba(240, 180, 41, 0.45);
            color: #d4c09a;
        }
        .tour-feed-hop.tour-feed-wait .tour-feed-arrow-stack { color: var(--t-waiting); }
        .tour-feed-hop .tour-feed-arrow-stack {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: var(--t-ready);
            font-size: 1rem;
            line-height: 0.85;
            animation: tourFeedPulse 1.8s ease-in-out infinite;
        }
        .tour-feed-hop strong { color: #fff; font-weight: 600; }
        .tour-feed-hop .tour-feed-to {
            margin-left: auto;
            font-family: 'Rajdhani', sans-serif;
            font-weight: 700;
            letter-spacing: 1px;
            color: var(--t-ready);
            white-space: nowrap;
        }

        .tour-feed-inbound {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin: 0 0 0.55rem;
            padding: 0.4rem 0.6rem;
            border-radius: 8px;
            background: rgba(108, 92, 231, 0.1);
            border: 1px solid rgba(108, 92, 231, 0.35);
            font-size: 0.78rem;
            color: #c5b8ff;
            font-family: 'Exo 2', sans-serif;
        }
        .tour-feed-inbound .tour-feed-arrow-in {
            color: var(--t-purple-soft);
            font-size: 1.05rem;
            animation: tourFeedPulse 1.8s ease-in-out infinite;
        }
        .tour-feed-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.1rem 0.45rem;
            border-radius: 999px;
            background: rgba(0, 212, 170, 0.12);
            border: 1px solid rgba(0, 212, 170, 0.35);
            color: var(--t-ready);
            font-size: 0.72rem;
            font-weight: 600;
        }

        /* Solid climb path: lower-group winner → higher group */
        .tour-climb-bridge {
            display: flex;
            align-items: stretch;
            justify-content: center;
            gap: 0.85rem;
            margin: 0.15rem auto 0.85rem;
            max-width: 420px;
            padding: 0.35rem 0.5rem;
        }
        .tour-climb-shaft {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 18px;
            flex: 0 0 18px;
            color: #fff;
        }
        .tour-climb-shaft .tour-climb-vline {
            width: 3px;
            flex: 1 1 auto;
            min-height: 28px;
            background: #fff;
            box-shadow: 0 0 0 0.5px rgba(255,255,255,0.35);
        }
        .tour-climb-shaft .tour-climb-head {
            font-size: 0.85rem;
            line-height: 1;
            margin-bottom: 2px;
            color: #fff;
            text-shadow: 0 0 8px rgba(0, 212, 170, 0.55);
            animation: tourFeedPulse 1.8s ease-in-out infinite;
        }
        .tour-climb-card {
            flex: 1 1 auto;
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 0.15rem;
            padding: 0.45rem 0.75rem;
            border-radius: 8px;
            background: rgba(0, 212, 170, 0.1);
            border: 1px solid rgba(0, 212, 170, 0.45);
            font-family: 'Exo 2', sans-serif;
        }
        .tour-climb-card .who {
            color: #fff;
            font-weight: 700;
            font-size: 0.95rem;
        }
        .tour-climb-card .path {
            font-family: 'Rajdhani', sans-serif;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            font-size: 0.78rem;
            color: var(--t-ready);
        }
        .tour-climb-card .path i { margin-right: 0.25rem; }
        .tour-group-block.has-climb-out {
            margin-bottom: 0.15rem;
        }
        .tour-slot .fed-tag {
            margin-left: 0.35rem;
            font-size: 0.62rem;
            font-weight: 700;
            color: var(--t-ready);
            letter-spacing: 0.3px;
            vertical-align: middle;
        }
        .tour-slot.fed-in {
            box-shadow: inset 2px 0 0 var(--t-ready);
        }
        .tour-group-block.has-feed {
            border-left: 2px solid rgba(0, 212, 170, 0.25);
            padding-left: 0.65rem;
        }
        .tour-code-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin: 1.1rem 0 0.65rem;
            padding: 0.55rem 0.85rem;
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.18);
            background: rgba(255,255,255,0.04);
            font-family: 'Rajdhani', sans-serif;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }
        .tour-code-banner.code-S { border-color: rgba(255, 215, 0, 0.45); color: #ffd700; }
        .tour-code-banner.code-A { border-color: rgba(192, 192, 192, 0.45); color: #d0d0d0; }
        .tour-code-banner.code-B { border-color: rgba(205, 127, 50, 0.5); color: #cd7f32; }
        .tour-code-banner .code-champ-label {
            font-family: 'Exo 2', sans-serif;
            font-size: 0.85rem;
            letter-spacing: 0;
            text-transform: none;
            font-weight: 600;
            color: #fff;
        }
        .tour-code-banner .code-champ-label.pending { color: #777; font-weight: 500; }

        /* Continuous white bracket lines (slot → spine → next round) */
        .tour-bracket-tree {
            display: flex;
            align-items: stretch;
            justify-content: center;
            gap: 0;
            overflow: visible;
            padding: 0.5rem 0.75rem 1rem;
            --line: #ffffff;
            --line-w: 3px;
        }
        .tour-round-col {
            display: flex;
            flex-direction: column;
            min-width: 230px;
            position: relative;
            flex: 0 0 auto;
            overflow: visible;
        }
        .tour-round-title {
            font-size: 0.68rem;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 0.45rem;
            font-family: 'Rajdhani', sans-serif;
            text-align: center;
            flex: 0 0 auto;
        }
        .tour-round-body {
            display: flex;
            flex-direction: column;
            justify-content: stretch;
            gap: 0;
            flex: 1 1 auto;
            min-height: 420px;
        }
        .tour-match-wrap {
            flex: 1 1 0;
            display: flex;
            flex-direction: column;
            min-height: 0;
            min-width: 0;
            position: relative;
        }
        .tour-conn {
            display: grid;
            grid-template-columns: minmax(140px, 1fr) 32px var(--line-w) 52px 16px;
            grid-template-rows: minmax(0, 1fr) minmax(0, 1fr);
            align-items: stretch;
            position: absolute;
            /* full shell height so outs line up with next-round slots */
            inset: 0 -56px 0 0;
            padding: 0.15rem 0 0.15rem 0.3rem;
            box-sizing: border-box;
            pointer-events: none;
            z-index: 3;
        }
        .tour-conn .tour-conn-slot { pointer-events: auto; }
        .tour-conn.single {
            grid-template-rows: 1fr;
        }
        .tour-conn.no-out {
            right: 0;
            grid-template-columns: minmax(140px, 1fr) 32px var(--line-w) 0 0;
        }
        .tour-conn-slot {
            display: flex;
            align-items: center;
            min-width: 0;
            padding: 0.2rem 0;
            position: relative;
        }
        .tour-conn-slot.s1 { grid-column: 1; grid-row: 1; }
        .tour-conn-slot.s2 {
            grid-column: 1;
            grid-row: 2;
            /* keep bottom player above End/Clear strip */
            align-items: flex-start;
            padding-top: 0.45rem;
            padding-bottom: 2.35rem;
        }
        .tour-conn.single .tour-conn-slot.s1 { grid-row: 1; }
        /* inbound stub into each player from previous round out */
        .tour-conn.has-in .tour-conn-slot::before {
            content: '';
            position: absolute;
            left: -56px;
            top: 50%;
            width: 56px;
            height: var(--line-w);
            background: var(--line);
            box-shadow: 0 0 0 0.5px rgba(255,255,255,0.35);
            transform: translateY(-50%);
        }
        .tour-conn-arm {
            height: var(--line-w);
            background: var(--line);
            box-shadow: 0 0 0 0.5px rgba(255,255,255,0.35);
            align-self: center;
            width: 100%;
        }
        .tour-conn-arm.a1 { grid-column: 2; grid-row: 1; }
        .tour-conn-arm.a2 { grid-column: 2; grid-row: 2; }
        .tour-conn.single .tour-conn-arm.a1 { grid-row: 1; }
        .tour-conn.single .tour-conn-arm.a2 { display: none; }
        .tour-conn-spine {
            grid-column: 3;
            grid-row: 1 / -1;
            width: var(--line-w);
            background: var(--line);
            box-shadow: 0 0 0 0.5px rgba(255,255,255,0.35);
            height: 50%;
            align-self: center;
            justify-self: center;
        }
        .tour-conn.single .tour-conn-spine { display: none; }
        .tour-conn-out {
            grid-column: 4;
            grid-row: 1 / -1;
            height: var(--line-w);
            background: var(--line);
            box-shadow: 0 0 0 0.5px rgba(255,255,255,0.35);
            align-self: center;
            width: 100%;
        }
        .tour-conn.single .tour-conn-out { grid-column: 2 / 5; }
        .tour-conn.no-out .tour-conn-out,
        .tour-conn.no-out .tour-conn-arrow { display: none; }
        .tour-conn-arrow {
            grid-column: 5;
            grid-row: 1 / -1;
            align-self: center;
            justify-self: start;
            color: var(--line);
            font-size: 0.75rem;
            line-height: 1;
            margin-left: -5px;
            text-shadow: 0 0 0 var(--line);
        }
        .tour-round-col + .tour-round-col {
            margin-left: -8px;
            padding-left: 56px;
        }
        .tour-conn-card {
            width: 100%;
            background: rgba(0,0,0,0.45);
            border: 1px solid rgba(255,255,255,0.16);
            border-radius: 6px;
            padding: 0.28rem 0.4rem;
            font-family: 'Exo 2', sans-serif;
            font-size: 0.8rem;
            font-weight: 600;
            color: #ddd;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.35rem;
            min-height: 1.85rem;
            box-sizing: border-box;
        }
        .tour-conn-card.tbd { color: #555; font-style: italic; font-weight: 500; }
        .tour-conn-card.winner {
            color: #fff;
            background: rgba(0, 212, 170, 0.15);
            border-color: rgba(0, 212, 170, 0.45);
        }
        .tour-conn-card.loser { opacity: 0.45; text-decoration: line-through; }
        .tour-conn-card.fed-in { box-shadow: inset 2px 0 0 var(--t-ready); }
        .tour-conn-card .fed-tag {
            margin-left: 0.3rem;
            font-size: 0.62rem;
            font-weight: 700;
            color: var(--t-ready);
        }
        .name-rank {
            margin-left: 0.3rem;
            font-size: 0.68rem;
            font-weight: 700;
            color: #a29bfe;
            font-family: 'Rajdhani', sans-serif;
            letter-spacing: 0.2px;
            white-space: nowrap;
        }
        .tour-match-shell {
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 8px;
            padding: 0;
            background: rgba(0,0,0,0.22);
            position: relative;
            flex: 1 1 0;
            margin: 0;
            min-width: 0;
            min-height: 200px;
            overflow: visible;
        }
        .tour-match-shell.status-ready {
            border-color: rgba(0, 212, 170, 0.65);
            box-shadow: 0 0 0 1px rgba(0, 212, 170, 0.15);
            animation: tourPulse 2.2s ease-in-out infinite;
        }
        .tour-match-shell.status-live { border-color: rgba(85, 163, 255, 0.7); }
        .tour-match-shell.status-waiting { border-color: rgba(240, 180, 41, 0.35); opacity: 0.9; }
        .tour-match-shell.status-done { border-color: rgba(150,150,160,0.25); }
        /* Byes keep flex height for connector alignment, but no empty-card chrome */
        .tour-match-shell.status-bye {
            border: none;
            background: transparent;
            box-shadow: none;
            opacity: 1;
        }
        .tour-match-shell.status-bye .tour-match-status {
            display: none;
        }
        .tour-match-shell.status-empty {
            border: none;
            background: transparent;
            min-height: 0;
        }
        .tour-match-wrap.is-empty {
            pointer-events: none;
        }
        .tour-match-wrap.is-empty .tour-match-shell {
            border: none;
            background: transparent;
            min-height: 0;
        }
        .tour-match-wrap.is-bye .tour-conn-card {
            border-style: dashed;
            border-color: rgba(255, 255, 255, 0.22);
            background: rgba(0, 0, 0, 0.32);
            max-width: 100%;
        }
        .tour-conn-card .bye-chip {
            margin-left: 0.35rem;
            font-size: 0.58rem;
            font-weight: 700;
            letter-spacing: 0.6px;
            color: #9a9aaa;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 3px;
            padding: 0.05rem 0.28rem;
            vertical-align: middle;
            font-family: 'Rajdhani', sans-serif;
        }
        /* Soften bye connector lines so play-in reads as the main R1 match */
        .tour-match-wrap.is-bye .tour-conn-arm,
        .tour-match-wrap.is-bye .tour-conn-out {
            opacity: 0.45;
        }
        .tour-match-wrap.is-bye .tour-conn-arrow {
            opacity: 0.45;
        }
        .tour-conn-card.tbd.awaiting {
            border-style: dashed;
            background: transparent;
            color: #666;
            font-style: normal;
            font-size: 0.72rem;
            font-weight: 500;
            letter-spacing: 0.3px;
        }
        .tour-match-shell.status-ready.is-playin {
            border-color: rgba(0, 212, 170, 0.75);
        }
        .tour-match-shell.forfeit-match { border-color: rgba(255, 118, 117, 0.45); }

        /* Compact path layout for bye-heavy groups (e.g. G1 with feeder) */
        .tour-path-bracket {
            display: flex;
            align-items: flex-start;
            justify-content: flex-start;
            gap: 0.65rem;
            flex-wrap: nowrap;
            overflow-x: auto;
            padding: 0.65rem 0.5rem 1rem;
        }
        .tour-path-col {
            display: flex;
            flex-direction: column;
            gap: 0.65rem;
            min-width: 210px;
            max-width: 270px;
            flex: 0 1 250px;
        }
        .tour-path-col .tour-round-title {
            text-align: left;
            margin-bottom: 0;
        }
        .tour-path-arrow {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.1rem;
            opacity: 0.55;
            padding: 1.6rem 0.15rem 0;
            flex: 0 0 auto;
            user-select: none;
        }
        .tour-match-wrap.plain {
            flex: 0 0 auto;
            min-height: 0;
        }
        .tour-match-shell.plain {
            position: relative;
            flex: 0 0 auto;
            min-height: 0;
            padding: 0.45rem 0.55rem 0.5rem;
            overflow: visible;
        }
        .tour-match-shell.plain .tour-match-status {
            position: static;
            display: inline-block;
            margin: 0 0 0.3rem;
            padding: 0;
            background: none;
            font-size: 0.65rem;
        }
        .tour-path-slots {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }
        .tour-path-sub {
            margin-top: 0.4rem;
            font-size: 0.68rem;
            color: #8a8a9a;
            line-height: 1.3;
        }
        .tour-match-wrap.plain > .tour-match-actions,
        .tour-match-shell.plain .tour-match-actions.in-flow {
            position: static;
            left: auto;
            right: auto;
            bottom: auto;
            margin-top: 0.45rem;
            background: transparent;
            padding: 0;
            border-radius: 0;
        }
        .tour-bye-strip {
            font-size: 0.72rem;
            color: #9a9aaa;
            border: 1px dashed rgba(255, 255, 255, 0.16);
            border-radius: 6px;
            padding: 0.5rem 0.6rem;
            line-height: 1.4;
            background: rgba(255, 255, 255, 0.02);
        }
        .tour-bye-strip strong {
            display: block;
            color: #c4c4d0;
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 0.2rem;
            font-family: 'Rajdhani', sans-serif;
        }
        .tour-path-pair-label {
            font-size: 0.7rem;
            color: #aaa;
            margin-bottom: 0.15rem;
        }
        .tour-match-shell .tour-match-status {
            position: absolute;
            top: 0.15rem;
            left: 0.35rem;
            margin: 0;
            background: rgba(10, 10, 16, 0.92);
            padding: 0 0.25rem;
            z-index: 5;
        }
        /*
         * Action row sits in the lower strip of the shell. Shells are tall enough
         * that player cards (centered in each half) clear these buttons.
         */
        .tour-match-wrap > .tour-match-actions {
            position: absolute;
            left: 0.3rem;
            right: 0.3rem;
            bottom: 0.3rem;
            display: flex;
            flex-wrap: nowrap;
            gap: 0.3rem;
            margin: 0;
            padding: 0.2rem;
            border-top: none;
            border-radius: 5px;
            background: rgba(8, 8, 14, 0.92);
            z-index: 6;
        }
        .tour-match-wrap > .tour-match-actions.placeholder {
            display: none;
        }
        .tour-match-shell .tour-from-label {
            position: absolute;
            top: 0.15rem;
            right: 0.35rem;
            font-size: 0.58rem;
            color: #666;
            z-index: 5;
        }
        .tour-asc-track {
            display: flex;
            align-items: center;
            gap: 0;
            overflow-x: auto;
            padding: 0.5rem 0;
            --line: #fff;
        }
        .tour-asc-step {
            display: flex;
            align-items: center;
            flex: 0 0 auto;
        }
        .tour-asc-join {
            width: 36px;
            height: 2px;
            background: #fff;
            position: relative;
            flex: 0 0 36px;
        }
        .tour-asc-join::after {
            content: '\25B6';
            position: absolute;
            right: -2px;
            top: 50%;
            transform: translateY(-50%);
            color: #fff;
            font-size: 0.65rem;
            line-height: 1;
        }
        .tour-asc-step .tour-match-wrap {
            min-width: 210px;
            margin: 0;
            flex: 0 0 auto;
        }
        .tour-asc-step .tour-match-shell {
            min-height: 88px;
        }
        .tour-code-champs-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            justify-content: center;
            margin-bottom: 0.85rem;
        }
        .tour-code-chip {
            font-family: 'Rajdhani', sans-serif;
            font-weight: 700;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            padding: 0.35rem 0.75rem;
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.2);
            background: rgba(0,0,0,0.3);
        }
        .tour-code-chip .who { font-family: 'Exo 2', sans-serif; font-weight: 600; margin-left: 0.35rem; }
        .tour-code-chip.pending { opacity: 0.55; }
        .tour-code-chip.code-S { border-color: rgba(255,215,0,0.45); color: #ffd700; }
        .tour-code-chip.code-A { border-color: rgba(192,192,192,0.45); color: #d0d0d0; }
        .tour-code-chip.code-B { border-color: rgba(205,127,50,0.5); color: #cd7f32; }

        @keyframes tourFeedPulse {
            0%, 100% { transform: translateY(0); opacity: 0.7; }
            50% { transform: translateY(-3px); opacity: 1; }
        }

        .save-indicator {
            position: fixed;
            bottom: 1rem;
            right: 1rem;
            padding: 0.5rem 1rem;
            background: rgba(0, 184, 148, 0.9);
            border-radius: 5px;
            color: #fff;
            display: none;
            z-index: 50;
        }
        .save-indicator.show {
            display: block;
            animation: fadeInOut 1.6s forwards;
        }
        @keyframes fadeInOut {
            0% { opacity: 0; transform: translateY(10px); }
            20% { opacity: 1; transform: translateY(0); }
            80% { opacity: 1; }
            100% { opacity: 0; }
        }
    </style>
</head>
<body>
<?php include_once '../includes/nav.php'; ?>

<div class="tour-page">
    <div class="tour-header">
        <a class="tour-back" href="index.php"><i class="fas fa-arrow-left"></i> Power Rankings</a>
        <h1>Challenger Bracket</h1>
        <p>Bottom-up group gauntlet — winners climb toward G1 for the FSL crown</p>
    </div>

    <div class="tour-stats" id="tourStats">
        <span class="tour-chip"><i class="fas fa-user-check"></i> <span id="statIn"><?= (int) $inCount ?></span> / <?= (int) $totalCount ?> in</span>
        <span class="tour-chip ready"><i class="fas fa-bolt"></i> <span id="statReady"><?= (int) ($bracket['counts']['ready'] ?? 0) ?></span> play now</span>
        <span class="tour-chip live"><i class="fas fa-play"></i> <span id="statLive"><?= (int) ($bracket['counts']['live'] ?? 0) ?></span> live</span>
        <span class="tour-chip waiting"><i class="fas fa-hourglass-half"></i> <span id="statWaiting"><?= (int) ($bracket['counts']['waiting'] ?? 0) ?></span> waiting</span>
        <span class="tour-chip done"><i class="fas fa-check"></i> <span id="statDone"><?= (int) ($bracket['counts']['done'] ?? 0) ?></span> done</span>
        <?php if (!empty($bracket['champion'])): ?>
        <span class="tour-chip champ" id="statChamp"><i class="fas fa-trophy"></i> <?= htmlspecialchars((string) $bracket['champion']) ?></span>
        <?php else: ?>
        <span class="tour-chip champ" id="statChamp" style="display:none"></span>
        <?php endif; ?>
    </div>

    <div class="tour-toolbar">
        <button type="button" class="tour-btn ghost" id="rosterToggleBtn" onclick="toggleRoster()">
            <i class="fas fa-users"></i> <span id="rosterToggleLabel">Show field</span>
        </button>
        <a class="tour-btn ghost" href="tournament_diagram.php" target="_blank" rel="noopener noreferrer" title="Render D2 bracket image">
            <i class="fas fa-project-diagram"></i> Bracket diagram
        </a>
        <?php if ($canEdit): ?>
        <button type="button" class="tour-btn" onclick="bulkParticipation(true)"><i class="fas fa-check-double"></i> All in</button>
        <button type="button" class="tour-btn ghost" onclick="bulkParticipation(false)"><i class="fas fa-times"></i> All out</button>
        <a class="tour-btn ghost" href="?action=export_json" title="Download signups + results JSON"><i class="fas fa-download"></i> Export JSON</a>
        <button type="button" class="tour-btn ghost" onclick="document.getElementById('importBracketFile').click()" title="Import signups (+ results if present)"><i class="fas fa-upload"></i> Import JSON</button>
        <input type="file" id="importBracketFile" accept="application/json,.json" style="display:none" onchange="importBracketFromFile(this)">
        <button type="button" class="tour-btn danger" onclick="resetResults()"><i class="fas fa-undo"></i> Reset results</button>
        <?php endif; ?>
      </div>
    <?php if ($canEdit): ?>
    <p class="text-center text-muted small mb-2">Admin: open the field to manage signups · export/import JSON to move the field between environments · enter scores on matches · End / Forfeit advances the winner</p>
    <?php else: ?>
    <p class="text-center text-muted small mb-2">Public view — bracket updates live. Field list is view-only.</p>
    <?php endif; ?>

    <div class="tour-layout" id="tourLayout">
        <aside class="tour-roster">
            <h2 class="tour-panel-title">
                Field
                <span class="sub"><?= $canEdit ? 'admin signup' : 'view only' ?></span>
            </h2>
            <div class="tour-roster-scroll" id="rosterList">
                <?php
                $byGroupRoster = [];
                foreach ($rankings as $p) {
                    $g = (int) $p['group'];
                    $byGroupRoster[$g][] = $p;
                }
                ksort($byGroupRoster);
                foreach ($byGroupRoster as $g => $plist):
                    $gIn = 0;
                    foreach ($plist as $pp) {
                        if (!empty($participatingMap[$pp['name']])) {
                            $gIn++;
                        }
                    }
                ?>
                <div class="tour-group-head" data-group="<?= (int) $g ?>">
                    <span>G<?= (int) $g ?> · <?= (int) $gIn ?>/<?= count($plist) ?></span>
                    <?php if ($canEdit): ?>
                    <span>
                        <button type="button" onclick="bulkParticipation(true, <?= (int) $g ?>)">all</button>
                        ·
                        <button type="button" onclick="bulkParticipation(false, <?= (int) $g ?>)">none</button>
                    </span>
                    <?php endif; ?>
                </div>
                <?php foreach ($plist as $p):
                    $name = (string) $p['name'];
                    $in = !empty($participatingMap[$name]);
                    $race = (string) ($p['race'] ?? 'R');
                ?>
                <div class="tour-player <?= $in ? 'in' : 'out' ?>" data-name="<?= htmlspecialchars($name) ?>">
                    <div class="skill-band g<?= (int) $g ?>"></div>
                    <span class="tour-rank"><?= (int) $p['rank'] ?></span>
                    <?php if (!empty($playerThumb[$name])): ?>
                    <img class="tour-player-thumb" src="../<?= htmlspecialchars($playerThumb[$name]) ?>" alt="" loading="lazy" onerror="this.style.display='none'">
                    <?php endif; ?>
                    <img class="tour-race" src="../images/<?= htmlspecialchars($raceIcons[$race] ?? 'random_icon.png') ?>" alt="">
                    <span class="tour-name">
                        <a href="../view_player.php?name=<?= urlencode($name) ?>"><?= htmlspecialchars($name) ?></a>
                    </span>
                    <?php if (!empty($playerTeamLogo[$name])): ?>
                    <img class="tour-team-logo" src="../<?= htmlspecialchars($playerTeamLogo[$name]) ?>" alt="">
                    <?php endif; ?>
                    <?php if ($canEdit): ?>
                    <button type="button"
                        class="tour-toggle <?= $in ? 'on' : '' ?>"
                        title="<?= $in ? 'Playing — click to sit out' : 'Sitting out — click to join' ?>"
                        data-player="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                        aria-pressed="<?= $in ? 'true' : 'false' ?>"></button>
                    <?php else: ?>
                    <span class="tour-toggle <?= $in ? 'on' : '' ?>" title="<?= $in ? 'Playing' : 'Sitting out' ?>" aria-hidden="true"></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </aside>

        <section class="tour-bracket-panel">
            <h2 class="tour-panel-title">
                Bracket
                <span class="sub">group play → climb to G1</span>
            </h2>
            <div class="tour-bracket-scroll" id="bracketRoot">
                <!-- filled by JS from initial + updates -->
            </div>
        </section>
    </div>
</div>

<div class="save-indicator" id="saveIndicator">Saved</div>

<script>
const CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
let bracketData = <?= json_encode($bracket, JSON_UNESCAPED_UNICODE) ?>;
let participating = <?= json_encode($participatingMap, JSON_UNESCAPED_UNICODE) ?>;
const PLAYER_THUMBS = <?= json_encode($playerThumb, JSON_UNESCAPED_UNICODE) ?>;

function showSaved() {
    const el = document.getElementById('saveIndicator');
    el.classList.remove('show');
    void el.offsetWidth;
    el.classList.add('show');
}

function toggleRoster() {
    const layout = document.getElementById('tourLayout');
    const open = layout.classList.toggle('roster-open');
    document.getElementById('rosterToggleLabel').textContent = open ? 'Hide field' : 'Show field';
    try { localStorage.setItem('fsl_tour_roster_open', open ? '1' : '0'); } catch (e) {}
}

async function api(action, payload) {
    const res = await fetch('tournament.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ action }, payload || {}))
    });
    let data;
    try { data = await res.json(); }
    catch (e) { alert('Bad server response'); throw e; }
    if (!data.success) {
        alert(data.error || 'Request failed');
        throw new Error(data.error || 'fail');
    }
    if (data.bracket) bracketData = data.bracket;
    if (data.state && data.state.participating) {
        participating = {};
        Object.keys(data.state.participating).forEach(n => {
            if (data.state.participating[n]) participating[n] = true;
        });
    }
    showSaved();
    syncRosterUi();
    renderBracket();
    updateStats();
    return data;
}

function syncRosterUi() {
    document.querySelectorAll('.tour-player').forEach(row => {
        const name = row.getAttribute('data-name');
        const on = !!participating[name];
        row.classList.toggle('in', on);
        row.classList.toggle('out', !on);
        const tog = row.querySelector('.tour-toggle');
        if (tog) {
            tog.classList.toggle('on', on);
            if (tog.tagName === 'BUTTON') {
                tog.setAttribute('aria-pressed', on ? 'true' : 'false');
                tog.title = on ? 'Playing — click to sit out' : 'Sitting out — click to join';
            }
        }
    });
    document.querySelectorAll('.tour-group-head').forEach(head => {
        const g = head.getAttribute('data-group');
        let inCount = 0, total = 0;
        let el = head.nextElementSibling;
        while (el && !el.classList.contains('tour-group-head')) {
            if (el.classList.contains('tour-player')) {
                total++;
                if (el.classList.contains('in')) inCount++;
            }
            el = el.nextElementSibling;
        }
        const label = head.querySelector('span');
        if (label) label.textContent = 'G' + g + ' · ' + inCount + '/' + total;
    });
}

function updateStats() {
    rebuildNameRankMap();
    const c = bracketData.counts || {};
    document.getElementById('statIn').textContent = bracketData.participant_count || 0;
    document.getElementById('statReady').textContent = c.ready || 0;
    document.getElementById('statLive').textContent = c.live || 0;
    document.getElementById('statWaiting').textContent = c.waiting || 0;
    document.getElementById('statDone').textContent = c.done || 0;
    const champEl = document.getElementById('statChamp');
    if (champEl) {
        if (bracketData.champion) {
            champEl.style.display = '';
            champEl.innerHTML = '<i class="fas fa-trophy"></i> ' + nameWithRankHtml(bracketData.champion);
        } else {
            champEl.style.display = 'none';
            champEl.innerHTML = '';
        }
    }
}

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function scoreVal(v) {
    return (v === null || v === undefined || v === '') ? '' : String(v);
}

/** name → overall power rank (from group rosters) */
let nameRankMap = {};

function rebuildNameRankMap() {
    const map = {};
    const groups = (bracketData && bracketData.groups) || {};
    Object.keys(groups).forEach(g => {
        const gb = groups[g];
        if (!gb) return;
        (gb.players || []).concat(gb.fed_in || []).forEach(p => {
            if (!p || !p.name || p.is_placeholder || p.rank == null || p.rank === '') return;
            if (/^G\d+ winner$/.test(String(p.name))) return;
            map[p.name] = Number(p.rank);
        });
    });
    nameRankMap = map;
}

function playerRank(name) {
    if (name == null || nameRankMap[name] == null || Number.isNaN(nameRankMap[name])) return null;
    return nameRankMap[name];
}

function nameWithRankText(name) {
    if (name == null) return '';
    const r = playerRank(name);
    return r == null ? String(name) : (String(name) + ' #' + r);
}

function playerThumbHtml(name) {
    if (name == null) return '';
    const path = PLAYER_THUMBS[name];
    if (!path) return '';
    return '<img class="tour-player-thumb" src="../' + esc(path) + '" alt="" loading="lazy" onerror="this.style.display=\'none\'">';
}

function nameWithRankHtml(name) {
    if (name == null) return '';
    const r = playerRank(name);
    let label = esc(name);
    if (r != null) {
        label += '<span class="name-rank" title="Overall rank ' + esc(r) + '">#' + esc(r) + '</span>';
    }
    return '<span class="tour-pname-wrap">' + playerThumbHtml(name) +
        '<span class="tour-pname">' + label + '</span></span>';
}

function slotCardHtml(match, which, fedMap) {
    const name = which === 1 ? match.p1 : match.p2;
    const other = which === 1 ? match.p2 : match.p1;
    const score = which === 1 ? match.score1 : match.score2;
    if (name == null) {
        const hint = which === 1 ? match.slotHint1 : match.slotHint2;
        if (hint) {
            return '<div class="tour-conn-card tbd awaiting">' + esc(hint) + '</div>';
        }
        // One side known → soft “Awaiting” instead of loud TBD
        if (other != null && (match.status === 'waiting' || match.status === 'ready')) {
            return '<div class="tour-conn-card tbd awaiting">Awaiting</div>';
        }
        return '<div class="tour-conn-card tbd">TBD</div>';
    }
    let cls = 'tour-conn-card';
    // Byes are auto-advance, not a won match — keep them visually quiet
    if (match.status === 'done') {
        if (match.winner === name) cls += ' winner';
        else if (other != null) cls += ' loser';
    }
    const fedFrom = fedMap && fedMap[name];
    const isPlaceholder = /^G\d+ winner$/.test(String(name));
    if (fedFrom) cls += ' fed-in';
    if (isPlaceholder) cls += ' tbd awaiting';
    const fedTag = fedFrom && !isPlaceholder
        ? '<span class="fed-tag" title="Fed up from G' + fedFrom + '">↑G' + fedFrom + '</span>'
        : (isPlaceholder
            ? '<span class="fed-tag" title="Reserved feeder slot">↑ feed</span>'
            : '');
    const byeChip = (match.status === 'bye')
        ? '<span class="bye-chip">BYE</span>'
        : '';
    const ffBadge = (match.forfeit === name)
        ? '<span class="tour-ff-badge">FF</span>'
        : '';

    let scoreHtml = '';
    if (match.status === 'bye' || match.status === 'waiting' || match.status === 'empty') {
        scoreHtml = '';
    } else if (CAN_EDIT && (match.status === 'ready' || match.status === 'live' || match.status === 'done')) {
        scoreHtml = '<input type="number" min="0" class="tour-score-input" data-score-side="' + which +
            '" data-match-id="' + esc(match.id) + '" value="' + esc(scoreVal(score)) + '"' +
            (match.status === 'done' ? ' disabled' : '') + '>';
    } else {
        scoreHtml = '<span class="tour-score-display">' + (score == null ? '–' : esc(score)) + '</span>';
    }

    let ffBtn = '';
    if (CAN_EDIT && (match.status === 'ready' || match.status === 'live')) {
        ffBtn = '<button type="button" class="tour-ff-btn" data-forfeit-match="' + esc(match.id) +
            '" data-forfeit-player="' + esc(name) + '" title="Mark forfeit">FF</button>';
    }

    return '<div class="' + cls + '">' +
        '<div class="tour-score-row"><span class="tour-slot-who">' + nameWithRankHtml(name) + fedTag + byeChip + ffBadge + '</span></div>' +
        scoreHtml + ffBtn +
        '</div>';
}

function matchActionsHtml(match) {
    const st = match.status || 'waiting';
    if (CAN_EDIT && (st === 'ready' || st === 'live')) {
        return '<button type="button" class="btn-end" data-end-match="' + esc(match.id) + '">End match</button>' +
            '<button type="button" class="btn-clear" data-clear-match="' + esc(match.id) + '">Clear</button>';
    }
    if (CAN_EDIT && st === 'done') {
        return '<button type="button" class="btn-reopen" data-reopen-match="' + esc(match.id) + '">Undo end</button>' +
            '<button type="button" class="btn-clear" data-clear-match="' + esc(match.id) + '">Clear</button>';
    }
    return '';
}

function matchStatusLabel(match, fedMap, opts) {
    opts = opts || {};
    const st = match.status || 'waiting';
    const isByeSolo = (st === 'bye' || match.is_bye) && ((match.p1 && !match.p2) || (!match.p1 && match.p2));
    const isPlayIn = !!opts.forcePlayIn || (!isByeSolo && fedMap && (
        (match.p1 && fedMap[match.p1]) || (match.p2 && fedMap[match.p2])
    ));
    const labels = {
        ready: isPlayIn ? 'Play-in' : 'Play now',
        live: isPlayIn ? 'Play-in · Live' : 'Live',
        waiting: 'Waiting',
        bye: 'BYE · advances',
        done: 'Final',
        empty: 'Empty'
    };
    const text = (opts.statusLabel || labels[st] || st) + (match.forfeit ? ' · FF' : '');
    return { text: text, isPlayIn: isPlayIn, isByeSolo: isByeSolo };
}

function matchCard(match, fedMap, opts) {
    opts = opts || {};
    const showArrow = opts.showArrow !== false;
    const hasIn = !!opts.hasIn;
    const plain = !!opts.plain;
    const st = match.status || 'waiting';

    // Keep flex slot for alignment, but draw nothing for empty pad cells
    if (st === 'empty' || (match.p1 == null && match.p2 == null && st !== 'waiting')) {
        if (plain) return '';
        return '<div class="tour-match-wrap is-empty" aria-hidden="true">' +
            '<div class="tour-match-shell status-empty"></div></div>';
    }

    const meta = matchStatusLabel(match, fedMap, opts);
    const label = meta.text;
    const isByeSolo = meta.isByeSolo;
    const isPlayIn = meta.isPlayIn;
    const ffClass = match.forfeit ? ' forfeit-match' : '';
    const playInClass = isPlayIn ? ' is-playin' : '';
    const actionsInner = matchActionsHtml(match);
    const byeWhich = (isByeSolo && !match.p1 && match.p2) ? 2 : 1;

    if (plain) {
        let slots = '<div class="tour-path-slots">';
        if (isByeSolo) {
            slots += slotCardHtml(match, byeWhich, fedMap);
        } else {
            slots += slotCardHtml(match, 1, fedMap);
            slots += slotCardHtml(match, 2, fedMap);
        }
        slots += '</div>';
        const pair = match.pairLabel
            ? '<div class="tour-path-pair-label">' + esc(match.pairLabel) + '</div>'
            : '';
        const sub = match.pathSub
            ? '<div class="tour-path-sub">' + esc(match.pathSub) + '</div>'
            : '';
        const actions = actionsInner
            ? '<div class="tour-match-actions in-flow">' + actionsInner + '</div>'
            : '';
        return '<div class="tour-match-wrap plain" data-match="' + esc(match.id) + '">' +
            '<div class="tour-match-shell plain status-' + esc(st) + ffClass + playInClass + '">' +
            '<span class="tour-match-status">' + label + '</span>' +
            pair + slots + sub + actions +
            '</div></div>';
    }

    const from = (match.from1 && match.from2)
        ? '<div class="tour-from-label">' + esc(match.from1) + ' · ' + esc(match.from2) + '</div>'
        : '';
    const actionsCls = 'tour-match-actions' + (actionsInner ? '' : ' placeholder');

    let connCls = 'tour-conn';
    if (isByeSolo) connCls += ' single';
    if (hasIn) connCls += ' has-in';
    if (!showArrow) connCls += ' no-out';

    let conn = '<div class="' + connCls + '">';
    if (isByeSolo) {
        conn += '<div class="tour-conn-slot s1">' + slotCardHtml(match, byeWhich, fedMap) + '</div>';
        conn += '<div class="tour-conn-arm a1" aria-hidden="true"></div>';
    } else {
        conn += '<div class="tour-conn-slot s1">' + slotCardHtml(match, 1, fedMap) + '</div>';
        conn += '<div class="tour-conn-arm a1" aria-hidden="true"></div>';
        conn += '<div class="tour-conn-slot s2">' + slotCardHtml(match, 2, fedMap) + '</div>';
        conn += '<div class="tour-conn-arm a2" aria-hidden="true"></div>';
        conn += '<div class="tour-conn-spine" aria-hidden="true"></div>';
    }
    if (showArrow) {
        conn += '<div class="tour-conn-out" aria-hidden="true"></div>';
        conn += '<div class="tour-conn-arrow" aria-hidden="true">&#9654;</div>';
    }
    conn += '</div>';

    const wrapCls = 'tour-match-wrap' + (isByeSolo ? ' is-bye' : '');
    return '<div class="' + wrapCls + '" data-match="' + esc(match.id) + '">' +
        '<div class="tour-match-shell status-' + esc(st) + ffClass + playInClass + '">' +
        '<span class="tour-match-status">' + label + '</span>' +
        from +
        conn +
        '</div>' +
        '<div class="' + actionsCls + '">' + actionsInner + '</div>' +
        '</div>';
}

function feedMapForGroup(gb) {
    const map = {};
    (gb.fed_in || []).forEach(p => {
        if (p && p.name) map[p.name] = p.origin_group || p.group || '?';
    });
    return map;
}

function renderClimbBridge(fromG, toG, name, seed) {
    const seedTxt = seed ? (' · seed ' + seed) : '';
    return '<div class="tour-climb-bridge" aria-label="' + esc(nameWithRankText(name)) + ' advances from G' + fromG + ' to G' + toG + '">' +
        '<div class="tour-climb-shaft" aria-hidden="true">' +
        '<div class="tour-climb-head">&#9650;</div>' +
        '<div class="tour-climb-vline"></div>' +
        '</div>' +
        '<div class="tour-climb-card">' +
        '<div class="who">' + nameWithRankHtml(name) + '</div>' +
        '<div class="path"><i class="fas fa-arrow-up"></i>G' + fromG + ' winner → G' + toG + seedTxt + '</div>' +
        '</div></div>';
}

function describeR1Source(m) {
    if (!m || m.status === 'empty') return null;
    if (m.status === 'bye' || m.is_bye) {
        return { type: 'bye', name: m.p1 || m.p2 || null };
    }
    return { type: 'match', match: m };
}

function annotateFromPriorRound(priorRound, match, matchIndex) {
    const out = Object.assign({}, match);
    const s1 = describeR1Source(priorRound[matchIndex * 2]);
    const s2 = describeR1Source(priorRound[matchIndex * 2 + 1]);

    if (!out.p1 && s1) {
        out.slotHint1 = s1.type === 'match' ? 'Play-in winner' : 'TBD';
    }
    if (!out.p2 && s2) {
        out.slotHint2 = s2.type === 'match' ? 'Play-in winner' : 'TBD';
    }

    if (out.p1 && !out.p2 && s1 && s1.type === 'bye' && s2 && s2.type === 'match') {
        out.pairLabel = 'Semi';
        out.pathSub = nameWithRankText(out.p1) + ' (bye) plays the play-in winner';
        out.slotHint2 = 'Play-in winner';
    } else if (!out.p1 && out.p2 && s2 && s2.type === 'bye' && s1 && s1.type === 'match') {
        out.pairLabel = 'Semi';
        out.pathSub = nameWithRankText(out.p2) + ' (bye) plays the play-in winner';
        out.slotHint1 = 'Play-in winner';
    } else if (out.p1 && out.p2 && s1 && s1.type === 'bye' && s2 && s2.type === 'bye') {
        out.pairLabel = 'Semi';
        out.pathSub = 'Both advanced by bye';
    } else if (out.p1 && out.p2) {
        out.pairLabel = 'Semi';
    } else if (!out.p1 && !out.p2) {
        out.pairLabel = 'Final';
        out.pathSub = 'Winners of each semi';
        out.slotHint1 = out.slotHint1 || 'Semi winner';
        out.slotHint2 = out.slotHint2 || 'Semi winner';
    }
    return out;
}

/** Clear Play-in → Semis → Final layout when R1 has structural byes. */
function renderPathBracket(rounds, fedMap) {
    const r1 = rounds[0] || [];
    const later = rounds.slice(1);
    const playIns = r1.filter(m => m && m.status !== 'bye' && m.status !== 'empty');
    const byes = r1.filter(m => m && (m.status === 'bye' || m.is_bye) && (m.p1 || m.p2));

    let html = '<div class="tour-path-bracket">';

    html += '<div class="tour-path-col">';
    html += '<div class="tour-round-title">' + (playIns.length ? 'Play-in' : 'Round 1') + '</div>';
    if (!playIns.length) {
        html += '<div class="tour-bye-strip">No play-in match — all seeds advance by bye.</div>';
    }
    playIns.forEach(m => {
        const hasFed = fedMap && ((m.p1 && fedMap[m.p1]) || (m.p2 && fedMap[m.p2]));
        const ann = Object.assign({}, m, {
            pathSub: hasFed
                ? 'Winner faces the #1 seed in semis'
                : 'Winner advances to semifinals'
        });
        html += matchCard(ann, fedMap, {
            plain: true,
            forcePlayIn: false,
            statusLabel: (m.status === 'ready' || m.status === 'live')
                ? (m.status === 'live' ? 'Live' : 'Play now')
                : null
        });
    });
    if (byes.length) {
        html += '<div class="tour-bye-strip"><strong>Byes into semis</strong>' +
            byes.map(m => nameWithRankHtml(m.p1 || m.p2)).join(' · ') + '</div>';
    }
    html += '</div>';

    later.forEach((round, li) => {
        const isLast = li === later.length - 1;
        const isSemi = li === 0 && !isLast;
        const title = isLast ? 'Final' : (isSemi ? 'Semifinals' : ('Round ' + (li + 2)));
        const prior = li === 0 ? r1 : later[li - 1];

        html += '<div class="tour-path-arrow" aria-hidden="true">&#9654;</div>';
        html += '<div class="tour-path-col">';
        html += '<div class="tour-round-title">' + title + '</div>';
        round.forEach((m, mi) => {
            let ann = Object.assign({}, m);
            if (li === 0) {
                ann = annotateFromPriorRound(prior, m, mi);
            } else if (isLast) {
                ann.pairLabel = 'Final';
                ann.pathSub = 'Winners of each semi';
                if (!ann.p1) ann.slotHint1 = 'Semi winner';
                if (!ann.p2) ann.slotHint2 = 'Semi winner';
            }
            const statusLabel = isLast && ann.status === 'waiting' ? 'Waiting' : null;
            html += matchCard(ann, fedMap, {
                plain: true,
                statusLabel: statusLabel
            });
        });
        html += '</div>';
    });

    html += '</div>';
    return html;
}

function renderRoundsTree(rounds, fedMap) {
    if (!rounds || !rounds.length) return '';
    const r1 = rounds[0] || [];
    const hasBye = r1.some(m => m && (m.status === 'bye' || m.is_bye));
    // Bye-padded trees (e.g. 5 into 8) get a clear path layout instead of spaghetti connectors
    if (hasBye) return renderPathBracket(rounds, fedMap);

    let html = '<div class="tour-bracket-tree">';
    rounds.forEach((round, ri) => {
        const isLast = ri === rounds.length - 1;
        const title = isLast ? 'Final' : ('Round ' + (ri + 1));
        html += '<div class="tour-round-col">';
        html += '<div class="tour-round-title">' + title + '</div>';
        html += '<div class="tour-round-body">';
        round.forEach(m => {
            html += matchCard(m, fedMap, { showArrow: !isLast, hasIn: ri > 0 });
        });
        html += '</div></div>';
    });
    html += '</div>';
    return html;
}

function renderBracket() {
    const root = document.getElementById('bracketRoot');
    const groups = bracketData.groups || {};
    const feeds = bracketData.feeds || [];
    const asc = bracketData.ascension || [];
    const maxG = bracketData.max_group || 0;
    const parts = bracketData.participant_count || 0;
    const codeChamps = bracketData.code_champs || {};
    rebuildNameRankMap();

    if (!parts) {
        root.innerHTML = '<div class="tour-empty"><i class="fas fa-sitemap"></i>' +
            'No players in the field yet.<br>' +
            (CAN_EDIT ? 'Open the field panel to mark participants.' : 'Check back once the field is set.') +
            '</div>';
        return;
    }

    const feedsByTo = {};
    feeds.forEach(f => { feedsByTo[f.to_group] = f; });

    let html = '';

    // Code champ summary chips
    html += '<div class="tour-code-champs-row">';
    ['B', 'A', 'S'].forEach(code => {
        const cc = codeChamps[code] || {};
        const ready = !!cc.ready && !!cc.name;
        html += '<div class="tour-code-chip code-' + code + (ready ? '' : ' pending') + '">' +
            'Code ' + code + ' Champ' +
            (ready
                ? '<span class="who">' + nameWithRankHtml(cc.name) + '</span>'
                : '<span class="who" style="color:#777">TBD</span>') +
            '</div>';
    });
    html += '</div>';

    if (bracketData.champion) {
        html += '<div class="tour-champ-banner"><i class="fas fa-crown"></i> Code S / FSL Champion: ' +
            nameWithRankHtml(bracketData.champion) + '</div>';
    }

    html += '<div class="tour-legend">' +
        '<span class="ready"><i class="fas fa-circle"></i> Play now</span>' +
        '<span class="live"><i class="fas fa-circle"></i> Live</span>' +
        '<span class="waiting"><i class="fas fa-circle"></i> Waiting</span>' +
        '<span class="done"><i class="fas fa-circle"></i> Final</span>' +
        '<span class="ready"><i class="fas fa-arrow-up"></i> Solo feeds up</span>' +
        '</div>';

    let lastCode = null;
    // Render S → A → B visually top→bottom as G1…Gmax
    for (let g = 1; g <= maxG; g++) {
        const gb = groups[g];
        if (!gb || !(gb.players && gb.players.length)) continue;

        const code = gb.code || 'B';
        if (code !== lastCode) {
            const cc = codeChamps[code] || {};
            const ready = !!cc.ready && !!cc.name;
            html += '<div class="tour-code-banner code-' + code + '">' +
                '<span>Code ' + code + '</span>' +
                '<span class="code-champ-label' + (ready ? '' : ' pending') + '">' +
                (ready ? ('Champ: ' + nameWithRankHtml(cc.name)) : 'Champ: TBD — last survivor of this code') +
                '</span></div>';
            lastCode = code;
        }

        const fedMap = feedMapForGroup(gb);
        const hasRounds = !!(gb.rounds && gb.rounds.length);
        const hasFeed = !!(gb.feeds_up || gb.waiting_for_feed || (gb.fed_in && gb.fed_in.length) || gb.fed_champion_up);

        // Climb arrow sits in the gap above this lower group (pointing up into G-1)
        if (gb.fed_champion_up && gb.champion && g > 1) {
            const dest = groups[g - 1];
            let seed = null;
            ((dest && dest.fed_in) || []).forEach(p => {
                if (p && p.name === gb.champion) seed = p.seed || null;
            });
            html += renderClimbBridge(g, g - 1, gb.champion, seed);
        }

        if (gb.feeds_up && !hasRounds) {
            const who = (gb.players || []).map(p => nameWithRankHtml(p.name)).join(', ');
            const to = gb.feed_to || (g - 1);
            html += '<div class="tour-feed-hop" id="group-block-' + g + '">' +
                '<div class="tour-feed-arrow-stack" aria-hidden="true">' +
                '<i class="fas fa-chevron-up"></i><i class="fas fa-chevron-up"></i></div>' +
                '<div><strong>G' + g + '</strong> · ' + who +
                '<div style="color:#6a9;font-size:0.75rem;margin-top:0.15rem">Only one here — joins the next group as lowest seed</div></div>' +
                '<span class="tour-feed-to"><i class="fas fa-arrow-up"></i> into G' + to + '</span>' +
                '</div>';
            continue;
        }

        // Legacy: solo host with no bracket yet. Reserved-feeder groups have rounds — render those.
        if (gb.waiting_for_feed && !hasRounds) {
            const who = (gb.players || []).map(p => nameWithRankHtml(p.name)).join(', ');
            html += '<div class="tour-feed-hop tour-feed-wait" id="group-block-' + g + '">' +
                '<div class="tour-feed-arrow-stack" aria-hidden="true">' +
                '<i class="fas fa-hourglass-half"></i></div>' +
                '<div><strong>G' + g + '</strong> · ' + who +
                '<div style="color:#c9a86a;font-size:0.75rem;margin-top:0.15rem">' +
                'Waiting for G' + (g + 1) + ' winner — they join here as lowest seed, then this group plays</div></div>' +
                '<span class="tour-feed-to" style="color:var(--t-waiting)"><i class="fas fa-lock"></i> holds for feed</span>' +
                '</div>';
            continue;
        }

        html += '<div class="tour-group-block' +
            (hasFeed ? ' has-feed' : '') +
            (gb.fed_champion_up ? ' has-climb-out' : '') +
            '" id="group-block-' + g + '">';
        html += '<div class="tour-group-label">Group ' + g;
        if (gb.auto_champion) {
            html += '<span class="solo-pill">solo advance · ' + nameWithRankHtml(gb.champion) + '</span>';
        } else if (gb.waiting_for_feed) {
            html += '<span class="solo-pill" style="border-color:rgba(201,168,106,.35);color:#c9a86a">awaiting lower feed</span>';
        }
        html += '</div>';

        const inbound = feedsByTo[g];
        if (inbound && inbound.players && inbound.players.length) {
            html += '<div class="tour-feed-inbound">' +
                '<span class="tour-feed-arrow-in"><i class="fas fa-arrow-up"></i></span>' +
                '<span>Joined from below as lowest seed' +
                (inbound.players.length > 1 ? 's' : '') + ':</span>';
            inbound.players.forEach(p => {
                const isPh = !!(p && (p.is_placeholder || /^G\d+ winner$/.test(String(p.name || ''))));
                if (isPh) {
                    const og = p.origin_group || '?';
                    html += '<span class="tour-feed-chip"><i class="fas fa-hourglass-half"></i> Awaiting G' +
                        og + ' winner · seed ' + (p.seed || '?') + '</span>';
                    return;
                }
                const r = playerRank(p.name);
                const rankBit = r != null ? (' · #' + r) : '';
                html += '<span class="tour-feed-chip"><i class="fas fa-arrow-up"></i> ' +
                    esc(p.name) + rankBit + ' · seed ' + (p.seed || '?') + ' ←G' + p.origin_group + '</span>';
            });
            html += '</div>';
        }

        const rounds = gb.rounds || [];
        if (!rounds.length) {
            if (gb.champion) {
                html += '<div class="tour-conn-card winner" style="max-width:220px">' + nameWithRankHtml(gb.champion) + '</div>';
            }
        } else {
            html += renderRoundsTree(rounds, fedMap);
        }
        html += '</div>';
    }

    if (asc.length) {
        html += '<div class="tour-road"><h3><i class="fas fa-mountain"></i> Road to G1 — winners climb</h3>';
        html += '<p style="color:#888;font-size:0.8rem;margin:-0.25rem 0 0.65rem">White lines show advance path. Code champs are the last survivor of each code.</p>';
        html += '<div class="tour-asc-track">';
        asc.forEach((m, i) => {
            if (i > 0) html += '<div class="tour-asc-join" aria-hidden="true"></div>';
            html += '<div class="tour-asc-step">' + matchCard(m, null, { showArrow: false, hasIn: false, compact: true }) + '</div>';
        });
        html += '</div></div>';
    }

    root.innerHTML = html;
}

async function toggleParticipation(name, btn) {
    if (!CAN_EDIT || !btn || btn.tagName !== 'BUTTON') return;
    const currentlyOn = btn.classList.contains('on');
    const next = !currentlyOn;
    btn.classList.toggle('on', next);
    const row = btn.closest('.tour-player');
    if (row) {
        row.classList.toggle('in', next);
        row.classList.toggle('out', !next);
    }
    try {
        await api('toggle_participation', { name, participating: next });
    } catch (e) {
        btn.classList.toggle('on', currentlyOn);
        if (row) {
            row.classList.toggle('in', currentlyOn);
            row.classList.toggle('out', !currentlyOn);
        }
    }
}

document.getElementById('rosterList').addEventListener('click', (ev) => {
    if (!CAN_EDIT) return;
    const btn = ev.target.closest('button.tour-toggle');
    if (!btn || !document.getElementById('rosterList').contains(btn)) return;
    ev.preventDefault();
    const name = btn.getAttribute('data-player') || btn.closest('.tour-player')?.getAttribute('data-name');
    if (!name) return;
    toggleParticipation(name, btn);
});

document.getElementById('bracketRoot').addEventListener('click', (ev) => {
    if (!CAN_EDIT) return;
    const endBtn = ev.target.closest('[data-end-match]');
    if (endBtn) {
        ev.preventDefault();
        endMatch(endBtn.getAttribute('data-end-match'));
        return;
    }
    const reopenBtn = ev.target.closest('[data-reopen-match]');
    if (reopenBtn) {
        ev.preventDefault();
        reopenMatch(reopenBtn.getAttribute('data-reopen-match'));
        return;
    }
    const clearBtn = ev.target.closest('[data-clear-match]');
    if (clearBtn) {
        ev.preventDefault();
        clearMatch(clearBtn.getAttribute('data-clear-match'));
        return;
    }
    const ffBtn = ev.target.closest('[data-forfeit-match]');
    if (ffBtn) {
        ev.preventDefault();
        forfeitMatch(ffBtn.getAttribute('data-forfeit-match'), ffBtn.getAttribute('data-forfeit-player'));
    }
});

document.getElementById('bracketRoot').addEventListener('change', (ev) => {
    if (!CAN_EDIT) return;
    const input = ev.target.closest('input.tour-score-input');
    if (!input) return;
    const matchId = input.getAttribute('data-match-id');
    const card = input.closest('.tour-match-wrap');
    if (!matchId || !card) return;
    const s1El = card.querySelector('input[data-score-side="1"]');
    const s2El = card.querySelector('input[data-score-side="2"]');
    const score1 = s1El && s1El.value !== '' ? parseInt(s1El.value, 10) : null;
    const score2 = s2El && s2El.value !== '' ? parseInt(s2El.value, 10) : null;
    updateScores(matchId, score1, score2);
});

async function bulkParticipation(on, group) {
    if (!CAN_EDIT) return;
    const payload = { participating: !!on };
    if (group != null) payload.group = group;
    await api('set_participation_bulk', payload);
}

async function importBracketFromFile(input) {
    if (!CAN_EDIT || !input || !input.files || !input.files[0]) return;
    const file = input.files[0];
    input.value = '';
    let payload;
    try {
        const text = await file.text();
        payload = JSON.parse(text);
    } catch (e) {
        alert('Could not parse JSON file');
        return;
    }
    if (!payload || typeof payload !== 'object') {
        alert('JSON must be an object');
        return;
    }
    const hasResults = payload.results && typeof payload.results === 'object'
        && !Array.isArray(payload.results)
        && Object.keys(payload.results).length > 0;
    if (!confirm('Import signups from this file? This replaces who is in/out.')) {
        return;
    }
    let includeResults = false;
    if (hasResults) {
        includeResults = confirm('This file also has match results. Replace current results too?');
    }
    try {
        const data = await api('import_bracket', { payload, include_results: includeResults });
        const info = data.import || {};
        const bits = [
            (info.imported_in || 0) + ' in',
            (info.imported_out || 0) + ' out'
        ];
        if (info.results_replaced) bits.push('results replaced');
        alert('Imported: ' + bits.join(' · '));
    } catch (e) { /* alerted */ }
}

async function updateScores(matchId, score1, score2) {
    await api('update_scores', { match_id: matchId, score1, score2 });
}

async function endMatch(matchId) {
    const card = document.querySelector('.tour-match-wrap[data-match="' + matchId + '"]');
    let winner = '';
    if (card) {
        const s1 = card.querySelector('input[data-score-side="1"]');
        const s2 = card.querySelector('input[data-score-side="2"]');
        // Prefer server-side infer; if tied, ask
        if (s1 && s2 && s1.value !== '' && s2.value !== '' && s1.value === s2.value) {
            const names = [...card.querySelectorAll('.tour-conn-card:not(.tbd)')].map(el => {
                const t = el.querySelector('.tour-pname');
                if (!t) return '';
                const clone = t.cloneNode(true);
                clone.querySelectorAll('.name-rank').forEach(n => n.remove());
                return clone.textContent.trim();
            }).filter(Boolean);
            winner = prompt('Tied score — type the winner name exactly:\n' + names.join(' vs '), names[0] || '');
            if (!winner) return;
        }
    }
    try {
        await api('end_match', { match_id: matchId, winner });
    } catch (e) { /* alerted */ }
}

async function forfeitMatch(matchId, player) {
    if (!confirm('Mark ' + player + ' as forfeit? The opponent advances.')) return;
    await api('forfeit_match', { match_id: matchId, player });
}

async function reopenMatch(matchId) {
    await api('reopen_match', { match_id: matchId });
}

async function clearMatch(matchId) {
    if (!confirm('Clear scores and result for this match?')) return;
    await api('clear_match', { match_id: matchId });
}

async function resetResults() {
    if (!confirm('Clear all match results? Participation stays the same.')) return;
    await api('reset_results', {});
}

// Restore roster preference (default: hidden)
try {
    if (localStorage.getItem('fsl_tour_roster_open') === '1') {
        document.getElementById('tourLayout').classList.add('roster-open');
        document.getElementById('rosterToggleLabel').textContent = 'Hide field';
    }
} catch (e) {}

renderBracket();
updateStats();
</script>
</body>
</html>
