<?php
/**
 * JSON/state shaping for player and watch UIs.
 */

declare(strict_types=1);

/**
 * Public host-relative base for map-veto routes (e.g. /fsl/map-veto).
 */
function map_veto_url_base_path(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $pos = strpos($script, '/map-veto/');
    if ($pos !== false) {
        return substr($script, 0, $pos + strlen('/map-veto'));
    }
    $dir = dirname($script);
    return rtrim($dir, '/') . '/map-veto';
}

/**
 * Full base URL for sharing links from the current request.
 */
function map_veto_absolute_base(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . map_veto_url_base_path();
}

/**
 * @param array<string, mixed> $session
 */
function map_veto_player_display_side(array $session, string $side): string
{
    $pa = $session['player_a'] ?? [];
    $pb = $session['player_b'] ?? [];
    $nameA = is_array($pa) ? (string) ($pa['display_name'] ?? 'Player A') : 'Player A';
    $nameB = is_array($pb) ? (string) ($pb['display_name'] ?? 'Player B') : 'Player B';
    return $side === 'b' ? $nameB : $nameA;
}

/**
 * Web path to a player headshot for map-veto pages (relative to map-veto/*.php).
 * Same filename rules as player_network.php: images/player_thumbnails/{sanitized}.png|.jpg
 */
function map_veto_player_thumbnail_href(string $displayName): ?string
{
    $displayName = trim($displayName);
    if ($displayName === '') {
        return null;
    }
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '', str_replace(' ', '_', $displayName));
    if ($safeName === '') {
        return null;
    }
    $projectRoot = dirname(__DIR__);
    $baseFs = $projectRoot . '/images/player_thumbnails/' . $safeName;
    if (is_file($baseFs . '.png')) {
        return '../images/player_thumbnails/' . $safeName . '.png';
    }
    if (is_file($baseFs . '.jpg')) {
        return '../images/player_thumbnails/' . $safeName . '.jpg';
    }
    return null;
}

/**
 * @param array<string, mixed>|null $player
 * @return array<string, mixed>|null
 */
function map_veto_player_payload_with_thumbnail(?array $player): ?array
{
    if ($player === null) {
        return null;
    }
    $out = $player;
    $href = map_veto_player_thumbnail_href((string) ($player['display_name'] ?? ''));
    if ($href !== null) {
        $out['thumbnail_url'] = $href;
    }
    return $out;
}

/**
 * Veto / map-order progress for UIs (1-based indices for display).
 *
 * @return array{veto_progress: array<string, int>|null, order_progress: array<string, int>|null}
 */
function map_veto_progress_for_payload(array $session): array
{
    $status = (string) ($session['status'] ?? '');
    $phase = (string) ($session['current_phase'] ?? '');
    $maps = $session['session_maps'] ?? [];
    if (!is_array($maps)) {
        $maps = [];
    }
    $mapsToPlay = (int) ($session['maps_to_play'] ?? 1);

    $vetoed = 0;
    $avail = 0;
    $assigned = 0;
    foreach ($maps as $row) {
        $st = (string) ($row['state'] ?? '');
        if ($st === 'vetoed') {
            ++$vetoed;
        } elseif ($st === 'available') {
            ++$avail;
        } elseif ($st === 'assigned') {
            ++$assigned;
        }
    }
    $poolSize = $vetoed + $avail + $assigned;
    $totalVetoes = max(0, $poolSize - $mapsToPlay);

    $vetoProgress = null;
    if ($status === 'live_veto' && $phase === 'veto' && $totalVetoes > 0) {
        $current = min($vetoed + 1, $totalVetoes);
        $vetoProgress = [
            'current' => $current,
            'total' => $totalVetoes,
            'completed' => $vetoed,
        ];
    }

    $orderProgress = null;
    if ($status === 'live_order' && $phase === 'order') {
        $gn = (int) ($session['game_number'] ?? 1);
        $orderProgress = [
            'current_game' => min(max(1, $gn), max(1, $mapsToPlay)),
            'total_games' => max(1, $mapsToPlay),
            'maps_picked' => $assigned,
        ];
    }

    return [
        'veto_progress' => $vetoProgress,
        'order_progress' => $orderProgress,
    ];
}

