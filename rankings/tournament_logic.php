<?php
/**
 * FSL challenger tournament — participation + bottom-up group bracket.
 */

declare(strict_types=1);

const TOURNAMENT_STATE_FILE = __DIR__ . '/tournament_state.json';

/**
 * @return array<string,mixed>
 */
function tournament_empty_state(): array
{
    return [
        'title' => 'FSL Challenger Bracket',
        'updated_at' => null,
        'participating' => [],
        'results' => [],
        'winners' => [], // derived mirror of ended results (compat)
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
        'format' => 'fsl_challenger_tournament',
        'version' => 1,
        'exported_at' => gmdate('c'),
        'title' => (string) ($state['title'] ?? 'FSL Challenger Bracket'),
        'participant_count' => $inCount,
        'participating' => $participating,
        'results' => $state['results'],
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
    if ($format !== '' && $format !== 'fsl_challenger_tournament') {
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
    return [
        'codeS' => ['minRank' => 1, 'maxRank' => $third],
        'codeA' => ['minRank' => $third + 1, 'maxRank' => 2 * $third],
        'codeB' => ['minRank' => 2 * $third + 1, 'maxRank' => max($totalPlayers, 2 * $third + 1)],
    ];
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
            $challenger = $groupChamps[$i];
            $chCode = (string) $challenger['code'];
            // Seal Code champ when climbing into a higher tier (B → A → S)
            if ($chCode !== $currentCode) {
                $codeChamps[$currentCode] = [
                    'name' => $current['name'],
                    'ready' => !empty($current['ready']),
                ];
                $currentCode = $chCode;
            }
            $id = 'asc-m' . $ascIdx;
            $label = 'G' . $current['group'] . ' vs G' . $challenger['group'];
            $p1 = $current['ready'] ? $current['name'] : null;
            $p2 = $challenger['ready'] ? $challenger['name'] : null;
            if ($p1 === null || $p2 === null) {
                $match = [
                    'id' => $id,
                    'label' => $label,
                    'p1' => $p1,
                    'p2' => $p2,
                    'from1' => 'G' . $current['group'],
                    'from2' => 'G' . $challenger['group'],
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
                $match['from2'] = 'G' . $challenger['group'];
            }
            $match['code'] = $chCode;
            $ascension[] = $match;
            $current = [
                'group' => (int) $challenger['group'],
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

    return [
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
    ];
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
