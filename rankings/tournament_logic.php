<?php
/**
 * FSL Individual Championships — participation + bottom-up group bracket.
 */

declare(strict_types=1);

const TOURNAMENT_STATE_FILE = __DIR__ . '/tournament_state.json';

/**
 * @return array<string,mixed>
 */
function tournament_empty_state(): array
{
    return [
        'title' => 'FSL Individual Championships',
        'updated_at' => null,
        'participating' => [],
        'results' => [],
        'winners' => [], // derived mirror of ended results (compat)
        'broadcast_dates' => [], // "1" => "2026-08-22T19:00" (US Eastern wall time)
        'broadcast_assignments' => [], // match_id => broadcast# (manual pins)
    ];
}

/**
 * Normalize / migrate state (old winners[] → results[]).
 *
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function tournament_normalize_state(array $data): array
{
    if (!isset($data['participating']) || !is_array($data['participating'])) {
        $data['participating'] = [];
    }
    if (!isset($data['results']) || !is_array($data['results'])) {
        $data['results'] = [];
    }
    if (!isset($data['winners']) || !is_array($data['winners'])) {
        $data['winners'] = [];
    }
    $data['broadcast_dates'] = tournament_normalize_broadcast_dates($data['broadcast_dates'] ?? []);
    $data['broadcast_assignments'] = tournament_normalize_broadcast_assignments($data['broadcast_assignments'] ?? []);

    // One-time migrate: legacy winners map → results (only when result row missing)
    foreach ($data['winners'] as $id => $name) {
        $id = (string) $id;
        $name = trim((string) $name);
        if ($name === '' || isset($data['results'][$id])) {
            continue;
        }
        $data['results'][$id] = [
            'score1' => null,
            'score2' => null,
            'winner' => $name,
            'ended' => true,
            'forfeit' => null,
        ];
    }

    // winners is always a derived mirror of ended results (never source of truth)
    $data['winners'] = [];
    foreach ($data['results'] as $id => $res) {
        if (!is_array($res)) {
            continue;
        }
        if (!empty($res['ended']) && !empty($res['winner'])) {
            $data['winners'][(string) $id] = (string) $res['winner'];
        }
    }

    return $data;
}

/**
 * @return array<string,mixed>
 */
function tournament_load_state(): array
{
    if (!is_readable(TOURNAMENT_STATE_FILE)) {
        return tournament_empty_state();
    }
    $raw = file_get_contents(TOURNAMENT_STATE_FILE);
    if ($raw === false || $raw === '') {
        return tournament_empty_state();
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return tournament_empty_state();
    }
    return tournament_normalize_state($data);
}

/**
 * @param array<string,mixed> $state
 */
function tournament_save_state(array $state): bool
{
    $state = tournament_normalize_state($state);
    $state['updated_at'] = gmdate('c');
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    return file_put_contents(TOURNAMENT_STATE_FILE, $json, LOCK_EX) !== false;
}

/**
 * Portable JSON payload for signup / bracket backup.
 *
 * @param array<string,mixed> $state
 * @param list<array<string,mixed>> $rankings
 * @return array<string,mixed>
 */
function tournament_export_payload(array $state, array $rankings): array
{
    $state = tournament_normalize_state($state);
    $known = [];
    foreach ($rankings as $p) {
        $n = trim((string) ($p['name'] ?? ''));
        if ($n !== '') {
            $known[$n] = true;
        }
    }

    $participating = [];
    foreach ($state['participating'] as $name => $on) {
        $name = trim((string) $name);
        if ($name === '' || !isset($known[$name])) {
            continue;
        }
        $participating[$name] = (bool) $on;
    }
    // Explicit false for ranked players not yet toggled keeps import complete.
    foreach ($known as $name => $_) {
        if (!array_key_exists($name, $participating)) {
            $participating[$name] = false;
        }
    }
    ksort($participating, SORT_NATURAL | SORT_FLAG_CASE);

    $inCount = 0;
    foreach ($participating as $on) {
        if ($on) {
            $inCount++;
        }
    }

    return [
        'format' => 'fsl_individual_championships',
        'version' => 1,
        'exported_at' => gmdate('c'),
        'title' => (string) ($state['title'] ?? 'FSL Individual Championships'),
        'participant_count' => $inCount,
        'participating' => $participating,
        'results' => $state['results'],
        'broadcast_dates' => $state['broadcast_dates'] ?? [],
        'broadcast_assignments' => $state['broadcast_assignments'] ?? [],
    ];
}

/**
 * Apply an exported payload onto current state.
 * Unknown player names in participating are ignored.
 *
 * @param array<string,mixed> $state
 * @param array<string,mixed> $payload
 * @param list<array<string,mixed>> $rankings
 * @param bool $includeResults
 * @return array{state: array<string,mixed>, imported_in: int, imported_out: int, results_replaced: bool}
 */
function tournament_import_payload(array $state, array $payload, array $rankings, bool $includeResults = true): array
{
    $state = tournament_normalize_state($state);
    $format = (string) ($payload['format'] ?? '');
    if (
        $format !== ''
        && $format !== 'fsl_individual_championships'
        && $format !== 'fsl_challenger_tournament' // legacy export name
    ) {
        throw new InvalidArgumentException('Unrecognized export format');
    }

    $known = [];
    foreach ($rankings as $p) {
        $n = trim((string) ($p['name'] ?? ''));
        if ($n !== '') {
            $known[$n] = true;
        }
    }
    if ($known === []) {
        throw new InvalidArgumentException('No rankings players to map');
    }

    // Accept full export, or a bare {participating:{...}} / ["Name",...] list.
    $rawParticipating = $payload['participating'] ?? null;
    if ($rawParticipating === null && isset($payload['signups']) && is_array($payload['signups'])) {
        $rawParticipating = $payload['signups'];
    }
    if ($rawParticipating === null) {
        throw new InvalidArgumentException('JSON missing participating / signups');
    }
    if (!is_array($rawParticipating)) {
        throw new InvalidArgumentException('participating must be an object or list');
    }

    $nextParticipating = [];
    foreach ($known as $name => $_) {
        $nextParticipating[$name] = false;
    }

    $isList = array_keys($rawParticipating) === range(0, count($rawParticipating) - 1);
    if ($isList) {
        foreach ($rawParticipating as $name) {
            $name = trim((string) $name);
            if ($name !== '' && isset($known[$name])) {
                $nextParticipating[$name] = true;
            }
        }
    } else {
        foreach ($rawParticipating as $name => $on) {
            $name = trim((string) $name);
            if ($name === '' || !isset($known[$name])) {
                continue;
            }
            $nextParticipating[$name] = !empty($on);
        }
    }

    $importedIn = 0;
    $importedOut = 0;
    foreach ($nextParticipating as $on) {
        if ($on) {
            $importedIn++;
        } else {
            $importedOut++;
        }
    }
    $state['participating'] = $nextParticipating;

    $resultsReplaced = false;
    if ($includeResults && isset($payload['results']) && is_array($payload['results'])) {
        $state['results'] = $payload['results'];
        $resultsReplaced = true;
    }

    if (isset($payload['title']) && is_string($payload['title']) && trim($payload['title']) !== '') {
        $state['title'] = trim($payload['title']);
    }

    if (array_key_exists('broadcast_dates', $payload)) {
        $state['broadcast_dates'] = tournament_normalize_broadcast_dates($payload['broadcast_dates']);
    }
    if (array_key_exists('broadcast_assignments', $payload)) {
        $state['broadcast_assignments'] = tournament_normalize_broadcast_assignments($payload['broadcast_assignments']);
    }

    $state = tournament_normalize_state($state);

    return [
        'state' => $state,
        'imported_in' => $importedIn,
        'imported_out' => $importedOut,
        'results_replaced' => $resultsReplaced,
    ];
}

/**
 * @param array<string,mixed>|null $result
 * @return array{score1:?int,score2:?int,winner:?string,ended:bool,forfeit:?string}
 */
function tournament_result_normalize(?array $result): array
{
    if ($result === null) {
        return [
            'score1' => null,
            'score2' => null,
            'winner' => null,
            'ended' => false,
            'forfeit' => null,
            'p1' => null,
            'p2' => null,
        ];
    }
    $s1 = $result['score1'] ?? null;
    $s2 = $result['score2'] ?? null;
    return [
        'score1' => $s1 === null || $s1 === '' ? null : (int) $s1,
        'score2' => $s2 === null || $s2 === '' ? null : (int) $s2,
        'winner' => isset($result['winner']) && $result['winner'] !== '' ? (string) $result['winner'] : null,
        'ended' => !empty($result['ended']),
        'forfeit' => isset($result['forfeit']) && $result['forfeit'] !== '' && $result['forfeit'] !== null
            ? (string) $result['forfeit']
            : null,
        'p1' => isset($result['p1']) && $result['p1'] !== '' && $result['p1'] !== null
            ? (string) $result['p1']
            : null,
        'p2' => isset($result['p2']) && $result['p2'] !== '' && $result['p2'] !== null
            ? (string) $result['p2']
            : null,
    ];
}

function tournament_next_pow2(int $n): int
{
    $p = 1;
    while ($p < $n) {
        $p <<= 1;
    }
    return $p;
}

/**
 * @return list<int>
 */
function tournament_seed_order(int $size): array
{
    if ($size < 1) {
        return [];
    }
    $pos = [1];
    for ($round = 1; $round < $size; $round *= 2) {
        $next = [];
        foreach ($pos as $p) {
            $next[] = $p;
            $next[] = $round * 2 + 1 - $p;
        }
        $pos = $next;
    }
    return $pos;
}

/**
 * @param list<array<string,mixed>> $players
 * @param array<string,array<string,mixed>> $results
 * @return array<string,mixed>
 */
