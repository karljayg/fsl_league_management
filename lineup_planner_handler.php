<?php
/**
 * Lineup Planner – push planned matchups into fsl_matches + fsl_schedule_matches.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ob_start();
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/fsl_schedule_permission.php';
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Setup failed: ' . $e->getMessage()]);
    exit;
}

$authError = fsl_lineup_send_auth_error();
if ($authError !== null) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => $authError]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$action = $_POST['action'] ?? '';
if ($action !== 'send_to_schedule') {
    echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
    exit;
}

/**
 * @return array{codeS: array, codeA: array, codeB: array}
 */
function lineup_load_code_tiers(): array
{
    $defaults = [
        'codeS' => ['minRank' => 1, 'maxRank' => 20],
        'codeA' => ['minRank' => 21, 'maxRank' => 36],
        'codeB' => ['minRank' => 37, 'maxRank' => 46],
    ];
    $path = __DIR__ . '/rankings/rankings_config.json';
    if (!is_readable($path)) {
        return $defaults;
    }
    $loaded = json_decode(file_get_contents($path), true);
    if (!is_array($loaded)) {
        return $defaults;
    }
    foreach (['codeS', 'codeA', 'codeB'] as $tier) {
        if (isset($loaded[$tier]['minRank'], $loaded[$tier]['maxRank'])) {
            $defaults[$tier] = [
                'minRank' => (int) $loaded[$tier]['minRank'],
                'maxRank' => (int) $loaded[$tier]['maxRank'],
            ];
        }
    }
    return $defaults;
}

/**
 * @param array<string, array{rank: int, race: string}> $rankByNameLower
 */
function lineup_code_for_rank(?int $rank, array $tiers): string
{
    if ($rank === null || $rank < 1) {
        return 'OTHER';
    }
    foreach (['codeS' => 'S', 'codeA' => 'A', 'codeB' => 'B'] as $key => $letter) {
        $r = $tiers[$key] ?? null;
        if ($r && $rank >= $r['minRank'] && $rank <= $r['maxRank']) {
            return $letter;
        }
    }
    return 'OTHER';
}

/**
 * @param array<string, array{rank: int, race: string}> $rankByNameLower
 */
function lineup_best_code_for_players(array $names, array $rankByNameLower, array $tiers): string
{
    $order = ['S' => 0, 'A' => 1, 'B' => 2, 'OTHER' => 3];
    $best = 'OTHER';
    foreach ($names as $name) {
        $key = strtolower(trim((string) $name));
        $rank = $rankByNameLower[$key]['rank'] ?? null;
        $code = lineup_code_for_rank($rank, $tiers);
        if (($order[$code] ?? 99) < ($order[$best] ?? 99)) {
            $best = $code;
        }
    }
    return $best;
}

function lineup_race_for_name(string $name, array $rankByNameLower): string
{
    $key = strtolower(trim($name));
    $race = $rankByNameLower[$key]['race'] ?? 'R';
    return in_array($race, ['P', 'T', 'Z', 'R'], true) ? $race : 'R';
}

function lineup_build_notes(?int $weekNum, array $matchup, array $mapNameById, ?string $customDescription = null): string
{
    $parts = [];
    if ($customDescription !== null && $customDescription !== '') {
        $parts[] = $customDescription;
    } elseif ($weekNum !== null && $weekNum > 0) {
        $parts[] = 'week ' . $weekNum;
    }
    $day = $matchup['broadcastDay'] ?? '';
    if ($day === 'friday') {
        $parts[] = 'Fri';
    } elseif ($day === 'saturday') {
        $parts[] = 'Sat';
    }
    $m1 = trim((string) ($matchup['map1'] ?? ''));
    $m2 = trim((string) ($matchup['map2'] ?? ''));
    if ($m1 !== '') {
        $parts[] = 'M1: ' . ($mapNameById[$m1] ?? $m1);
    }
    if ($m2 !== '') {
        $parts[] = 'M2: ' . ($mapNameById[$m2] ?? $m2);
    }
    $notes = implode(' · ', $parts);
    return strlen($notes) > 255 ? substr($notes, 0, 252) . '...' : $notes;
}

