<?php
/**
 * Map veto state machine: veto phase, order phase, timers, autofill.
 */

declare(strict_types=1);

require_once __DIR__ . '/map_veto_store.php';

/**
 * @param array<int, array<string, mixed>> $allMaps
 * @param array<string, mixed> $season
 * @return list<array<string, mixed>>
 */
function map_veto_build_effective_pool(array $allMaps, array $season, int $bestOf): array
{
    $enabledIds = $season['enabled_map_ids'] ?? [];
    if (!is_array($enabledIds)) {
        $enabledIds = [];
    }
    $byId = [];
    foreach ($allMaps as $m) {
        $id = (string) ($m['id'] ?? '');
        if ($id !== '') {
            $byId[$id] = $m;
        }
    }
    /** @var array<string, array<string, mixed>> $pool */
    $pool = [];
    foreach ($enabledIds as $mid) {
        $mid = (string) $mid;
        if (isset($byId[$mid]) && !empty($byId[$mid]['is_active'])) {
            $pool[$mid] = $byId[$mid];
        }
    }
    $mapsToPlay = $bestOf;
    if ($mapsToPlay > 7) {
        foreach ($allMaps as $m) {
            $id = (string) ($m['id'] ?? '');
            if ($id === '' || empty($m['is_active'])) {
                continue;
            }
            if (empty($m['is_overflow_eligible'])) {
                continue;
            }
            if (!isset($pool[$id])) {
                $pool[$id] = $m;
            }
        }
    }
    return array_values($pool);
}

/**
 * @param array<string, mixed> $mapRow
 * @return array<string, mixed>
 */
function map_veto_session_map_snapshot(array $mapRow): array
{
    return [
        'map_id' => (string) ($mapRow['id'] ?? ''),
        'name' => (string) ($mapRow['name'] ?? ''),
        'image_url' => (string) ($mapRow['image_url'] ?? ''),
        'state' => 'available',
        'vetoed_by' => null,
        'game_number' => null,
    ];
}

function map_veto_opposite_side(string $side): string
{
    return $side === 'a' ? 'b' : 'a';
}

/**
 * @param array<string, mixed> $session
 */
function map_veto_count_available(array $session): int
{
    $n = 0;
    foreach ($session['session_maps'] ?? [] as $row) {
        if (($row['state'] ?? '') === 'available') {
            $n++;
        }
    }
    return $n;
}

/**
 * @param array<string, mixed> $session
 */
function map_veto_count_assigned(array $session): int
{
    $n = 0;
    foreach ($session['session_maps'] ?? [] as $row) {
        if (($row['state'] ?? '') === 'assigned') {
            $n++;
        }
    }
    return $n;
}

/**
 * @param array<string, mixed> $session
 */
function map_veto_begin_turn(array &$session): void
{
    $timer = max(15, min(600, (int) ($session['timer_seconds'] ?? 60)));
    $session['timer_seconds'] = $timer;
    $session['turn_started_at'] = gmdate('c');
    $session['turn_expires_at'] = gmdate('c', time() + $timer);
    $session['revision'] = (int) ($session['revision'] ?? 0) + 1;
}

/**
 * @param array<string, mixed> $session
 * @param array<string, mixed> $action
 */
function map_veto_append_action(array &$session, array $action): void
{
    $actions = $session['actions'] ?? [];
    if (!is_array($actions)) {
        $actions = [];
    }
    $step = count($actions) + 1;
    $action['step'] = $step;
    $action['at'] = gmdate('c');
    $actions[] = $action;
    $session['actions'] = $actions;
    $session['revision'] = (int) ($session['revision'] ?? 0) + 1;
}

/**
 * @param array<string, mixed> $session
 */
function map_veto_transition_to_order(array &$session): void
{
    $session['status'] = 'live_order';
    $session['current_phase'] = 'order';
    $session['current_turn_side'] = $session['lower_side'];
    $session['game_number'] = 1;
    map_veto_begin_turn($session);
}