function tournament_build_group_bracket(int $group, array $players, array $results): array
{
    $n = count($players);
    foreach ($players as $i => &$p) {
        $p['seed'] = $i + 1;
    }
    unset($p);

    if ($n === 0) {
        return [
            'group' => $group,
            'players' => [],
            'rounds' => [],
            'champion' => null,
            'auto_champion' => false,
            'feeds_up' => false,
            'fed_in' => [],
        ];
    }
    if ($n === 1) {
        return [
            'group' => $group,
            'players' => $players,
            'rounds' => [],
            'champion' => $players[0]['name'],
            'auto_champion' => true,
            'feeds_up' => false,
            'fed_in' => [],
        ];
    }

    $size = tournament_next_pow2($n);
    $seedOrder = tournament_seed_order($size);
    $slots = array_fill(0, $size, null);
    for ($i = 0; $i < $n; $i++) {
        $seed = $i + 1;
        $idx = array_search($seed, $seedOrder, true);
        if ($idx === false) {
            continue;
        }
        $slots[(int) $idx] = $players[$i]['name'];
    }

    $rounds = [];
    $currentSlots = $slots;
    $roundNum = 1;
    while (count($currentSlots) > 1) {
        $matches = [];
        $nextSlots = [];
        $matchCount = (int) (count($currentSlots) / 2);
        $allowBye = ($roundNum === 1);
        for ($m = 0; $m < $matchCount; $m++) {
            $p1 = $currentSlots[$m * 2];
            $p2 = $currentSlots[$m * 2 + 1];
            $id = 'g' . $group . '-r' . $roundNum . '-m' . $m;
            $match = tournament_resolve_match(
                $id,
                $p1,
                $p2,
                $results,
                'G' . $group . ' R' . $roundNum,
                $allowBye
            );
            $matches[] = $match;
            $nextSlots[] = $match['winner'];
        }
        $rounds[] = $matches;
        $currentSlots = $nextSlots;
        $roundNum++;
    }

    $champion = null;
    if ($rounds !== []) {
        $lastRound = $rounds[count($rounds) - 1];
        $champion = $lastRound[0]['winner'] ?? null;
    }

    return [
        'group' => $group,
        'players' => $players,
        'rounds' => $rounds,
        'champion' => $champion,
        'auto_champion' => false,
        'feeds_up' => false,
        'fed_in' => [],
    ];
}

/**
 * @param array<string,array<string,mixed>> $results
 * @return array<string,mixed>
 */
function tournament_resolve_match(
    string $id,
    ?string $p1,
    ?string $p2,
    array $results,
    string $label,
    bool $allowStructuralBye = false
): array {
    $res = tournament_result_normalize(isset($results[$id]) && is_array($results[$id]) ? $results[$id] : null);
    $winner = null;
    $status = 'waiting';

    if ($p1 !== null && $p2 !== null) {
        // Reserved feeder slot — not playable until the lower group crowns.
        if (tournament_is_feed_placeholder($p1) || tournament_is_feed_placeholder($p2)) {
            return [
                'id' => $id,
                'label' => $label,
                'p1' => $p1,
                'p2' => $p2,
                'winner' => null,
                'status' => 'waiting',
                'is_bye' => false,
                'score1' => null,
                'score2' => null,
                'forfeit' => null,
                'ended' => false,
            ];
        }
        // Ignore ended results recorded against a different / incomplete pairing
        // (e.g. a final ended before both semifinal winners were known).
        $hasPairing = $res['p1'] !== null && $res['p2'] !== null;
        $legacyComplete = !$hasPairing && (
            $res['forfeit'] !== null
            || $res['score1'] !== null
            || $res['score2'] !== null
        );
        $pairingOk = $hasPairing
            ? (
                ($res['p1'] === $p1 && $res['p2'] === $p2)
                || ($res['p1'] === $p2 && $res['p2'] === $p1)
            )
            : $legacyComplete;
        if (
            $pairingOk
            && $res['ended']
            && $res['winner'] !== null
            && ($res['winner'] === $p1 || $res['winner'] === $p2)
        ) {
            $winner = $res['winner'];
            $status = 'done';
        } else {
            $hasScores = $res['score1'] !== null || $res['score2'] !== null;
            $status = $hasScores ? 'live' : 'ready';
            $winner = null;
            // Don't advance on non-ended / mismatched results
            $res['ended'] = false;
            $res['winner'] = null;
        }
    } elseif ($allowStructuralBye && $p1 !== null && $p2 === null) {
        $status = 'bye';
        $winner = $p1;
    } elseif ($allowStructuralBye && $p1 === null && $p2 !== null) {
        $status = 'bye';
        $winner = $p2;
    } elseif ($allowStructuralBye && $p1 === null && $p2 === null) {
        $status = 'empty';
        $winner = null;
    } else {
        $status = 'waiting';
        $winner = null;
    }

    return [
        'id' => $id,
        'label' => $label,
        'p1' => $p1,
        'p2' => $p2,
        'winner' => $winner,
        'status' => $status,
        'is_bye' => $status === 'bye',
        'score1' => $res['score1'],
        'score2' => $res['score2'],
        'forfeit' => $res['forfeit'],
        'ended' => $status === 'done',
    ];
}

/**
 * Blank score counts as 0 when the other side has a number (e.g. 1 vs null → 1-0).
 *
 * @return array{0:?int,1:?int}
 */
function tournament_normalize_score_pair(?int $s1, ?int $s2): array
{
    if ($s1 === null && $s2 === null) {
        return [null, null];
    }
    return [$s1 === null ? 0 : $s1, $s2 === null ? 0 : $s2];
}

/**
 * Infer winner from scores (null if tied / both blank).
 * "1 vs null" is treated as 1-0.
 */
function tournament_infer_winner_from_scores(?string $p1, ?string $p2, ?int $s1, ?int $s2): ?string
{
    if ($p1 === null || $p2 === null) {
        return null;
    }
    [$s1, $s2] = tournament_normalize_score_pair($s1, $s2);
    if ($s1 === null || $s2 === null) {
        return null;
    }
    if ($s1 > $s2) {
        return $p1;
    }
    if ($s2 > $s1) {
        return $p2;
    }
    return null;
}

/**
 * Force contiguous non-overlapping S → A → B covering 1..$totalPlayers.
 * Matches rankings.json group bands when tiers are group-aligned (4 players/group).
 *
 * @param array<string,array{minRank?:int,maxRank?:int}> $tiers
 * @return array<string,array{minRank:int,maxRank:int}>
 */
function tournament_normalize_code_tiers(array $tiers, int $totalPlayers): array
{
    $totalPlayers = max(1, $totalPlayers);
    $sMax = max(1, (int) ($tiers['codeS']['maxRank'] ?? 24));
    $aMax = max($sMax + 1, (int) ($tiers['codeA']['maxRank'] ?? ($sMax + 1)));
    // Clamp A if it overlaps a declared B start (corrupt configs used to do this).
    $bMinDeclared = (int) ($tiers['codeB']['minRank'] ?? ($aMax + 1));
    if ($bMinDeclared > $sMax + 1 && $aMax >= $bMinDeclared) {
        $aMax = $bMinDeclared - 1;
    }
    if ($aMax > $totalPlayers) {
        $aMax = $totalPlayers;
    }
    $bMin = min($totalPlayers, max($sMax + 1, $aMax + 1));
    return [
        'codeS' => ['minRank' => 1, 'maxRank' => min($sMax, $totalPlayers)],
        'codeA' => ['minRank' => min($sMax + 1, $totalPlayers), 'maxRank' => $aMax],
        'codeB' => ['minRank' => $bMin, 'maxRank' => $totalPlayers],
    ];
}

/**
 * Code tier for a rank (S, then A, else B) — same priority as rankings/index.php.
 *
 * @param array<string,array{minRank:int,maxRank:int}> $tiers
 */
function tournament_code_for_rank(int $rank, array $tiers): string
{
    $s = $tiers['codeS'] ?? ['minRank' => 1, 'maxRank' => 24];
    $a = $tiers['codeA'] ?? ['minRank' => 25, 'maxRank' => 36];
    if ($rank >= (int) $s['minRank'] && $rank <= (int) $s['maxRank']) {
        return 'S';
    }
    if ($rank >= (int) $a['minRank'] && $rank <= (int) $a['maxRank']) {
        return 'A';
    }
    return 'B';
}

/**
 * Code tier for a group number (based on the group's top seed rank band).
 *
 * @param array<string,array{minRank:int,maxRank:int}> $tiers
 */
function tournament_code_for_group(int $group, array $tiers): string
{
    $minRank = max(1, ($group - 1) * 4 + 1);
    return tournament_code_for_rank($minRank, $tiers);
}

/**
 * True when a lower group still needs to finish before a solo host can climb.
 * (Processed bottom-up, so $groups already contains entries for g+1…max.)
 *
 * @param array<int,array<string,mixed>> $groups
 * @param array<int,list<array<string,mixed>>> $byGroup
 */
function tournament_lower_feeder_pending(int $g, array $groups, int $maxGroup, array $byGroup): bool
{
    for ($lg = $g + 1; $lg <= $maxGroup; $lg++) {
        $locals = $byGroup[$lg] ?? [];
        $fg = $groups[$lg] ?? null;
        $hasLocals = $locals !== [];
        $hasPlayers = $fg !== null && !empty($fg['players']);
        if (!$hasLocals && !$hasPlayers) {
            continue;
        }
        if ($fg === null) {
            return true;
        }
        // Solo already climbing past this slot — not blocking.
        if (!empty($fg['feeds_up'])) {
            continue;
        }
        // Still waiting on its own lower feed, or bracket not finished.
        if (!empty($fg['waiting_for_feed'])) {
            return true;
        }
        $champ = $fg['champion'] ?? null;
        if ($champ === null || $champ === '') {
            return true;
        }
    }
    return false;
}