function lineup_truncate(string $value, int $maxLen): string
{
    if (strlen($value) <= $maxLen) {
        return $value;
    }
    return substr($value, 0, max(0, $maxLen - 3)) . '...';
}

function lineup_resolve_player_id(string $name, array $playerIdByLower): int
{
    $key = strtolower(trim($name));
    if ($key === '' || !isset($playerIdByLower[$key])) {
        throw new Exception('Unknown player: ' . $name);
    }
    return (int) $playerIdByLower[$key];
}

function lineup_resolve_team_id(string $name, array $teamIdByLower): int
{
    $key = strtolower(trim($name));
    if ($key === '' || !isset($teamIdByLower[$key])) {
        throw new Exception('Unknown team: ' . $name);
    }
    return (int) $teamIdByLower[$key];
}

/**
 * @return list<array<string, mixed>>
 */
function lineup_find_duplicate_matches(
    PDO $dbConn,
    int $season,
    int $playerAId,
    int $playerBId,
    string $seasonExtraInfo,
    string $notes
): array {
    $stmt = $dbConn->prepare("
        SELECT fm.fsl_match_id, fm.season_extra_info, fm.notes, fm.t_code,
               fm.map_win, fm.map_loss,
               pw.Real_Name AS winner_name, pl.Real_Name AS loser_name
        FROM fsl_matches fm
        JOIN Players pw ON fm.winner_player_id = pw.Player_ID
        JOIN Players pl ON fm.loser_player_id = pl.Player_ID
        WHERE fm.season = ?
          AND fm.season_extra_info = ?
          AND fm.notes = ?
          AND (
              (fm.winner_player_id = ? AND fm.loser_player_id = ?)
              OR (fm.winner_player_id = ? AND fm.loser_player_id = ?)
          )
        ORDER BY fm.fsl_match_id DESC
    ");
    $stmt->execute([
        $season,
        $seasonExtraInfo,
        $notes,
        $playerAId,
        $playerBId,
        $playerBId,
        $playerAId,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @param list<array<string, mixed>> $entries
 */
function lineup_build_duplicate_confirm_message(array $entries): string
{
    $lines = [];
    foreach ($entries as $entry) {
        $lines[] = sprintf(
            '%s vs %s — match #%d (%s-%s, code %s): %s · %s',
            $entry['player_a'],
            $entry['player_b'],
            $entry['match_id'],
            $entry['map_win'],
            $entry['map_loss'],
            $entry['t_code'],
            $entry['season_extra_info'],
            $entry['notes']
        );
    }
    return "Possible duplicate(s) found:\n\n" . implode("\n\n", $lines) . "\n\nAdd anyway?";
}

try {
    $season = isset($_POST['season']) ? (int) $_POST['season'] : 0;
    $weekRaw = trim((string) ($_POST['week'] ?? ''));
    $isCustom = (strtolower($weekRaw) === 'custom');
    $weekNum = (!$isCustom && $weekRaw !== '' && ctype_digit($weekRaw)) ? (int) $weekRaw : 0;
    $description = trim((string) ($_POST['description'] ?? ''));
    $teamAName = trim((string) ($_POST['teamA'] ?? ''));
    $teamBName = trim((string) ($_POST['teamB'] ?? ''));
    $confirmDuplicates = !empty($_POST['confirm_duplicates']);
    $matchupsJson = $_POST['matchups'] ?? '[]';
    $matchups = json_decode($matchupsJson, true);

    if ($season < 1) {
        throw new Exception('Select a season.');
    }
    if ($isCustom) {
        if ($description === '') {
            throw new Exception('Description is required for Custom lineups.');
        }
    } elseif ($weekNum < 1) {
        throw new Exception('Select a schedule week or Custom.');
    }
    if ($teamAName === '' || $teamBName === '') {
        throw new Exception('Both teams are required.');
    }
    if (!is_array($matchups) || count($matchups) === 0) {
        throw new Exception('Add at least one matchup.');
    }

    require_once __DIR__ . '/includes/map_veto_store.php';

    $mapPool = [];
    $seasonsData = map_veto_load_seasons();
    $mapsData = map_veto_load_maps();
    $mapById = [];
    foreach ($mapsData as $m) {
        $id = isset($m['id']) ? (string) $m['id'] : '';
        if ($id !== '' && ($m['is_active'] ?? true)) {
            $mapById[$id] = $m;
        }
    }
    $enabledIds = [];
    foreach (['season_fsl_' . $season, 'season_fsl_10', 'season_fsl_default'] as $sid) {
        foreach ($seasonsData as $s) {
            if (($s['id'] ?? '') === $sid && !empty($s['enabled_map_ids']) && is_array($s['enabled_map_ids'])) {
                $enabledIds = $s['enabled_map_ids'];
                break 2;
            }
        }
    }
    foreach ($enabledIds as $id) {
        $id = (string) $id;
        if (!isset($mapById[$id])) {
            continue;
        }
        $mapPool[] = ['id' => $id, 'name' => (string) ($mapById[$id]['name'] ?? $id)];
    }
    $mapNameById = [];
    foreach ($mapPool as $m) {
        $mapNameById[$m['id']] = $m['name'];
    }

    $rankByNameLower = [];
    $rankingsFile = __DIR__ . '/rankings/rankings.json';
    if (is_readable($rankingsFile)) {
        $rankings = json_decode(file_get_contents($rankingsFile), true) ?? [];
        foreach ($rankings as $p) {
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $rankByNameLower[strtolower($name)] = [
                'rank' => (int) ($p['rank'] ?? 0),
                'race' => (string) ($p['race'] ?? 'R'),
            ];
        }
    }
    $codeTiers = lineup_load_code_tiers();

    $playerIdByLower = [];
    $playerStmt = $db->query("SELECT Player_ID, Real_Name FROM Players WHERE Status = 'active'");
    while ($row = $playerStmt->fetch(PDO::FETCH_ASSOC)) {
        $playerIdByLower[strtolower(trim($row['Real_Name']))] = (int) $row['Player_ID'];
    }

    $teamIdByLower = [];
    $teamStmt = $db->query("SELECT Team_ID, Team_Name FROM Teams");
    while ($row = $teamStmt->fetch(PDO::FETCH_ASSOC)) {
        $teamIdByLower[strtolower(trim($row['Team_Name']))] = (int) $row['Team_ID'];
    }

    $teamAId = lineup_resolve_team_id($teamAName, $teamIdByLower);
    $teamBId = lineup_resolve_team_id($teamBName, $teamIdByLower);

    $scheduleId = null;
    $scheduleRow = null;

    if (!$isCustom) {
        $schedStmt = $db->prepare("
            SELECT schedule_id, team1_id, team2_id, status
            FROM fsl_schedule
            WHERE season = ? AND week_number = ?
            LIMIT 1
        ");
        $schedStmt->execute([$season, $weekNum]);
        $scheduleRow = $schedStmt->fetch(PDO::FETCH_ASSOC);
        if (!$scheduleRow) {
            throw new Exception("No schedule entry for season {$season} week {$weekNum}.");
        }
        $scheduleId = (int) $scheduleRow['schedule_id'];
        $schedTeam1 = (int) ($scheduleRow['team1_id'] ?? 0);
        $schedTeam2 = (int) ($scheduleRow['team2_id'] ?? 0);
        if ($schedTeam1 > 0 && $schedTeam2 > 0) {
            $ok = ($teamAId === $schedTeam1 && $teamBId === $schedTeam2)
                || ($teamAId === $schedTeam2 && $teamBId === $schedTeam1);
            if (!$ok) {
                throw new Exception('Teams do not match the scheduled week pairing.');
            }
        }
    }

    $seasonExtraInfo = $isCustom
        ? lineup_truncate($description, 100)
        : 'Team League Group Stage';
    $bestOf = 2;
    $scoreA = 0;
    $scoreB = 0;
    $source = '';
    $vod = '';
    $notesContext = $isCustom ? $description : null;
    $planned1v1 = [];
    $twoVtwoLines = [];
    $twoVtwoSkipped = false;

    foreach ($matchups as $matchup) {
        if (!is_array($matchup)) {
            continue;
        }
        $type = ($matchup['type'] ?? '') === '2v2' ? '2v2' : '1vs1';

        if ($type === '2v2') {
            $a1 = trim((string) ($matchup['playerA1'] ?? ''));
            $a2 = trim((string) ($matchup['playerA2'] ?? ''));
            $b1 = trim((string) ($matchup['playerB1'] ?? ''));
            $b2 = trim((string) ($matchup['playerB2'] ?? ''));
            if (!($a1 || $a2) || !($b1 || $b2)) {
                continue;
            }
            $sideA = array_values(array_filter([$a1, $a2]));
            $sideB = array_values(array_filter([$b1, $b2]));
            $twoVtwoLines[] = implode('/', $sideA) . ' vs ' . implode('/', $sideB);
            if ($isCustom) {
                $twoVtwoSkipped = true;
            }
            continue;
        }

        $playerA = trim((string) ($matchup['playerA'] ?? ''));
        $playerB = trim((string) ($matchup['playerB'] ?? ''));
        if ($playerA === '' || $playerB === '') {
            continue;
        }

        $playerAId = lineup_resolve_player_id($playerA, $playerIdByLower);
        $playerBId = lineup_resolve_player_id($playerB, $playerIdByLower);
        if ($playerAId === $playerBId) {
            throw new Exception('Player A and Player B must be different.');
        }

        $tCode = lineup_best_code_for_players([$playerA, $playerB], $rankByNameLower, $codeTiers);
        if ($tCode === 'OTHER') {
            $tCode = 'B';
        }
        $notes = lineup_build_notes($isCustom ? null : $weekNum, $matchup, $mapNameById, $notesContext);

        $planned1v1[] = [
            'player_a' => $playerA,
            'player_b' => $playerB,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            't_code' => $tCode,
            'notes' => $notes,
        ];
    }

    if (empty($planned1v1) && empty($twoVtwoLines)) {
        throw new Exception('No complete matchups to save (each 1v1 needs both players).');
    }
    if ($isCustom && empty($planned1v1) && !empty($twoVtwoLines)) {
        throw new Exception('Custom send requires at least one 1v1 matchup (2v2-only is not stored as match rows).');
    }

    $duplicateEntries = [];
    $seenMatchIds = [];
    foreach ($planned1v1 as $planned) {
        $existingRows = lineup_find_duplicate_matches(
            $db,
            $season,
            $planned['player_a_id'],
            $planned['player_b_id'],
            $seasonExtraInfo,
            $planned['notes']
        );
        foreach ($existingRows as $row) {
            $matchId = (int) ($row['fsl_match_id'] ?? 0);
            if ($matchId < 1 || isset($seenMatchIds[$matchId])) {
                continue;
            }
            $seenMatchIds[$matchId] = true;
            $duplicateEntries[] = [
                'player_a' => $planned['player_a'],
                'player_b' => $planned['player_b'],
                'match_id' => $matchId,
                'map_win' => (int) ($row['map_win'] ?? 0),
                'map_loss' => (int) ($row['map_loss'] ?? 0),
                't_code' => (string) ($row['t_code'] ?? ''),
                'season_extra_info' => (string) ($row['season_extra_info'] ?? ''),
                'notes' => (string) ($row['notes'] ?? ''),
                'winner_name' => (string) ($row['winner_name'] ?? ''),
                'loser_name' => (string) ($row['loser_name'] ?? ''),
            ];
        }
    }

    if (!empty($duplicateEntries) && !$confirmDuplicates) {
        ob_end_clean();
        echo json_encode([
            'status' => 'confirm',
            'message' => lineup_build_duplicate_confirm_message($duplicateEntries),
            'duplicates' => $duplicateEntries,
        ]);
        exit;
    }

    $insertedIds = [];

    $db->beginTransaction();

    $insertSql = "INSERT INTO fsl_matches
        (fsl_match_id, season, season_extra_info, notes, t_code, winner_player_id, winner_race,
         best_of, map_win, map_loss, loser_player_id, loser_race, winner_team_id, loser_team_id, source, vod)
        VALUES
        (:fsl_match_id, :season, :season_extra_info, :notes, :t_code, :winner_player_id, :winner_race,
         :best_of, :map_win, :map_loss, :loser_player_id, :loser_race, :winner_team_id, :loser_team_id, :source, :vod)";
    $insertStmt = $db->prepare($insertSql);

    $linkStmt = $db->prepare("INSERT IGNORE INTO fsl_schedule_matches (schedule_id, fsl_match_id, match_type) VALUES (?, ?, ?)");

    foreach ($planned1v1 as $planned) {
        $nextId = (int) $db->query('SELECT COALESCE(MAX(fsl_match_id), 0) + 1 FROM fsl_matches')->fetchColumn();
        $insertStmt->execute([
            ':fsl_match_id' => $nextId,
            ':season' => $season,
            ':season_extra_info' => $seasonExtraInfo,
            ':notes' => $planned['notes'],
            ':t_code' => $planned['t_code'],
            ':winner_player_id' => $planned['player_a_id'],
            ':winner_race' => lineup_race_for_name($planned['player_a'], $rankByNameLower),
            ':best_of' => $bestOf,
            ':map_win' => $scoreA,
            ':map_loss' => $scoreB,
            ':loser_player_id' => $planned['player_b_id'],
            ':loser_race' => lineup_race_for_name($planned['player_b'], $rankByNameLower),
            ':winner_team_id' => $teamAId,
            ':loser_team_id' => $teamBId,
            ':source' => $source,
            ':vod' => $vod,
        ]);

        $matchId = $nextId;
        $insertedIds[] = $matchId;
        if (!$isCustom && $scheduleId) {
            $linkStmt->execute([$scheduleId, $matchId, $planned['t_code']]);
        }
    }

    if (!$isCustom && !empty($twoVtwoLines) && $scheduleId) {
        $twoVtwoText = implode('; ', $twoVtwoLines);
        $existing2v2Stmt = $db->prepare("SELECT team_2v2_results FROM fsl_schedule WHERE schedule_id = ?");
        $existing2v2Stmt->execute([$scheduleId]);
        $existing2v2 = trim((string) $existing2v2Stmt->fetchColumn());
        if ($existing2v2 !== '') {
            $twoVtwoText = $existing2v2 . '; ' . $twoVtwoText;
        }
        if (strlen($twoVtwoText) > 500) {
            $twoVtwoText = substr($twoVtwoText, 0, 497) . '...';
        }
        $db->prepare("UPDATE fsl_schedule SET team_2v2_results = ? WHERE schedule_id = ?")
            ->execute([$twoVtwoText, $scheduleId]);
    }

    if (empty($insertedIds) && empty($twoVtwoLines)) {
        throw new Exception('No complete matchups to save (each 1v1 needs both players).');
    }

    if (!$isCustom && $scheduleRow && ($scheduleRow['status'] ?? '') !== 'completed' && $scheduleId) {
        $db->prepare("UPDATE fsl_schedule SET status = 'scheduled' WHERE schedule_id = ?")
            ->execute([$scheduleId]);
    }

    $db->commit();

    ob_end_clean();
    echo json_encode([
        'status' => 'success',
        'message' => $isCustom ? 'Custom lineup saved to matches.' : 'Lineup saved to schedule.',
        'schedule_id' => $scheduleId,
        'match_ids' => $insertedIds,
        'linked_count' => $isCustom ? 0 : count($insertedIds),
        'two_vtwo' => !empty($twoVtwoLines) && !$isCustom,
        'two_vtwo_skipped' => $twoVtwoSkipped,
        'custom' => $isCustom,
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