/**
 * @param array<string, mixed> $session
 */
function map_veto_complete(array &$session): void
{
    $session['status'] = 'completed';
    $session['current_phase'] = 'completed';
    $session['current_turn_side'] = null;
    $session['turn_started_at'] = null;
    $session['turn_expires_at'] = null;
    $session['completed_at'] = gmdate('c');
    $session['revision'] = (int) ($session['revision'] ?? 0) + 1;
}

/**
 * Run after loading session: single-map order autofill + timeouts.
 *
 * @param array<string, mixed> $session
 */
function map_veto_process_tick(array &$session): void
{
    $status = (string) ($session['status'] ?? '');
    if ($status === 'cancelled' || $status === 'completed' || $status === 'pending') {
        return;
    }
    // Order autofill loop
    while (($session['status'] ?? '') === 'live_order') {
        $avail = [];
        foreach ($session['session_maps'] ?? [] as $idx => $row) {
            if (($row['state'] ?? '') === 'available') {
                $avail[] = ['idx' => $idx, 'map_id' => (string) ($row['map_id'] ?? '')];
            }
        }
        $needSlot = (int) ($session['game_number'] ?? 1);
        $mapsToPlay = (int) ($session['maps_to_play'] ?? 1);
        if ($needSlot > $mapsToPlay) {
            map_veto_complete($session);
            break;
        }
        if (count($avail) === 1) {
            $only = $avail[0];
            $idx = $only['idx'];
            $session['session_maps'][$idx]['state'] = 'assigned';
            $session['session_maps'][$idx]['game_number'] = $needSlot;
            map_veto_append_action($session, [
                'phase' => 'order',
                'acting_side' => 'system',
                'action_type' => 'system_finalize',
                'map_id' => $only['map_id'],
                'game_number' => $needSlot,
                'was_timeout' => false,
            ]);
            $session['game_number'] = $needSlot + 1;
            if ($session['game_number'] > $mapsToPlay) {
                map_veto_complete($session);
                break;
            }
            map_veto_begin_turn($session);
            continue;
        }
        break;
    }

    map_veto_resolve_deadlines($session);
}

/**
 * @param array<string, mixed> $session
 */
function map_veto_resolve_deadlines(array &$session): void
{
    $status = (string) ($session['status'] ?? '');
    if ($status !== 'live_veto' && $status !== 'live_order') {
        return;
    }
    $expires = $session['turn_expires_at'] ?? null;
    if (!$expires) {
        return;
    }
    $expTs = strtotime((string) $expires);
    if ($expTs === false || time() <= $expTs) {
        return;
    }

    if (($session['current_phase'] ?? '') === 'veto') {
        map_veto_force_random_veto($session);
    } elseif (($session['current_phase'] ?? '') === 'order') {
        map_veto_force_random_pick($session);
    }
}

/**
 * @param array<string, mixed> $session
 */
function map_veto_force_random_veto(array &$session): void
{
    $choices = [];
    foreach ($session['session_maps'] ?? [] as $idx => $row) {
        if (($row['state'] ?? '') === 'available') {
            $choices[] = ['idx' => $idx, 'map_id' => (string) ($row['map_id'] ?? '')];
        }
    }
    if ($choices === []) {
        return;
    }
    $pick = $choices[random_int(0, count($choices) - 1)];
    $side = (string) ($session['current_turn_side'] ?? 'a');
    map_veto_apply_veto_internal($session, $side, $pick['map_id'], true);
}

/**
 * @param array<string, mixed> $session
 */
function map_veto_force_random_pick(array &$session): void
{
    $choices = [];
    foreach ($session['session_maps'] ?? [] as $idx => $row) {
        if (($row['state'] ?? '') === 'available') {
            $choices[] = ['idx' => $idx, 'map_id' => (string) ($row['map_id'] ?? '')];
        }
    }
    if ($choices === []) {
        return;
    }
    $pick = $choices[random_int(0, count($choices) - 1)];
    $side = (string) ($session['current_turn_side'] ?? 'a');
    map_veto_apply_pick_internal($session, $side, $pick['map_id'], true);
}