/**
 * Nearest lower group expected to send the next feeder into $g.
 *
 * @param array<int,array<string,mixed>> $groups
 * @param array<int,list<array<string,mixed>>> $byGroup
 */
function tournament_expected_feed_origin(int $g, array $groups, int $maxGroup, array $byGroup): int
{
    for ($lg = $g + 1; $lg <= $maxGroup; $lg++) {
        $locals = $byGroup[$lg] ?? [];
        $fg = $groups[$lg] ?? null;
        $hasLocals = $locals !== [];
        $hasPlayers = $fg !== null && !empty($fg['players']);
        if (!$hasLocals && !$hasPlayers) {
            continue;
        }
        if ($fg !== null && !empty($fg['feeds_up'])) {
            continue;
        }
        return $lg;
    }
    return min($maxGroup, $g + 1);
}

function tournament_feed_placeholder_name(int $originGroup): string
{
    return 'G' . $originGroup . ' winner';
}

function tournament_is_feed_placeholder(?string $name): bool
{
    if ($name === null || $name === '') {
        return false;
    }
    return (bool) preg_match('/^G\d+ winner$/', $name);
}

/**
 * @return array<string,mixed>
 */
function tournament_make_feed_placeholder(int $originGroup): array
{
    return [
        'name' => tournament_feed_placeholder_name($originGroup),
        'rank' => null,
        'race' => 'R',
        'team' => '',
        'group' => $originGroup,
        'origin_group' => $originGroup,
        'fed_in' => true,
        'is_placeholder' => true,
    ];
}

/**
 * @return array<string,array{minRank:int,maxRank:int}>
 */
function tournament_default_code_tiers(int $totalPlayers = 58): array
{
    $third = max(1, (int) ceil($totalPlayers / 3));
    return tournament_normalize_code_tiers([
        'codeS' => ['minRank' => 1, 'maxRank' => $third],
        'codeA' => ['minRank' => $third + 1, 'maxRank' => 2 * $third],
        'codeB' => ['minRank' => 2 * $third + 1, 'maxRank' => $totalPlayers],
    ], $totalPlayers);
}

/**
 * @param list<array<string,mixed>> $rankings
 * @param array<string,mixed> $state
 * @param array<string,array{minRank:int,maxRank:int}>|null $codeTiers
 * @return array<string,mixed>
 */