/**
 * @param array<string, mixed> $session
 * @return array<string, mixed>
 */
function map_veto_build_payload(array $session, string $role): array
{
    $status = (string) ($session['status'] ?? '');
    $phase = (string) ($session['current_phase'] ?? '');
    $turn = (string) ($session['current_turn_side'] ?? '');
    $maps = $session['session_maps'] ?? [];
    if (!is_array($maps)) {
        $maps = [];
    }
    $actions = $session['actions'] ?? [];
    if (!is_array($actions)) {
        $actions = [];
    }

    /** @var array<string, array<string, mixed>> */
    $catalogById = [];
    foreach (map_veto_load_maps() as $cm) {
        $cid = (string) ($cm['id'] ?? '');
        if ($cid !== '') {
            $catalogById[$cid] = $cm;
        }
    }
    foreach ($maps as $idx => &$mapRow) {
        if (!is_array($mapRow)) {
            continue;
        }
        $mid = (string) ($mapRow['map_id'] ?? '');
        if ($mid !== '' && isset($catalogById[$mid])) {
            $mapRow['description'] = (string) ($catalogById[$mid]['description'] ?? '');
        }
    }
    unset($mapRow);

    $mySide = null;
    if ($role === 'player_a') {
        $mySide = 'a';
    } elseif ($role === 'player_b') {
        $mySide = 'b';
    }

    $paused = !empty($session['paused']);
    $pauseRem = (int) ($session['pause_remaining_seconds'] ?? 0);

    $isMyTurn = false;
    if (
        $mySide !== null && $turn === $mySide && ($status === 'live_veto' || $status === 'live_order')
        && !$paused
    ) {
        $isMyTurn = true;
    }

    $finalOrder = [];
    foreach ($maps as $row) {
        if (($row['state'] ?? '') === 'assigned' && isset($row['game_number'])) {
            $finalOrder[(int) $row['game_number']] = $row;
        }
    }
    ksort($finalOrder);

    $progress = map_veto_progress_for_payload($session);

    $payload = [
        'id' => (string) ($session['id'] ?? ''),
        'revision' => (int) ($session['revision'] ?? 0),
        'match_title' => (string) ($session['match_title'] ?? ''),
        'status' => $status,
        'phase' => $phase,
        'season_name' => (string) ($session['season_name'] ?? ''),
        'best_of' => (int) ($session['best_of'] ?? 1),
        'maps_to_play' => (int) ($session['maps_to_play'] ?? 1),
        'timer_seconds' => (int) ($session['timer_seconds'] ?? 60),
        'paused' => $paused,
        'pause_remaining_seconds' => $paused ? $pauseRem : null,
        'player_a' => map_veto_player_payload_with_thumbnail(
            isset($session['player_a']) && is_array($session['player_a']) ? $session['player_a'] : null
        ),
        'player_b' => map_veto_player_payload_with_thumbnail(
            isset($session['player_b']) && is_array($session['player_b']) ? $session['player_b'] : null
        ),
        'higher_side' => (string) ($session['higher_side'] ?? 'a'),
        'lower_side' => (string) ($session['lower_side'] ?? 'b'),
        'current_turn_side' => $turn,
        'current_turn_label' => $turn !== '' ? map_veto_player_display_side($session, $turn) : '',
        'game_number' => (int) ($session['game_number'] ?? 1),
        'turn_started_at' => $paused ? null : ($session['turn_started_at'] ?? null),
        'turn_expires_at' => $paused ? null : ($session['turn_expires_at'] ?? null),
        'session_maps' => array_values($maps),
        'actions' => array_values($actions),
        'final_order' => array_values($finalOrder),
        'role' => $role,
        'my_side' => $mySide,
        'is_my_turn' => $isMyTurn,
        'tie_break' => $session['tie_break'] ?? null,
        'veto_progress' => $progress['veto_progress'],
        'order_progress' => $progress['order_progress'],
    ];

    return $payload;
}