/**
 * @param array<string, mixed> $session
 */
function map_veto_apply_veto_internal(array &$session, string $playerSide, string $mapId, bool $wasTimeout): void
{
    $mapsToPlay = (int) ($session['maps_to_play'] ?? 1);
    $foundIdx = null;
    foreach ($session['session_maps'] ?? [] as $idx => $row) {
        if (($row['map_id'] ?? '') === $mapId && ($row['state'] ?? '') === 'available') {
            $foundIdx = $idx;
            break;
        }
    }
    if ($foundIdx === null) {
        return;
    }
    $session['session_maps'][$foundIdx]['state'] = 'vetoed';
    $session['session_maps'][$foundIdx]['vetoed_by'] = $playerSide;
    map_veto_append_action($session, [
        'phase' => 'veto',
        'acting_side' => $playerSide,
        'action_type' => $wasTimeout ? 'autoveto' : 'veto',
        'map_id' => $mapId,
        'was_timeout' => $wasTimeout,
    ]);

    $remaining = map_veto_count_available($session);
    if ($remaining === $mapsToPlay) {
        map_veto_transition_to_order($session);
        map_veto_process_tick($session);
        return;
    }
    $session['current_turn_side'] = map_veto_opposite_side((string) ($session['current_turn_side'] ?? 'a'));
    map_veto_begin_turn($session);
}

/**
 * @param array<string, mixed> $session
 */
function map_veto_apply_pick_internal(array &$session, string $playerSide, string $mapId, bool $wasTimeout): void
{
    $mapsToPlay = (int) ($session['maps_to_play'] ?? 1);
    $gameNum = (int) ($session['game_number'] ?? 1);
    $foundIdx = null;
    foreach ($session['session_maps'] ?? [] as $idx => $row) {
        if (($row['map_id'] ?? '') === $mapId && ($row['state'] ?? '') === 'available') {
            $foundIdx = $idx;
            break;
        }
    }
    if ($foundIdx === null) {
        return;
    }
    $session['session_maps'][$foundIdx]['state'] = 'assigned';
    $session['session_maps'][$foundIdx]['game_number'] = $gameNum;
    map_veto_append_action($session, [
        'phase' => 'order',
        'acting_side' => $playerSide,
        'action_type' => $wasTimeout ? 'autopick' : 'pick_order',
        'map_id' => $mapId,
        'game_number' => $gameNum,
        'was_timeout' => $wasTimeout,
    ]);

    $session['game_number'] = $gameNum + 1;
    if ($session['game_number'] > $mapsToPlay) {
        map_veto_complete($session);
        return;
    }
    $session['current_turn_side'] = map_veto_opposite_side((string) ($session['current_turn_side'] ?? 'a'));
    map_veto_begin_turn($session);
    map_veto_process_tick($session);
}

/**
 * @param string $matchTitle Required label for this veto session (shown in admin list).
 *
 * @param ?string $seedHigherSideOverride If null, higher seed follows ladder (+ Tie on equal ranks).
 *                                       If 'a' or 'b', that side is forced higher for veto flow only;
 *                                       displayed ranks still come from ladder / manual rank args.
 *
 * @return array<string, mixed>|array{success: false, message: string}
 */