function tournament_build_bracket(array $rankings, array $state, ?array $codeTiers = null): array
{
    $state = tournament_normalize_state($state);
    if ($codeTiers === null) {
        $codeTiers = tournament_default_code_tiers(count($rankings));
    } else {
        $codeTiers = tournament_normalize_code_tiers($codeTiers, max(1, count($rankings)));
    }

    $participating = [];
    foreach ($state['participating'] as $name => $on) {
        if ($on) {
            $participating[trim((string) $name)] = true;
        }
    }

    $byGroup = [];
    $maxGroup = 1;
    foreach ($rankings as $p) {
        $name = trim((string) ($p['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $rank = (int) ($p['rank'] ?? 0);
        $group = isset($p['group']) ? (int) $p['group'] : (int) ceil(max(1, $rank) / 4);
        if ($group > $maxGroup) {
            $maxGroup = $group;
        }
        if (empty($participating[$name])) {
            continue;
        }
        if (!isset($byGroup[$group])) {
            $byGroup[$group] = [];
        }
        $byGroup[$group][] = [
            'name' => $name,
            'rank' => $rank,
            'race' => (string) ($p['race'] ?? 'R'),
            'team' => (string) ($p['team'] ?? ''),
            'group' => $group,
        ];
    }

    /** @var array<string,array<string,mixed>> $results */
    $results = [];
    foreach ($state['results'] as $mid => $res) {
        if (is_array($res)) {
            $results[(string) $mid] = tournament_result_normalize($res);
        }
    }

    $groups = [];
    $feeds = [];
    /** @var list<array<string,mixed>> $bubbling */
    $bubbling = [];
    /** @var list<int> $bubbleOrigins */
    $bubbleOrigins = [];

    for ($g = $maxGroup; $g >= 1; $g--) {
        $locals = $byGroup[$g] ?? [];
        usort($locals, static function ($a, $b) {
            return $a['rank'] <=> $b['rank'];
        });

        $fedIn = [];
        foreach ($bubbling as $bp) {
            $copy = $bp;
            $copy['fed_in'] = true;
            $copy['origin_group'] = (int) ($bp['origin_group'] ?? $bp['group'] ?? 0);
            $fedIn[] = $copy;
        }

        // Reserve a feeder slot so the group bracket is already N+1 shaped
        // (bottom local seed vs lower-group winner; other locals on byes).
        $awaitingFeed = false;
        if (
            $fedIn === []
            && $locals !== []
            && $g < $maxGroup
            && tournament_lower_feeder_pending($g, $groups, $maxGroup, $byGroup)
        ) {
            $origin = tournament_expected_feed_origin($g, $groups, $maxGroup, $byGroup);
            $fedIn[] = tournament_make_feed_placeholder($origin);
            $awaitingFeed = true;
            $bubbleOrigins = [$origin];
        }

        $pool = $locals;
        foreach ($fedIn as $fp) {
            $pool[] = $fp;
        }

        if ($pool === []) {
            $groups[$g] = tournament_build_group_bracket($g, [], $results);
            continue;
        }

        // Solo host with nobody left below: auto-feed into the next higher group.
        if (count($pool) === 1 && $g > 1) {
            $solo = $pool[0];
            $origin = (int) ($solo['origin_group'] ?? $solo['group'] ?? $g);
            $solo['origin_group'] = $origin;
            $solo['group'] = $origin;
            $bubbling = [$solo];
            if ($bubbleOrigins === []) {
                $bubbleOrigins = [$origin];
            } elseif (!in_array($origin, $bubbleOrigins, true)) {
                $bubbleOrigins[] = $origin;
            }
            if ($locals !== [] && !in_array($g, $bubbleOrigins, true)) {
                $bubbleOrigins[] = $g;
            }

            $groups[$g] = [
                'group' => $g,
                'code' => tournament_code_for_group($g, $codeTiers),
                'players' => $pool,
                'rounds' => [],
                'champion' => null,
                'auto_champion' => false,
                'feeds_up' => true,
                'waiting_for_feed' => false,
                'feed_to' => $g - 1,
                'fed_in' => $fedIn,
                'local_players' => $locals,
                'fed_champion_up' => false,
            ];
            continue;
        }

        $gb = tournament_build_group_bracket($g, $pool, $results);
        $gb['local_players'] = $locals;
        $gb['feeds_up'] = false;
        $gb['waiting_for_feed'] = $awaitingFeed;

        $seedByName = [];
        foreach ($gb['players'] as $bp) {
            $seedByName[(string) $bp['name']] = (int) ($bp['seed'] ?? 0);
        }
        $fedInAnnotated = [];
        foreach ($fedIn as $fp) {
            $fp['seed'] = $seedByName[(string) $fp['name']] ?? 0;
            $fedInAnnotated[] = $fp;
        }
        $gb['fed_in'] = $fedInAnnotated;

        if ($fedInAnnotated !== []) {
            $fromGroups = $bubbleOrigins;
            foreach ($fedInAnnotated as $fp) {
                $og = (int) ($fp['origin_group'] ?? 0);
                if ($og > 0 && !in_array($og, $fromGroups, true)) {
                    $fromGroups[] = $og;
                }
            }
            sort($fromGroups);
            $feeds[] = [
                'from_groups' => $fromGroups,
                'to_group' => $g,
                'players' => array_map(static function ($p) {
                    return [
                        'name' => (string) $p['name'],
                        'origin_group' => (int) ($p['origin_group'] ?? $p['group'] ?? 0),
                        'seed' => (int) ($p['seed'] ?? 0),
                        'is_placeholder' => !empty($p['is_placeholder']),
                    ];
                }, $fedInAnnotated),
            ];
        }

        $gb['code'] = tournament_code_for_group($g, $codeTiers);

        // Group winner climbs into the next higher group as lowest seed (5th spot, etc.).
        // Placeholder "winners" never climb.
        $champName = $gb['champion'] ?? null;
        if (
            $champName !== null
            && $champName !== ''
            && $g > 1
            && !tournament_is_feed_placeholder($champName)
        ) {
            $champPlayer = null;
            foreach ($pool as $p) {
                if ((string) ($p['name'] ?? '') === (string) $champName) {
                    $champPlayer = $p;
                    break;
                }
            }
            if ($champPlayer !== null && empty($champPlayer['is_placeholder'])) {
                $champPlayer['origin_group'] = $g;
                $champPlayer['group'] = $g;
                $bubbling = [$champPlayer];
                $bubbleOrigins = [$g];
                $gb['fed_champion_up'] = true;
            } else {
                $bubbling = [];
                $bubbleOrigins = [];
                $gb['fed_champion_up'] = false;
            }
        } else {
            $bubbling = [];
            $bubbleOrigins = [];
            $gb['fed_champion_up'] = false;
        }

        $groups[$g] = $gb;
        continue;
    }

    for ($g = 1; $g <= $maxGroup; $g++) {
        if (isset($groups[$g]) && !isset($groups[$g]['code'])) {
            $groups[$g]['code'] = tournament_code_for_group($g, $codeTiers);
        }
    }

    $groupChamps = [];
    for ($g = $maxGroup; $g >= 1; $g--) {
        $gb = $groups[$g] ?? null;
        if ($gb === null || !empty($gb['feeds_up'])) {
            continue;
        }
        // Champion already plays inside the next higher group — not a separate ladder match.
        if (!empty($gb['fed_champion_up'])) {
            continue;
        }
        if (!empty($gb['waiting_for_feed'])) {
            continue;
        }
        $hasBracket = !empty($gb['rounds']) || !empty($gb['auto_champion']);
        if (!$hasBracket) {
            continue;
        }
        $champ = $gb['champion'] ?? null;
        $groupChamps[] = [
            'group' => $g,
            'code' => (string) ($gb['code'] ?? tournament_code_for_group($g, $codeTiers)),
            'name' => ($champ !== null && $champ !== '') ? $champ : null,
            'ready' => $champ !== null && $champ !== '',
        ];
    }

    $codeChamps = [
        'B' => ['name' => null, 'ready' => false],
        'A' => ['name' => null, 'ready' => false],
        'S' => ['name' => null, 'ready' => false],
    ];

    $ascension = [];
    if (count($groupChamps) === 1) {
        $overallChampion = $groupChamps[0]['name'];
        $c = $groupChamps[0]['code'];
        $codeChamps[$c] = [
            'name' => $groupChamps[0]['name'],
            'ready' => $groupChamps[0]['ready'],
        ];
    } elseif (count($groupChamps) === 0) {
        $overallChampion = null;
    } else {
        $current = $groupChamps[0];
        $currentCode = (string) $current['code'];
        $ascIdx = 0;
        for ($i = 1; $i < count($groupChamps); $i++) {
            $nextChamp = $groupChamps[$i];
            $chCode = (string) $nextChamp['code'];
            // Seal Code champ when climbing into a higher tier (B → A → S)
            if ($chCode !== $currentCode) {
                $codeChamps[$currentCode] = [
                    'name' => $current['name'],
                    'ready' => !empty($current['ready']),
                ];
                $currentCode = $chCode;
            }
            $id = 'asc-m' . $ascIdx;
            $label = 'G' . $current['group'] . ' vs G' . $nextChamp['group'];
            $p1 = $current['ready'] ? $current['name'] : null;
            $p2 = $nextChamp['ready'] ? $nextChamp['name'] : null;
            if ($p1 === null || $p2 === null) {
                $match = [
                    'id' => $id,
                    'label' => $label,
                    'p1' => $p1,
                    'p2' => $p2,
                    'from1' => 'G' . $current['group'],
                    'from2' => 'G' . $nextChamp['group'],
                    'winner' => null,
                    'status' => 'waiting',
                    'is_bye' => false,
                    'score1' => null,
                    'score2' => null,
                    'forfeit' => null,
                    'ended' => false,
                ];
            } else {
                $match = tournament_resolve_match($id, $p1, $p2, $results, $label);
                $match['from1'] = 'G' . $current['group'];
                $match['from2'] = 'G' . $nextChamp['group'];
            }
            $match['code'] = $chCode;
            $ascension[] = $match;
            $current = [
                'group' => (int) $nextChamp['group'],
                'code' => $chCode,
                'name' => $match['winner'],
                'ready' => $match['winner'] !== null && $match['winner'] !== '',
            ];
            $ascIdx++;
        }
        $codeChamps[$currentCode] = [
            'name' => $current['name'],
            'ready' => !empty($current['ready']),
        ];
        $overallChampion = $current['name'];
    }

    $ready = 0;
    $waiting = 0;
    $done = 0;
    $live = 0;
    $tally = static function (array $m) use (&$ready, &$waiting, &$done, &$live): void {
        if ($m['status'] === 'ready') {
            $ready++;
        } elseif ($m['status'] === 'waiting') {
            $waiting++;
        } elseif ($m['status'] === 'done') {
            $done++;
        } elseif ($m['status'] === 'live') {
            $live++;
        }
    };
    foreach ($groups as $gb) {
        foreach ($gb['rounds'] as $round) {
            foreach ($round as $m) {
                $tally($m);
            }
        }
    }
    foreach ($ascension as $m) {
        $tally($m);
    }

    return tournament_stamp_code_championships([
        'groups' => $groups,
        'feeds' => $feeds,
        'ascension' => $ascension,
        'champion' => $overallChampion,
        'code_champs' => $codeChamps,
        'code_tiers' => $codeTiers,
        'max_group' => $maxGroup,
        'participant_count' => count($participating),
        'counts' => [
            'ready' => $ready,
            'waiting' => $waiting,
            'done' => $done,
            'live' => $live,
        ],
    ]);
}

/**
 * @param array<string,mixed> $bracket
 * @param array<string,array<string,mixed>> $results
 * @return array<string,array<string,mixed>>
 */
function tournament_prune_results(array $bracket, array $results): array
{
    $validIds = tournament_index_matches($bracket);
    $kept = [];
    foreach ($results as $id => $res) {
        $id = (string) $id;
        if (!isset($validIds[$id])) {
            continue;
        }
        $m = $validIds[$id];
        $st = (string) ($m['status'] ?? '');
        // Keep scores on ready/live/done; drop if match no longer playable
        if ($st !== 'ready' && $st !== 'live' && $st !== 'done') {
            continue;
        }
        $norm = tournament_result_normalize(is_array($res) ? $res : null);
        $p1 = $m['p1'] ?? null;
        $p2 = $m['p2'] ?? null;
        if ($norm['winner'] !== null && $norm['winner'] !== $p1 && $norm['winner'] !== $p2) {
            $norm['winner'] = null;
            $norm['ended'] = false;
            $norm['forfeit'] = null;
        }
        if ($norm['forfeit'] !== null && $norm['forfeit'] !== $p1 && $norm['forfeit'] !== $p2) {
            $norm['forfeit'] = null;
        }
        if ($norm['ended'] && ($p1 === null || $p2 === null)) {
            $norm['ended'] = false;
            $norm['winner'] = null;
        }
        // Refresh pairing snapshot to current match slots when keeping a result
        $norm['p1'] = $p1;
        $norm['p2'] = $p2;
        // Drop empty shells
        if (
            $norm['score1'] === null && $norm['score2'] === null
            && !$norm['ended'] && $norm['winner'] === null && $norm['forfeit'] === null
        ) {
            continue;
        }
        $kept[$id] = $norm;
    }
    return $kept;
}

/**
 * Backward-compatible alias used by older call sites.
 *
 * @param array<string,mixed> $bracket
 * @param array<string,string> $winners
 * @return array<string,string>
 */
function tournament_prune_winners(array $bracket, array $winners): array
{
    $asResults = [];
    foreach ($winners as $id => $name) {
        $asResults[(string) $id] = [
            'score1' => null,
            'score2' => null,
            'winner' => (string) $name,
            'ended' => true,
            'forfeit' => null,
        ];
    }
    $pruned = tournament_prune_results($bracket, $asResults);
    $out = [];
    foreach ($pruned as $id => $res) {
        if (!empty($res['ended']) && !empty($res['winner'])) {
            $out[(string) $id] = (string) $res['winner'];
        }
    }
    return $out;
}

/**
 * @param array<string,mixed> $bracket
 * @return array<string,array<string,mixed>>
 */
function tournament_index_matches(array $bracket): array
{
    $out = [];
    foreach ($bracket['groups'] ?? [] as $gb) {
        foreach ($gb['rounds'] ?? [] as $round) {
            foreach ($round as $m) {
                $out[(string) $m['id']] = $m;
            }
        }
    }
    foreach ($bracket['ascension'] ?? [] as $m) {
        $out[(string) $m['id']] = $m;
    }
    return $out;
}

/**
 * English ordinal (1 → 1st, 2 → 2nd, 4 → 4th).
 */
function tournament_ordinal(int $n): string
{
    $n = max(0, $n);
    $mod100 = $n % 100;
    if ($mod100 >= 11 && $mod100 <= 13) {
        return $n . 'th';
    }
    switch ($n % 10) {
        case 1:
            return $n . 'st';
        case 2:
            return $n . 'nd';
        case 3:
            return $n . 'rd';
        default:
            return $n . 'th';
    }
}

/**
 * Diagram / UI label, e.g. "4th broadcast".
 */
function tournament_broadcast_label(int $broadcastNum): string
{
    return tournament_ordinal(max(1, $broadcastNum)) . ' broadcast';
}

/**
 * Wall-clock timezone for Individual Championships broadcast scheduling / display.
 */
function tournament_broadcast_timezone(): string
{
    return 'America/New_York';
}

/**
 * Normalize manual broadcast pins: match_id → broadcast number (1-based).
 *
 * @param mixed $raw
 * @return array<string,int>
 */
function tournament_normalize_broadcast_assignments($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $id => $num) {
        $id = trim((string) $id);
        if ($id === '') {
            continue;
        }
        $n = (int) $num;
        if ($n < 1) {
            $n = 1;
        }
        if ($n > 64) {
            $n = 64;
        }
        $out[$id] = $n;
    }
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * Normalize broadcast date map: keys "1","2",… → "Y-m-d\TH:i" (Eastern wall time).
 * Empty / invalid entries are dropped (dates are optional).
 *
 * @param mixed $raw
 * @return array<string,string>
 */
function tournament_normalize_broadcast_dates($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $key => $val) {
        $n = (int) $key;
        if ($n < 1 || $n > 64) {
            continue;
        }
        $iso = '';
        if (is_array($val)) {
            $iso = trim((string) ($val['iso'] ?? $val['datetime'] ?? $val['at'] ?? ''));
            if ($iso === '' && isset($val['text'])) {
                $iso = trim((string) $val['text']);
            }
        } else {
            $iso = trim((string) $val);
        }
        if ($iso === '') {
            continue;
        }
        $norm = tournament_parse_broadcast_datetime($iso);
        if ($norm !== null) {
            $out[(string) $n] = $norm;
        }
    }
    ksort($out, SORT_NUMERIC);
    return $out;
}

/**
 * Accept "2026-08-22T19:00", "2026-08-22 19:00", "8/22/2026 7:00 PM", etc.
 * Returns canonical "Y-m-d\TH:i" or null.
 */
function tournament_parse_broadcast_datetime(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    // datetime-local / ISO without seconds
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{1,2}:\d{2})(?::\d{2})?/', $raw, $m)) {
        $hourMin = $m[2];
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $hourMin, $hm)) {
            $hourMin = sprintf('%02d:%02d', (int) $hm[1], (int) $hm[2]);
        }
        return $m[1] . 'T' . $hourMin;
    }
    $tz = new DateTimeZone(tournament_broadcast_timezone());
    $dt = date_create($raw, $tz);
    if ($dt === false) {
        return null;
    }
    return $dt->format('Y-m-d\TH:i');
}

/**
 * Display string for a stored Eastern wall time, e.g. "8/22/2026 7:00 PM ET".
 */
function tournament_format_broadcast_when(?string $iso): ?string
{
    $iso = $iso !== null ? trim($iso) : '';
    if ($iso === '') {
        return null;
    }
    $norm = tournament_parse_broadcast_datetime($iso);
    if ($norm === null) {
        return null;
    }
    $tz = new DateTimeZone(tournament_broadcast_timezone());
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $norm, $tz);
    if ($dt === false) {
        return null;
    }
    // n/j/Y g:i A — e.g. 8/22/2026 7:00 PM ET
    return $dt->format('n/j/Y g:i A') . ' ET';
}

/**
 * Display string for UI labels — formatted Eastern time or "TBD" when unset.
 */
function tournament_broadcast_when_or_tbd(?string $iso): string
{
    $formatted = tournament_format_broadcast_when($iso);
    return $formatted ?? 'TBD';
}

/**
 * "1st broadcast · 8/22/2026 7:00 PM ET" or "1st broadcast · TBD".
 */
function tournament_broadcast_label_full(int $broadcastNum, ?string $whenDisplay = null): string
{
    $base = tournament_broadcast_label($broadcastNum);
    $whenDisplay = $whenDisplay !== null ? trim($whenDisplay) : '';
    if ($whenDisplay === '') {
        $whenDisplay = 'TBD';
    }
    return $base . ' · ' . $whenDisplay;
}

/**
 * Top group number per code (lowest G# in that code tier) — mirrors diagram logic.
 *
 * @param array<string,mixed> $bracket
 * @return array<string,int>
 */
function tournament_code_exit_groups(array $bracket): array
{
    $groups = is_array($bracket['groups'] ?? null) ? $bracket['groups'] : [];
    $maxGroup = (int) ($bracket['max_group'] ?? 0);
    if ($maxGroup < 1 && $groups !== []) {
        $maxGroup = (int) max(array_map('intval', array_keys($groups)));
    }
    $codeExitGroup = [];
    for ($g = 1; $g <= $maxGroup; $g++) {
        $gb = is_array($groups[$g] ?? null) ? $groups[$g] : [];
        $code = strtoupper(trim((string) ($gb['code'] ?? '')));
        if ($code === '') {
            continue;
        }
        if (!isset($codeExitGroup[$code]) || $g < $codeExitGroup[$code]) {
            $codeExitGroup[$code] = $g;
        }
    }
    return $codeExitGroup;
}

/**
 * Contested match id that crowns each code tier (B / A / S).
 *
 * @param array<string,mixed> $bracket
 * @return array<string,string>
 */
function tournament_code_championship_match_ids(array $bracket): array
{
    $groups = is_array($bracket['groups'] ?? null) ? $bracket['groups'] : [];
    $maxGroup = (int) ($bracket['max_group'] ?? 0);
    if ($maxGroup < 1 && $groups !== []) {
        $maxGroup = (int) max(array_map('intval', array_keys($groups)));
    }
    $out = [];
    foreach (tournament_code_exit_groups($bracket) as $code => $startG) {
        $g = $startG;
        $guard = 0;
        while ($g >= 1 && $g <= max($maxGroup, $startG) && $guard < 64) {
            $guard++;
            $gb = is_array($groups[$g] ?? null) ? $groups[$g] : null;
            if ($gb === null) {
                break;
            }
            $exitId = tournament_group_exit_match_id($gb);
            if ($exitId !== null) {
                $out[$code] = $exitId;
                break;
            }
            if (!empty($gb['feeds_up'])) {
                $g++;
                continue;
            }
            break;
        }
    }
    return $out;
}

function tournament_code_championship_label(string $code): string
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return 'Championship';
    }
    return 'Code ' . $code . ' Championship';
}

/**
 * Stamp code_championship + code_championship_label on tier-crowning matches.
 *
 * @param array<string,mixed> $bracket
 * @return array<string,mixed>
 */
function tournament_stamp_code_championships(array $bracket): array
{
    $champIds = tournament_code_championship_match_ids($bracket);
    if ($champIds === []) {
        return $bracket;
    }
    $idToCode = array_flip($champIds);
    foreach ($bracket['groups'] ?? [] as $g => $gb) {
        if (!is_array($gb) || !isset($gb['rounds']) || !is_array($gb['rounds'])) {
            continue;
        }
        foreach ($gb['rounds'] as $ri => $round) {
            if (!is_array($round)) {
                continue;
            }
            foreach ($round as $mi => $m) {
                if (!is_array($m)) {
                    continue;
                }
                $id = trim((string) ($m['id'] ?? ''));
                if ($id === '' || !isset($idToCode[$id])) {
                    continue;
                }
                $code = $idToCode[$id];
                $bracket['groups'][$g]['rounds'][$ri][$mi]['code_championship'] = $code;
                $bracket['groups'][$g]['rounds'][$ri][$mi]['code_championship_label'] = tournament_code_championship_label($code);
            }
        }
    }
    return $bracket;
}

/**
 * Annotate with dates from tournament state.
 *
 * @param array<string,mixed> $bracket
 * @param array<string,mixed> $state
 * @param array<string,mixed>|null $opts
 * @return array{bracket:array<string,mixed>,schedule:array<string,mixed>}
 */
function tournament_annotate_broadcasts_from_state(array $bracket, array $state, ?array $opts = null): array
{
    $opts = is_array($opts) ? $opts : [];
    if (!isset($opts['broadcast_dates'])) {
        $opts['broadcast_dates'] = $state['broadcast_dates'] ?? [];
    }
    if (!isset($opts['broadcast_assignments'])) {
        $opts['broadcast_assignments'] = $state['broadcast_assignments'] ?? [];
    }
    return tournament_annotate_broadcasts($bracket, $opts);
}

/**
 * True when a match is a real contested game (not bye / empty shell).
 *
 * @param array<string,mixed> $match
 */
function tournament_match_is_contested(array $match): bool
{
    if (!empty($match['is_bye'])) {
        return false;
    }
    $st = (string) ($match['status'] ?? '');
    return $st !== 'bye' && $st !== 'empty';
}

/**
 * Last contested match that crowns a group (null if none).
 *
 * @param array<string,mixed> $gb
 */
function tournament_group_exit_match_id(array $gb): ?string
{
    $rounds = is_array($gb['rounds'] ?? null) ? $gb['rounds'] : [];
    for ($ri = count($rounds) - 1; $ri >= 0; $ri--) {
        $round = is_array($rounds[$ri]) ? $rounds[$ri] : [];
        for ($mi = count($round) - 1; $mi >= 0; $mi--) {
            $m = is_array($round[$mi]) ? $round[$mi] : null;
            if ($m === null || !tournament_match_is_contested($m)) {
                continue;
            }
            $id = trim((string) ($m['id'] ?? ''));
            return $id !== '' ? $id : null;
        }
    }
    return null;
}

/**
 * Resolve the contested match that produces a lower-group feeder.
 * Chases solo auto-climb hops (feeds_up) until a real exit match exists.
 *
 * @param array<string,mixed> $bracket
 */
function tournament_feed_origin_exit_match_id(array $bracket, int $originGroup): ?string
{
    $groups = is_array($bracket['groups'] ?? null) ? $bracket['groups'] : [];
    $maxGroup = (int) ($bracket['max_group'] ?? 0);
    $g = $originGroup;
    $guard = 0;
    while ($g >= 1 && $g <= max($maxGroup, $originGroup) && $guard < 64) {
        $guard++;
        $gb = is_array($groups[$g] ?? null) ? $groups[$g] : null;
        if ($gb === null) {
            return null;
        }
        $exit = tournament_group_exit_match_id($gb);
        if ($exit !== null) {
            return $exit;
        }
        // Solo climber with no bracket — keep looking lower for the real series.
        if (!empty($gb['feeds_up'])) {
            $g++;
            continue;
        }
        return null;
    }
    return null;
}

/**
 * Contested matches with scheduling metadata (no broadcast numbers yet).
 *
 * @param array<string,mixed> $bracket
 * @return array<string,array<string,mixed>>
 */