function map_veto_create_session(
    string $seasonId,
    string $matchTitle,
    string $playerAName,
    string $playerBName,
    int $bestOf,
    int $timerSeconds,
    string $tieBreak,
    ?int $manualRankA,
    ?int $manualRankB,
    ?string $seedHigherSideOverride = null
): array {
    require_once __DIR__ . '/map_veto_rankings.php';
    $matchTitle = trim($matchTitle);
    if ($matchTitle === '') {
        return ['success' => false, 'message' => 'Match title is required.'];
    }
    if (mb_strlen($matchTitle) > 200) {
        return ['success' => false, 'message' => 'Match title must be 200 characters or less.'];
    }
    if (!in_array($bestOf, [1, 3, 5, 7, 9], true)) {
        return ['success' => false, 'message' => 'Invalid best-of.'];
    }
    $seasons = map_veto_load_seasons();
    $season = null;
    foreach ($seasons as $s) {
        if ((string) ($s['id'] ?? '') === $seasonId) {
            $season = $s;
            break;
        }
    }
    if ($season === null) {
        return ['success' => false, 'message' => 'Season not found.'];
    }
    $maps = map_veto_load_maps();
    $pool = map_veto_build_effective_pool($maps, $season, $bestOf);
    $enabledInSeason = 0;
    foreach ($season['enabled_map_ids'] ?? [] as $mid) {
        foreach ($maps as $m) {
            if ((string) ($m['id'] ?? '') === (string) $mid && !empty($m['is_active'])) {
                $enabledInSeason++;
                break;
            }
        }
    }
    if ($enabledInSeason < 7) {
        return ['success' => false, 'message' => 'Season needs at least 7 active enabled maps.'];
    }
    $mapsToPlay = $bestOf;
    if (count($pool) <= $mapsToPlay) {
        return ['success' => false, 'message' => 'Effective map pool must be larger than maps to play (for vetoes).'];
    }

    $ra = map_veto_lookup_ranking_by_name($playerAName);
    $rb = map_veto_lookup_ranking_by_name($playerBName);
    /** @var int|null $rankAResolved ladder or manual only; null = unranked */
    $rankAResolved = $manualRankA ?? ($ra ? $ra['rank'] : null);
    /** @var int|null $rankBResolved */
    $rankBResolved = $manualRankB ?? ($rb ? $rb['rank'] : null);

    /**
     * Lower numeric rank = better placement on the ladder.
     * Missing ladder entry / unranked → large sentinel so ranked players seed above unranked.
     * Two unranked players share the same sentinel → tie (use tie-break: random / seed A / seed B).
     */
    $unrankedSentinel = 999999;
    $rankA = $rankAResolved ?? $unrankedSentinel;
    $rankB = $rankBResolved ?? $unrankedSentinel;

    $displayA = $ra['name'] ?? $playerAName;
    $displayB = $rb['name'] ?? $playerBName;

    $higher = 'a';
    $lower = 'b';
    $resolvedTieBreak = $tieBreak;
    if ($seedHigherSideOverride === 'a' || $seedHigherSideOverride === 'b') {
        $higher = $seedHigherSideOverride;
        $lower = map_veto_opposite_side($higher);
        $resolvedTieBreak = 'seed_override:' . $seedHigherSideOverride;
    } elseif ($rankA < $rankB) {
        $higher = 'a';
        $lower = 'b';
    } elseif ($rankB < $rankA) {
        $higher = 'b';
        $lower = 'a';
    } else {
        if ($tieBreak === 'a' || $tieBreak === 'b') {
            $higher = $tieBreak;
            $lower = map_veto_opposite_side($tieBreak);
        } else {
            $higher = random_int(0, 1) === 0 ? 'a' : 'b';
            $lower = map_veto_opposite_side($higher);
            $resolvedTieBreak = 'random:' . $higher;
        }
    }

    $sessionMaps = [];
    foreach ($pool as $m) {
        $sessionMaps[] = map_veto_session_map_snapshot($m);
    }

    $id = 'mv_' . bin2hex(random_bytes(12));
    $session = [
        'id' => $id,
        'match_title' => $matchTitle,
        'season_id' => $seasonId,
        'season_name' => (string) ($season['name'] ?? $seasonId),
        'best_of' => $bestOf,
        'maps_to_play' => $mapsToPlay,
        'timer_seconds' => max(15, min(600, $timerSeconds)),
        'status' => 'pending',
        'current_phase' => 'veto',
        'player_a' => ['display_name' => $displayA, 'rank' => $rankAResolved],
        'player_b' => ['display_name' => $displayB, 'rank' => $rankBResolved],
        'higher_side' => $higher,
        'lower_side' => $lower,
        'tie_break' => $resolvedTieBreak,
        'current_turn_side' => $higher,
        'game_number' => 1,
        'session_maps' => $sessionMaps,
        'actions' => [],
        'tokens' => [
            'player_a' => map_veto_generate_token(),
            'player_b' => map_veto_generate_token(),
            'public' => map_veto_generate_token(),
        ],
        'revision' => 1,
        'created_at' => gmdate('c'),
        'started_at' => null,
        'completed_at' => null,
        'turn_started_at' => null,
        'turn_expires_at' => null,
    ];
    map_veto_save_session($session);
    return $session;
}