function tournament_broadcast_match_catalog(array $bracket): array
{
    $out = [];
    foreach ($bracket['groups'] ?? [] as $gKey => $gb) {
        if (!is_array($gb)) {
            continue;
        }
        $group = (int) ($gb['group'] ?? $gKey);
        $code = strtoupper(trim((string) ($gb['code'] ?? '')));
        if ($code === '') {
            $code = 'S';
        }
        foreach ($gb['rounds'] ?? [] as $ri => $round) {
            if (!is_array($round)) {
                continue;
            }
            foreach ($round as $mi => $m) {
                if (!is_array($m) || !tournament_match_is_contested($m)) {
                    continue;
                }
                $id = trim((string) ($m['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $entry = [
                    'id' => $id,
                    'group' => $group,
                    'code' => $code,
                    'round' => $ri + 1,
                    'match_index' => (int) $mi,
                    'label' => (string) ($m['label'] ?? ('G' . $group . ' R' . ($ri + 1))),
                    'p1' => $m['p1'] ?? null,
                    'p2' => $m['p2'] ?? null,
                    'status' => (string) ($m['status'] ?? 'waiting'),
                    'winner' => $m['winner'] ?? null,
                    'score1' => $m['score1'] ?? null,
                    'score2' => $m['score2'] ?? null,
                    'source' => 'group',
                ];
                $champ = strtoupper(trim((string) ($m['code_championship'] ?? '')));
                if ($champ !== '') {
                    $entry['code_championship'] = $champ;
                    $entry['code_championship_label'] = trim((string) ($m['code_championship_label'] ?? '')) !== ''
                        ? (string) $m['code_championship_label']
                        : tournament_code_championship_label($champ);
                }
                $out[$id] = $entry;
            }
        }
    }
    foreach ($bracket['ascension'] ?? [] as $ai => $m) {
        if (!is_array($m) || !tournament_match_is_contested($m)) {
            continue;
        }
        $id = trim((string) ($m['id'] ?? ('asc-m' . $ai)));
        if ($id === '') {
            continue;
        }
        $code = strtoupper(trim((string) ($m['code'] ?? 'S')));
        $entry = [
            'id' => $id,
            'group' => 0,
            'code' => $code !== '' ? $code : 'S',
            'round' => 1,
            'match_index' => (int) $ai,
            'label' => (string) ($m['label'] ?? 'Ascension'),
            'p1' => $m['p1'] ?? null,
            'p2' => $m['p2'] ?? null,
            'status' => (string) ($m['status'] ?? 'waiting'),
            'winner' => $m['winner'] ?? null,
            'score1' => $m['score1'] ?? null,
            'score2' => $m['score2'] ?? null,
            'source' => 'ascension',
        ];
        $champ = strtoupper(trim((string) ($m['code_championship'] ?? '')));
        if ($champ !== '') {
            $entry['code_championship'] = $champ;
            $entry['code_championship_label'] = trim((string) ($m['code_championship_label'] ?? '')) !== ''
                ? (string) $m['code_championship_label']
                : tournament_code_championship_label($champ);
        }
        $out[$id] = $entry;
    }
    return $out;
}

/**
 * Dependency map: match id → list of contested match ids that must finish first.
 *
 * @param array<string,mixed> $bracket
 * @return array<string,list<string>>
 */
function tournament_broadcast_dependencies(array $bracket): array
{
    $catalog = tournament_broadcast_match_catalog($bracket);
    $deps = [];
    foreach ($catalog as $id => $_) {
        $deps[$id] = [];
    }

    $add = static function (string $id, string $dep) use (&$deps, $catalog): void {
        if ($id === '' || $dep === '' || $id === $dep) {
            return;
        }
        if (!isset($catalog[$dep]) || !isset($deps[$id])) {
            return;
        }
        if (!in_array($dep, $deps[$id], true)) {
            $deps[$id][] = $dep;
        }
    };

    // Intra-group: round R+1 match M depends on round R matches 2M and 2M+1.
    foreach ($bracket['groups'] ?? [] as $gb) {
        if (!is_array($gb)) {
            continue;
        }
        $rounds = is_array($gb['rounds'] ?? null) ? $gb['rounds'] : [];
        for ($ri = 1; $ri < count($rounds); $ri++) {
            $round = is_array($rounds[$ri]) ? $rounds[$ri] : [];
            $prev = is_array($rounds[$ri - 1]) ? $rounds[$ri - 1] : [];
            foreach ($round as $mi => $m) {
                if (!is_array($m) || !tournament_match_is_contested($m)) {
                    continue;
                }
                $id = trim((string) ($m['id'] ?? ''));
                if ($id === '' || !isset($deps[$id])) {
                    continue;
                }
                foreach ([(int) $mi * 2, (int) $mi * 2 + 1] as $pi) {
                    if (!isset($prev[$pi]) || !is_array($prev[$pi])) {
                        continue;
                    }
                    if (!tournament_match_is_contested($prev[$pi])) {
                        continue;
                    }
                    $depId = trim((string) ($prev[$pi]['id'] ?? ''));
                    $add($id, $depId);
                }
            }
        }
    }

    // Feed placeholders ("G12 winner") → origin group exit match.
    foreach ($catalog as $id => $row) {
        foreach ([$row['p1'] ?? null, $row['p2'] ?? null] as $slot) {
            $name = trim((string) ($slot ?? ''));
            if ($name === '' || !tournament_is_feed_placeholder($name)) {
                continue;
            }
            if (!preg_match('/^G(\d+)\s+winner$/i', $name, $mm)) {
                continue;
            }
            $origin = (int) $mm[1];
            $exit = tournament_feed_origin_exit_match_id($bracket, $origin);
            if ($exit !== null) {
                $add($id, $exit);
            }
        }
    }

    // Ascension chain: each step depends on prior ascension winner being decided.
    $asc = is_array($bracket['ascension'] ?? null) ? $bracket['ascension'] : [];
    $prevAsc = null;
    foreach ($asc as $m) {
        if (!is_array($m) || !tournament_match_is_contested($m)) {
            continue;
        }
        $id = trim((string) ($m['id'] ?? ''));
        if ($id === '' || !isset($deps[$id])) {
            continue;
        }
        if ($prevAsc !== null) {
            $add($id, $prevAsc);
        }
        $prevAsc = $id;
    }

    return $deps;
}

/**
 * Code play order for anticipation: finish B, then A, then S (overall).
 *
 * @return array<string,int>
 */
function tournament_broadcast_code_priority(?array $codeOrder = null): array
{
    $order = $codeOrder ?? ['B', 'A', 'S'];
    $prio = [];
    foreach ($order as $i => $code) {
        $prio[strtoupper(trim((string) $code))] = (int) $i;
    }
    foreach (['B', 'A', 'S'] as $c) {
        if (!isset($prio[$c])) {
            $prio[$c] = count($prio);
        }
    }
    return $prio;
}

/**
 * Sort key for contested matches within / across broadcasts (B→A→S, group desc, round).
 *
 * @param array<string,mixed> $row
 * @param array<string,int> $codePrio
 * @return list<int|string>
 */
function tournament_broadcast_sort_key(array $row, array $codePrio): array
{
    $code = (string) ($row['code'] ?? '');
    $id = (string) ($row['id'] ?? '');
    return [
        $codePrio[$code] ?? 99,
        -((int) ($row['group'] ?? 0)),
        (int) ($row['round'] ?? 0),
        (int) ($row['match_index'] ?? 0),
        $id,
    ];
}

/**
 * Both players known (ready / live / done) — may slide among parallel side-board matches.
 * Waiting / TBD feed matches stay locked to the winner chain.
 *
 * @param array<string,mixed> $row catalog or schedule row
 */
function tournament_broadcast_match_is_stated(array $row): bool
{
    $st = strtolower(trim((string) ($row['status'] ?? '')));
    return $st === 'ready' || $st === 'live' || $st === 'done';
}

/**
 * Reverse dependency map: match id → list of contested ids that depend on it.
 *
 * @param array<string,list<string>> $deps
 * @return array<string,list<string>>
 */
function tournament_broadcast_dependents_map(array $deps): array
{
    $out = [];
    foreach ($deps as $id => $depList) {
        if (!isset($out[$id])) {
            $out[$id] = [];
        }
        foreach ($depList as $d) {
            $d = (string) $d;
            if ($d === '') {
                continue;
            }
            if (!isset($out[$d])) {
                $out[$d] = [];
            }
            if (!in_array($id, $out[$d], true)) {
                $out[$d][] = (string) $id;
            }
        }
    }
    return $out;
}

/**
 * After auto-pack + pin moves + compact, remap stored pins to final broadcast numbers.
 * Drops pins for unknown matches; keeps only ids still in the schedule.
 *
 * @param array<string,int> $assignments
 * @param array<string,array<string,mixed>> $byMatch
 * @return array<string,int>
 */
function tournament_broadcast_remap_assignments(array $assignments, array $byMatch): array
{
    $out = [];
    foreach ($assignments as $id => $_) {
        $id = (string) $id;
        if (!isset($byMatch[$id])) {
            continue;
        }
        $out[$id] = (int) $byMatch[$id]['broadcast'];
    }
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * Validate moving match $matchId to broadcast $target (absolute).
 * Same-show chaining OK. Blocks winner-chain breaks.
 *
 * @param array<string,array<string,mixed>> $byMatch current schedule by_match
 * @param array<string,list<string>> $deps
 * @param array<string,list<string>> $dependents
 * @return string|null error message or null if OK
 */
function tournament_broadcast_move_validate(
    string $matchId,
    int $target,
    array $byMatch,
    array $deps,
    array $dependents
): ?string {
    if ($matchId === '' || !isset($byMatch[$matchId])) {
        return 'Unknown contested match';
    }
    if ($target < 1) {
        return 'Invalid target broadcast';
    }
    $cur = (int) ($byMatch[$matchId]['broadcast'] ?? 0);
    if ($cur < 1) {
        return 'Match has no broadcast assignment';
    }
    if ($target === $cur) {
        return null;
    }

    foreach ($deps[$matchId] ?? [] as $depId) {
        $depId = (string) $depId;
        if ($depId === '' || !isset($byMatch[$depId])) {
            continue;
        }
        $depB = (int) $byMatch[$depId]['broadcast'];
        // Dep ends up later than moved match → break winner feed.
        if ($depB > $target) {
            $label = (string) ($byMatch[$depId]['label'] ?? $depId);
            return 'Cannot move before dependency ' . $label . ' (broadcast ' . $depB . ')';
        }
    }

    foreach ($dependents[$matchId] ?? [] as $childId) {
        $childId = (string) $childId;
        if ($childId === '' || !isset($byMatch[$childId])) {
            continue;
        }
        $childB = (int) $byMatch[$childId]['broadcast'];
        // Dependent left in an earlier show than moved match.
        if ($childB < $target) {
            $label = (string) ($byMatch[$childId]['label'] ?? $childId);
            return 'Cannot leave dependent ' . $label . ' in earlier broadcast ' . $childB;
        }
    }

    return null;
}

/**
 * Apply an adjacent broadcast move; returns updated assignments (compact-remapped) or error.
 *
 * @param array<string,mixed> $bracket
 * @param array<string,mixed> $state
 * @param array<string,mixed>|null $opts schedule opts override
 * @return array{ok:bool,error:?string,assignments:array<string,int>,schedule:?array<string,mixed>}
 */
function tournament_broadcast_move_match(
    array $bracket,
    array $state,
    string $matchId,
    string $direction,
    ?array $opts = null
): array {
    $matchId = trim($matchId);
    $direction = strtolower(trim($direction));
    if ($direction !== 'prev' && $direction !== 'next') {
        return ['ok' => false, 'error' => 'direction must be prev or next', 'assignments' => [], 'schedule' => null];
    }

    $opts = is_array($opts) ? $opts : [];
    if (!isset($opts['broadcast_dates'])) {
        $opts['broadcast_dates'] = $state['broadcast_dates'] ?? [];
    }
    $assignments = tournament_normalize_broadcast_assignments($state['broadcast_assignments'] ?? []);
    $opts['broadcast_assignments'] = $assignments;

    $schedule = tournament_broadcast_schedule($bracket, $opts);
    $byMatch = $schedule['by_match'] ?? [];
    if ($matchId === '' || !isset($byMatch[$matchId])) {
        return ['ok' => false, 'error' => 'Unknown contested match', 'assignments' => $assignments, 'schedule' => $schedule];
    }

    $cur = (int) $byMatch[$matchId]['broadcast'];
    $total = (int) ($schedule['total_broadcasts'] ?? 0);
    if ($direction === 'prev') {
        if ($cur <= 1) {
            return ['ok' => false, 'error' => 'Already in first broadcast', 'assignments' => $assignments, 'schedule' => $schedule];
        }
        $target = $cur - 1;
    } else {
        $target = $cur + 1;
        // Allow creating one show past the end only when current is last.
        if ($cur > $total) {
            return ['ok' => false, 'error' => 'Invalid current broadcast', 'assignments' => $assignments, 'schedule' => $schedule];
        }
    }

    $deps = tournament_broadcast_dependencies($bracket);
    $dependents = tournament_broadcast_dependents_map($deps);
    $err = tournament_broadcast_move_validate($matchId, $target, $byMatch, $deps, $dependents);
    if ($err !== null) {
        return ['ok' => false, 'error' => $err, 'assignments' => $assignments, 'schedule' => $schedule];
    }

    // Apply move on top of current layout: pin every match at its current
    // broadcast, then set the moved match to target (allows overflow past cap).
    $layoutPins = [];
    foreach ($byMatch as $id => $row) {
        $layoutPins[(string) $id] = (int) $row['broadcast'];
    }
    $layoutPins[$matchId] = $target;

    $opts['broadcast_assignments'] = $layoutPins;
    $newSchedule = tournament_broadcast_schedule($bracket, $opts);

    // Persist only intentional pins (prior overrides + this match), remapped after compact.
    // Do not store every match whose ordinal shifted when an empty show was dropped.
    $pinIds = array_fill_keys(array_map('strval', array_keys($assignments)), true);
    $pinIds[$matchId] = true;
    $remapped = [];
    foreach ($pinIds as $id => $_) {
        if (!isset($newSchedule['by_match'][$id])) {
            continue;
        }
        $remapped[$id] = (int) $newSchedule['by_match'][$id]['broadcast'];
    }
    ksort($remapped, SORT_NATURAL | SORT_FLAG_CASE);

    return [
        'ok' => true,
        'error' => null,
        'assignments' => $remapped,
        'schedule' => $newSchedule,
    ];
}

/**
 * Stable broadcast assignment for every contested match.
 * Same-show chaining allowed; Code S is deferred by default for anticipation.
 * Broadcast numbers are structural (stable as results come in) — status fields track progress.
 *
 * Opts:
 * - matches_per_broadcast (int, default 4, clamped 1–12)
 * - code_order (list, default ['B','A','S'])
 * - same_broadcast_chaining (bool, default true)
 * - phase_by_code (bool, default true) — finish each code before starting the next
 *   (keeps Code S off early shows for anticipation; last show of a code may be short)
 * - broadcast_dates (map) — optional "1" => "2026-08-22T19:00" (US Eastern wall time)
 * - broadcast_assignments (map) — optional match_id => broadcast# manual pins
 *
 * Pin algorithm: auto-pack into capacity, then relocate pinned matches onto their
 * target broadcast (overflow allowed). Empty shows are compacted and pins remapped
 * in the returned schedule via broadcast_assignments_effective.
 *
 * @param array<string,mixed> $bracket
 * @param array<string,mixed>|null $opts
 * @return array{
 *   matches_per_broadcast:int,
 *   code_order:list<string>,
 *   total_matches:int,
 *   total_broadcasts:int,
 *   current_broadcast:int|null,
 *   by_match: array<string,array<string,mixed>>,
 *   broadcasts: list<array<string,mixed>>,
 *   broadcast_assignments: array<string,int>,
 *   broadcast_assignments_effective: array<string,int>
 * }
 */
function tournament_broadcast_schedule(array $bracket, ?array $opts = null): array
{
    $opts = is_array($opts) ? $opts : [];
    $cap = (int) ($opts['matches_per_broadcast'] ?? 4);
    if ($cap < 1) {
        $cap = 1;
    }
    if ($cap > 12) {
        $cap = 12;
    }
    $codeOrder = [];
    if (isset($opts['code_order']) && is_array($opts['code_order'])) {
        foreach ($opts['code_order'] as $c) {
            $c = strtoupper(trim((string) $c));
            if ($c !== '' && !in_array($c, $codeOrder, true)) {
                $codeOrder[] = $c;
            }
        }
    }
    if ($codeOrder === []) {
        $codeOrder = ['B', 'A', 'S'];
    }
    $chain = array_key_exists('same_broadcast_chaining', $opts)
        ? (bool) $opts['same_broadcast_chaining']
        : true;
    $phaseByCode = array_key_exists('phase_by_code', $opts)
        ? (bool) $opts['phase_by_code']
        : true;
    $broadcastDates = tournament_normalize_broadcast_dates($opts['broadcast_dates'] ?? []);
    $pinInput = tournament_normalize_broadcast_assignments($opts['broadcast_assignments'] ?? []);

    $catalog = tournament_broadcast_match_catalog($bracket);
    $deps = tournament_broadcast_dependencies($bracket);
    $codePrio = tournament_broadcast_code_priority($codeOrder);

    $depsSatisfied = static function (string $id, array $done) use ($deps): bool {
        foreach ($deps[$id] ?? [] as $d) {
            if (!isset($done[$d])) {
                return false;
            }
        }
        return true;
    };

    $pickBest = static function (array $candidates) use ($catalog, $codePrio): ?string {
        $best = null;
        $bestKey = null;
        foreach ($candidates as $id) {
            $row = $catalog[$id];
            $key = tournament_broadcast_sort_key($row, $codePrio);
            if ($best === null || $key < $bestKey) {
                $best = $id;
                $bestKey = $key;
            }
        }
        return $best;
    };

    $activeCode = static function (array $remaining) use ($catalog, $codePrio): ?string {
        $bestCode = null;
        $bestP = null;
        foreach ($remaining as $id) {
            $code = (string) ($catalog[$id]['code'] ?? '');
            $p = $codePrio[$code] ?? 99;
            if ($bestP === null || $p < $bestP) {
                $bestP = $p;
                $bestCode = $code;
            }
        }
        return $bestCode;
    };

    $remaining = array_keys($catalog);
    $scheduled = [];
    $shows = [];
    $guard = 0;
    $maxGuard = max(1, count($remaining)) * 4;
    while ($remaining !== [] && $guard < $maxGuard) {
        $guard++;
        $show = [];
        $done = $scheduled;
        $phaseCode = $phaseByCode ? $activeCode($remaining) : null;
        while (count($show) < $cap) {
            $candidates = [];
            foreach ($remaining as $id) {
                if (isset($done[$id])) {
                    continue;
                }
                if ($phaseCode !== null && (string) ($catalog[$id]['code'] ?? '') !== $phaseCode) {
                    continue;
                }
                $checkDone = $chain ? $done : $scheduled;
                if ($depsSatisfied($id, $checkDone)) {
                    $candidates[] = $id;
                }
            }
            $pick = $pickBest($candidates);
            if ($pick === null) {
                break;
            }
            $show[] = $pick;
            $done[$pick] = true;
        }
        if ($show === []) {
            // Cycle / missing dep edge — force best remaining so we never stall.
            $pick = $pickBest($remaining);
            if ($pick === null) {
                break;
            }
            $show[] = $pick;
        }
        $shows[] = $show;
        foreach ($show as $id) {
            $scheduled[$id] = true;
            $remaining = array_values(array_filter(
                $remaining,
                static function ($rid) use ($id) {
                    return $rid !== $id;
                }
            ));
        }
    }

    // Apply manual pins: relocate match onto target broadcast (overflow OK).
    $pins = [];
    foreach ($pinInput as $id => $bNum) {
        if (!isset($catalog[$id])) {
            continue;
        }
        $pins[$id] = max(1, (int) $bNum);
    }
    if ($pins !== []) {
        $loc = [];
        foreach ($shows as $bi => $show) {
            foreach ($show as $si => $id) {
                $loc[$id] = [$bi, $si];
            }
        }
        foreach ($pins as $id => $bNum) {
            $targetBi = $bNum - 1;
            if (isset($loc[$id])) {
                [$fromBi, $fromSi] = $loc[$id];
                if ($fromBi === $targetBi) {
                    continue;
                }
                array_splice($shows[$fromBi], $fromSi, 1);
                // Fix loc indices in the shortened source show.
                $loc = [];
                foreach ($shows as $bi => $show) {
                    foreach ($show as $si => $mid) {
                        $loc[$mid] = [$bi, $si];
                    }
                }
            }
            while (count($shows) <= $targetBi) {
                $shows[] = [];
            }
            $shows[$targetBi][] = $id;
            $loc[$id] = [$targetBi, count($shows[$targetBi]) - 1];
        }

        // Repair winner-chain: no match may sit in an earlier broadcast than any dep.
        $rebuildLoc = static function (array $shows): array {
            $loc = [];
            foreach ($shows as $bi => $show) {
                foreach ($show as $si => $mid) {
                    $loc[$mid] = [$bi, $si];
                }
            }
            return $loc;
        };
        $loc = $rebuildLoc($shows);
        $orderIds = array_keys($catalog);
        usort($orderIds, static function (string $a, string $b) use ($catalog, $codePrio): int {
            return tournament_broadcast_sort_key($catalog[$a] ?? ['id' => $a], $codePrio)
                <=> tournament_broadcast_sort_key($catalog[$b] ?? ['id' => $b], $codePrio);
        });
        foreach ($orderIds as $id) {
            if (!isset($loc[$id])) {
                continue;
            }
            $minBi = 0;
            foreach ($deps[$id] ?? [] as $depId) {
                if (!isset($loc[$depId])) {
                    continue;
                }
                $minBi = max($minBi, (int) $loc[$depId][0]);
            }
            [$curBi, $curSi] = $loc[$id];
            if ($curBi >= $minBi) {
                continue;
            }
            array_splice($shows[$curBi], $curSi, 1);
            $loc = $rebuildLoc($shows);
            while (count($shows) <= $minBi) {
                $shows[] = [];
            }
            $shows[$minBi][] = $id;
            $loc = $rebuildLoc($shows);
        }

        // Sort each show by priority (deps may share a show via chaining).
        foreach ($shows as $bi => $show) {
            usort($show, static function (string $a, string $b) use ($catalog, $codePrio): int {
                $ka = tournament_broadcast_sort_key($catalog[$a] ?? ['id' => $a], $codePrio);
                $kb = tournament_broadcast_sort_key($catalog[$b] ?? ['id' => $b], $codePrio);
                return $ka <=> $kb;
            });
            $shows[$bi] = $show;
        }

        // Compact empty broadcasts; dates stay keyed by absolute number (user may re-check).
        $shows = array_values(array_filter($shows, static function (array $show): bool {
            return $show !== [];
        }));
    }

    $broadcasts = [];
    $byMatch = [];
    $currentBroadcast = null;
    $effectiveAssignments = [];
    foreach ($shows as $bi => $show) {
        $bNum = $bi + 1;
        $bLabel = tournament_broadcast_label($bNum);
        $whenIso = $broadcastDates[(string) $bNum] ?? null;
        $whenDisplay = tournament_broadcast_when_or_tbd($whenIso);
        $bLabelFull = tournament_broadcast_label_full($bNum, $whenDisplay);
        $rows = [];
        $allDone = true;
        $anyReady = false;
        foreach ($show as $si => $id) {
            $row = $catalog[$id];
            $st = (string) $row['status'];
            if ($st !== 'done') {
                $allDone = false;
            }
            if ($st === 'ready' || $st === 'live') {
                $anyReady = true;
            }
            $pinned = isset($pins[$id]);
            $entry = array_merge($row, [
                'broadcast' => $bNum,
                'broadcast_label' => $bLabel,
                'broadcast_label_full' => $bLabelFull,
                'broadcast_when' => $whenDisplay,
                'broadcast_when_iso' => $whenIso,
                'slot' => $si + 1,
                'depends_on' => $deps[$id] ?? [],
                'broadcast_pinned' => $pinned,
                'stated' => tournament_broadcast_match_is_stated($row),
            ]);
            $byMatch[$id] = $entry;
            $effectiveAssignments[$id] = $bNum;
            $rows[] = $entry;
        }
        $codes = [];
        foreach ($rows as $r) {
            $c = (string) $r['code'];
            if ($c !== '' && !in_array($c, $codes, true)) {
                $codes[] = $c;
            }
        }
        $broadcasts[] = [
            'broadcast' => $bNum,
            'broadcast_label' => $bLabel,
            'broadcast_label_full' => $bLabelFull,
            'broadcast_when' => $whenDisplay,
            'broadcast_when_iso' => $whenIso,
            'matches' => $rows,
            'match_count' => count($rows),
            'all_done' => $allDone,
            'has_ready' => $anyReady,
            'codes' => $codes,
        ];
        if ($currentBroadcast === null && !$allDone) {
            $currentBroadcast = $bNum;
        }
    }

    return [
        'matches_per_broadcast' => $cap,
        'code_order' => $codeOrder,
        'same_broadcast_chaining' => $chain,
        'phase_by_code' => $phaseByCode,
        'broadcast_dates' => $broadcastDates,
        'broadcast_assignments' => $pins,
        'broadcast_assignments_effective' => $effectiveAssignments,
        'total_matches' => count($catalog),
        'total_broadcasts' => count($broadcasts),
        'current_broadcast' => $currentBroadcast,
        'by_match' => $byMatch,
        'broadcasts' => $broadcasts,
    ];
}

/**
 * Stamp broadcast / broadcast_label / slot onto each contested match in the bracket.
 *
 * @param array<string,mixed> $bracket
 * @param array<string,mixed>|null $opts
 * @return array{bracket:array<string,mixed>,schedule:array<string,mixed>}
 */
function tournament_annotate_broadcasts(array $bracket, ?array $opts = null): array
{
    $schedule = tournament_broadcast_schedule($bracket, $opts);
    $byMatch = $schedule['by_match'];

    foreach ($bracket['groups'] ?? [] as $g => $gb) {
        if (!is_array($gb) || !isset($gb['rounds']) || !is_array($gb['rounds'])) {
            continue;
        }
        foreach ($gb['rounds'] as $ri => $round) {
            if (!is_array($round)) {
                continue;
            }
            foreach ($round as $mi => $m) {
                if (!is_array($m)) {
                    continue;
                }
                $id = (string) ($m['id'] ?? '');
                if ($id === '' || !isset($byMatch[$id])) {
                    continue;
                }
                $bracket['groups'][$g]['rounds'][$ri][$mi]['broadcast'] = $byMatch[$id]['broadcast'];
                $bracket['groups'][$g]['rounds'][$ri][$mi]['broadcast_label'] = $byMatch[$id]['broadcast_label'];
                $bracket['groups'][$g]['rounds'][$ri][$mi]['broadcast_label_full'] = $byMatch[$id]['broadcast_label_full'];
                $bracket['groups'][$g]['rounds'][$ri][$mi]['broadcast_when'] = $byMatch[$id]['broadcast_when'];
                $bracket['groups'][$g]['rounds'][$ri][$mi]['broadcast_when_iso'] = $byMatch[$id]['broadcast_when_iso'];
                $bracket['groups'][$g]['rounds'][$ri][$mi]['slot'] = $byMatch[$id]['slot'];
            }
        }
    }
    foreach ($bracket['ascension'] ?? [] as $ai => $m) {
        if (!is_array($m)) {
            continue;
        }
        $id = (string) ($m['id'] ?? '');
        if ($id === '' || !isset($byMatch[$id])) {
            continue;
        }
        $bracket['ascension'][$ai]['broadcast'] = $byMatch[$id]['broadcast'];
        $bracket['ascension'][$ai]['broadcast_label'] = $byMatch[$id]['broadcast_label'];
        $bracket['ascension'][$ai]['broadcast_label_full'] = $byMatch[$id]['broadcast_label_full'];
        $bracket['ascension'][$ai]['broadcast_when'] = $byMatch[$id]['broadcast_when'];
        $bracket['ascension'][$ai]['broadcast_when_iso'] = $byMatch[$id]['broadcast_when_iso'];
        $bracket['ascension'][$ai]['slot'] = $byMatch[$id]['slot'];
    }

    $bracket['broadcast_schedule'] = [
        'matches_per_broadcast' => $schedule['matches_per_broadcast'],
        'total_broadcasts' => $schedule['total_broadcasts'],
        'current_broadcast' => $schedule['current_broadcast'],
        'code_order' => $schedule['code_order'],
        'phase_by_code' => !empty($schedule['phase_by_code']),
        'broadcast_dates' => $schedule['broadcast_dates'] ?? [],
        'broadcast_assignments' => $schedule['broadcast_assignments'] ?? [],
        'has_manual_assignments' => ($schedule['broadcast_assignments'] ?? []) !== [],
        'broadcasts' => array_map(static function (array $b): array {
            return [
                'broadcast' => (int) $b['broadcast'],
                'broadcast_label' => (string) $b['broadcast_label'],
                'broadcast_label_full' => (string) ($b['broadcast_label_full'] ?? $b['broadcast_label']),
                'broadcast_when' => $b['broadcast_when'] ?? null,
                'broadcast_when_iso' => $b['broadcast_when_iso'] ?? null,
                'match_count' => (int) $b['match_count'],
                'codes' => array_values($b['codes'] ?? []),
                'all_done' => !empty($b['all_done']),
                'has_ready' => !empty($b['has_ready']),
            ];
        }, $schedule['broadcasts'] ?? []),
    ];

    return [
        'bracket' => $bracket,
        'schedule' => $schedule,
    ];
}