/**
 * @return array<string, mixed>|null
 */
function map_veto_start_session(string $id): ?array
{
    return map_veto_session_transaction($id, static function (array $s): ?array {
        if (($s['status'] ?? '') !== 'pending') {
            return $s;
        }
        $s['status'] = 'live_veto';
        $s['current_phase'] = 'veto';
        $s['current_turn_side'] = $s['higher_side'];
        $s['started_at'] = gmdate('c');
        map_veto_begin_turn($s);
        map_veto_process_tick($s);
        return $s;
    });
}

/**
 * @return array<string, mixed>|array{success: false, message: string}
 */
function map_veto_submit_veto(string $sessionId, string $playerSide, string $mapId)
{
    $out = map_veto_session_transaction($sessionId, static function (array $s) use ($playerSide, $mapId): ?array {
        if (($s['status'] ?? '') !== 'live_veto' || ($s['current_phase'] ?? '') !== 'veto') {
            return $s;
        }
        if (($s['current_turn_side'] ?? '') !== $playerSide) {
            return $s;
        }
        map_veto_process_tick($s);
        if (($s['status'] ?? '') !== 'live_veto') {
            return $s;
        }
        map_veto_apply_veto_internal($s, $playerSide, $mapId, false);
        map_veto_process_tick($s);
        return $s;
    });
    if ($out === null) {
        return ['success' => false, 'message' => 'Session not found.'];
    }
    return $out;
}

/**
 * @return array<string, mixed>|array{success: false, message: string}
 */
function map_veto_submit_pick(string $sessionId, string $playerSide, string $mapId)
{
    $out = map_veto_session_transaction($sessionId, static function (array $s) use ($playerSide, $mapId): ?array {
        if (($s['status'] ?? '') !== 'live_order' || ($s['current_phase'] ?? '') !== 'order') {
            return $s;
        }
        if (($s['current_turn_side'] ?? '') !== $playerSide) {
            return $s;
        }
        map_veto_process_tick($s);
        if (($s['status'] ?? '') !== 'live_order') {
            return $s;
        }
        map_veto_apply_pick_internal($s, $playerSide, $mapId, false);
        map_veto_process_tick($s);
        return $s;
    });
    if ($out === null) {
        return ['success' => false, 'message' => 'Session not found.'];
    }
    return $out;
}

/**
 * Used by cron or admin to advance stuck sessions.
 *
 * @return array<string, mixed>|null
 */
function map_veto_get_session_resolved(string $id): ?array
{
    return map_veto_session_transaction($id, static function (array $s): array {
        map_veto_process_tick($s);
        return $s;
    });
}

/**
 * Read-only resolve for public API (uses lock).
 *
 * @return array<string, mixed>|null
 */
function map_veto_refresh_session(string $id): ?array
{
    return map_veto_get_session_resolved($id);
}

/**
 * @return array<string, mixed>|null
 */
function map_veto_cancel_session(string $id): ?array
{
    return map_veto_session_transaction($id, static function (array $s): array {
        $st = (string) ($s['status'] ?? '');
        if ($st === 'completed' || $st === 'cancelled') {
            return $s;
        }
        $s['status'] = 'cancelled';
        $s['current_phase'] = 'completed';
        $s['current_turn_side'] = null;
        $s['turn_started_at'] = null;
        $s['turn_expires_at'] = null;
        $s['revision'] = (int) ($s['revision'] ?? 0) + 1;
        return $s;
    });
}

/**
 * @return array<string, mixed>|null
 */
function map_veto_regenerate_tokens(string $id): ?array
{
    return map_veto_session_transaction($id, static function (array $s): array {
        if (($s['status'] ?? '') === 'completed') {
            return $s;
        }
        $s['tokens'] = [
            'player_a' => map_veto_generate_token(),
            'player_b' => map_veto_generate_token(),
            'public' => map_veto_generate_token(),
        ];
        $s['revision'] = (int) ($s['revision'] ?? 0) + 1;
        return $s;
    });
}

/**
 * Reset an existing session to the same state as a newly created one: pending, empty action log,
 * fresh map pool from the current season catalog. Keeps id, players, seeding, match title, timer, and tokens.
 *
 * @return array{success: true}|array{success: false, message: string}
 */
function map_veto_reset_session_to_start(string $id): array
{
    $message = '';
    $out = map_veto_session_transaction($id, function (array $s) use (&$message): ?array {
        $seasonId = (string) ($s['season_id'] ?? '');
        if ($seasonId === '') {
            $message = 'Session has no season.';
            return null;
        }
        $bestOf = (int) ($s['best_of'] ?? 1);
        if (!in_array($bestOf, [1, 3, 5, 7, 9], true)) {
            $message = 'Invalid best-of on session.';
            return null;
        }
        $seasons = map_veto_load_seasons();
        $season = null;
        foreach ($seasons as $row) {
            if ((string) ($row['id'] ?? '') === $seasonId) {
                $season = $row;
                break;
            }
        }
        if ($season === null) {
            $message = 'Season not found — update the session or seasons JSON.';
            return null;
        }
        $maps = map_veto_load_maps();
        $enabledInSeason = 0;
        foreach ($season['enabled_map_ids'] ?? [] as $mid) {
            foreach ($maps as $m) {
                if ((string) ($m['id'] ?? '') === (string) $mid && !empty($m['is_active'])) {
                    ++$enabledInSeason;
                    break;
                }
            }
        }
        if ($enabledInSeason < 7) {
            $message = 'Season needs at least 7 active enabled maps.';
            return null;
        }
        $pool = map_veto_build_effective_pool($maps, $season, $bestOf);
        $mapsToPlay = $bestOf;
        if (count($pool) <= $mapsToPlay) {
            $message = 'Effective map pool must be larger than maps to play.';
            return null;
        }
        $sessionMaps = [];
        foreach ($pool as $m) {
            $sessionMaps[] = map_veto_session_map_snapshot($m);
        }

        $s['session_maps'] = $sessionMaps;
        $s['maps_to_play'] = $mapsToPlay;
        $s['status'] = 'pending';
        $s['current_phase'] = 'veto';
        $s['current_turn_side'] = (string) ($s['higher_side'] ?? 'a');
        $s['game_number'] = 1;
        $s['actions'] = [];
        $s['started_at'] = null;
        $s['completed_at'] = null;
        $s['turn_started_at'] = null;
        $s['turn_expires_at'] = null;
        $s['revision'] = (int) ($s['revision'] ?? 0) + 1;

        return $s;
    });

    if ($out === null) {
        return [
            'success' => false,
            'message' => $message !== '' ? $message : 'Session not found or could not be reset.',
        ];
    }

    return ['success' => true];
}
