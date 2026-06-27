<?php
/**
 * Community rankings voting — session file, ballots, aggregation, log.
 * Paths are relative to the rankings/ directory.
 */

declare(strict_types=1);

const RANKINGS_PERM_SUPER = 'edit player, team, stats';
const RANKINGS_PERM_VOTE = 'rankings community vote';

const RANKINGS_VOTING_SESSION_FILE = __DIR__ . '/voting_session.json';
const RANKINGS_VOTING_LOG_FILE = __DIR__ . '/voting_log.txt';
const RANKINGS_SNAPSHOT_FILE = __DIR__ . '/last_publish_snapshot.json';

function rankings_user_has_permission(PDO $db, string $userId, string $permissionName): bool
{
    try {
        $stmt = $db->prepare('
            SELECT COUNT(*) AS cnt
            FROM ws_user_roles ur
            JOIN ws_role_permissions rp ON ur.role_id = rp.role_id
            JOIN ws_permissions p ON rp.permission_id = p.permission_id
            WHERE ur.user_id = ? AND p.permission_name = ?
        ');
        $stmt->execute([$userId, $permissionName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && (int) $row['cnt'] > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/** @return array<string,mixed>|null */
function rankings_voting_load_session(): ?array
{
    if (!is_readable(RANKINGS_VOTING_SESSION_FILE)) {
        return null;
    }
    $raw = file_get_contents(RANKINGS_VOTING_SESSION_FILE);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || ($data['status'] ?? '') === 'idle') {
        return null;
    }
    rankings_voting_session_normalize($data);
    return $data;
}

/** Auto-close collecting session when past closes_at. */
function rankings_voting_session_normalize(array &$session): void
{
    if (($session['status'] ?? '') !== 'collecting') {
        return;
    }
    $close = strtotime((string) ($session['closes_at'] ?? ''));
    if ($close !== false && time() > $close) {
        $session['status'] = 'closed';
        $session['closed_at'] = gmdate('Y-m-d\TH:i:s\Z');
        rankings_voting_save_session($session);
    }
}

/** @param array<string,mixed> $data */
function rankings_voting_save_session(array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    return file_put_contents(RANKINGS_VOTING_SESSION_FILE, $json, LOCK_EX) !== false;
}

function rankings_voting_clear_session(): void
{
    if (is_file(RANKINGS_VOTING_SESSION_FILE)) {
        @unlink(RANKINGS_VOTING_SESSION_FILE);
    }
}

function rankings_voting_sessions_dir(string $sessionId): string
{
    return __DIR__ . '/sessions/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
}

function rankings_voting_ballots_path(string $sessionId): string
{
    return rankings_voting_sessions_dir($sessionId) . '/ballots.json';
}

/** @param array<int,array<string,mixed>> $baseline */
function rankings_voting_ballots_load(string $sessionId): array
{
    $path = rankings_voting_ballots_path($sessionId);
    if (!is_readable($path)) {
        return [];
    }
    $j = json_decode((string) file_get_contents($path), true);
    return is_array($j) ? $j : [];
}

/** @param array<int,array<string,mixed>> $ballots */
function rankings_voting_ballots_save(string $sessionId, array $ballots): bool
{
    $dir = rankings_voting_sessions_dir($sessionId);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return false;
    }
    $json = json_encode(array_values($ballots), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    return file_put_contents(rankings_voting_ballots_path($sessionId), $json, LOCK_EX) !== false;
}

/**
 * Positive = moved up in standings (better rank number). Negative = down.
 * delta = baseline_rank - proposed_rank (1-based).
 *
 * @param array<int,array<string,mixed>> $baselineOrdered
 * @param array<int,string> $orderedNames
 * @return array<string,int>
 */
function rankings_voting_deltas_from_order(array $baselineOrdered, array $orderedNames): array
{
    $rankByName = [];
    foreach ($baselineOrdered as $i => $row) {
        $n = (string) ($row['name'] ?? '');
        if ($n !== '') {
            $rankByName[$n] = $i + 1;
        }
    }
    $nameSet = array_keys($rankByName);
    sort($nameSet);
    $got = $orderedNames;
    sort($got);
    if ($nameSet !== $got) {
        return [];
    }
    $deltas = [];
    foreach ($orderedNames as $i => $name) {
        $proposed = $i + 1;
        $base = $rankByName[$name] ?? $proposed;
        $deltas[$name] = (int) $base - (int) $proposed;
    }
    return $deltas;
}

/**
 * Active session blocks super from editing canonical rankings.json.
 */
function rankings_voting_blocks_canonical_edit(?array $session): bool
{
    if ($session === null) {
        return false;
    }
    $st = (string) ($session['status'] ?? '');
    return in_array($st, ['collecting', 'closed', 'preview'], true);
}

function rankings_voting_is_collecting_now(?array $session): bool
{
    if ($session === null || ($session['status'] ?? '') !== 'collecting') {
        return false;
    }
    $now = time();
    $open = strtotime((string) ($session['opens_at'] ?? ''));
    $close = strtotime((string) ($session['closes_at'] ?? ''));
    if ($open === false || $close === false) {
        return false;
    }
    return $now >= $open && $now <= $close;
}

function rankings_voting_append_log(string $text): void
{
    $line = '[' . gmdate('Y-m-d\TH:i:s\Z') . "] " . str_replace(["\r\n", "\r"], "\n", $text) . "\n";
    file_put_contents(RANKINGS_VOTING_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

/** @param array<string,int> $deltas */
function rankings_voting_nonzero_deltas_only(array $deltas): array
{
    return array_filter($deltas, static function ($v): bool {
        return (int) $v !== 0;
    });
}

/**
 * @param array<int,array<string,mixed>> $baseline
 * @param array<int,array{user_id:string,username:string,submitted_at:string,deltas:array<string,int>}> $ballots
 * @return array{applied: array<string,int>, details: array<int,string>}
 */
function rankings_voting_aggregate(array $baseline, array $ballots): array
{
    $names = [];
    foreach ($baseline as $row) {
        $n = (string) ($row['name'] ?? '');
        if ($n !== '') {
            $names[] = $n;
        }
    }
    $totalBallots = count($ballots);
    $applied = [];

    if ($totalBallots === 0) {
        return [
            'applied' => [],
            'details' => ['Aggregation ballots=0 applied={}'],
        ];
    }

    foreach ($names as $player) {
        $down = 0;
        $up = 0;
        $winningDeltas = [];

        foreach ($ballots as $b) {
            $d = (int) ($b['deltas'][$player] ?? 0);
            if ($d < 0) {
                $down++;
            } elseif ($d > 0) {
                $up++;
            }
        }

        $downWins = (2 * $down >= $totalBallots) && ($down > $up);
        $upWins = (2 * $up >= $totalBallots) && ($up > $down);

        if ($downWins && $upWins) {
            continue;
        }
        if (!$downWins && !$upWins) {
            continue;
        }

        $sign = $downWins ? -1 : 1;
        foreach ($ballots as $b) {
            $d = (int) ($b['deltas'][$player] ?? 0);
            if ($sign < 0 && $d < 0) {
                $winningDeltas[] = abs($d);
            } elseif ($sign > 0 && $d > 0) {
                $winningDeltas[] = abs($d);
            }
        }
        if ($winningDeltas === []) {
            continue;
        }
        $avg = array_sum($winningDeltas) / count($winningDeltas);
        $mag = (int) round($avg, 0, PHP_ROUND_HALF_UP);
        if ($mag < 1) {
            continue;
        }
        $applied[$player] = $sign * $mag;
    }

    $enc = json_encode($applied, JSON_UNESCAPED_UNICODE);
    if ($enc === false) {
        $enc = '{}';
    }
    $details = ['Aggregation ballots=' . $totalBallots . ' applied=' . $enc];
    if ($applied === []) {
        $details[] = 'Consensus: none.';
    }

    return ['applied' => $applied, 'details' => $details];
}

/**
 * @param array<int,array<string,mixed>> $baselineOrdered
 * @param array<string,int> $signedDeltas name => signed (positive = toward rank 1)
 * @return array<int,array<string,mixed>>
 */
function rankings_voting_merge_apply_deltas(array $baselineOrdered, array $signedDeltas): array
{
    $n = count($baselineOrdered);
    if ($n === 0) {
        return [];
    }
    $indexed = [];
    foreach ($baselineOrdered as $i => $row) {
        $name = (string) ($row['name'] ?? '');
        $delta = (int) ($signedDeltas[$name] ?? 0);
        $desired = $i - $delta;
        if ($desired < 0) {
            $desired = 0;
        }
        if ($desired > $n - 1) {
            $desired = $n - 1;
        }
        $indexed[] = ['row' => $row, 'desired' => $desired, 'baseline' => $i];
    }
    usort($indexed, static function (array $a, array $b): int {
        if ($a['desired'] !== $b['desired']) {
            return $a['desired'] <=> $b['desired'];
        }
        return $a['baseline'] <=> $b['baseline'];
    });
    $out = [];
    foreach ($indexed as $item) {
        $out[] = $item['row'];
    }
    foreach ($out as $i => &$p) {
        $p['rank'] = $i + 1;
    }
    unset($p);

    return $out;
}

/**
 * @return array<string,int>|null
 */
function rankings_voting_load_snapshot_ranks(): ?array
{
    if (!is_readable(RANKINGS_SNAPSHOT_FILE)) {
        return null;
    }
    $data = json_decode((string) file_get_contents(RANKINGS_SNAPSHOT_FILE), true);
    if (!is_array($data) || !isset($data['ranks']) || !is_array($data['ranks'])) {
        return null;
    }
    $out = [];
    foreach ($data['ranks'] as $k => $v) {
        $out[(string) $k] = (int) $v;
    }
    return $out;
}

/** @param array<int,array<string,mixed>> $rankingsOrdered */
function rankings_voting_save_snapshot(array $rankingsOrdered, string $publishedAtIso): bool
{
    $ranks = [];
    foreach ($rankingsOrdered as $p) {
        $n = (string) ($p['name'] ?? '');
        if ($n !== '') {
            $ranks[$n] = (int) ($p['rank'] ?? 0);
        }
    }
    $payload = [
        'published_at' => $publishedAtIso,
        'ranks' => $ranks,
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    return file_put_contents(RANKINGS_SNAPSHOT_FILE, $json, LOCK_EX) !== false;
}

/**
 * @return array<string,int> name => display delta (negative = rank number went up / “down” in list)
 */
function rankings_voting_rank_movement_vs_snapshot(array $currentRankings, ?array $snapshotRanks): array
{
    if ($snapshotRanks === null || $snapshotRanks === []) {
        return [];
    }
    $movement = [];
    foreach ($currentRankings as $p) {
        $name = (string) ($p['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $now = (int) ($p['rank'] ?? 0);
        if (!isset($snapshotRanks[$name])) {
            continue;
        }
        $was = (int) $snapshotRanks[$name];
        if ($was === $now) {
            continue;
        }
        // Higher rank number = worse. “Down 3” in UI: was 5 now 8 -> show -3 (user convention)
        $movement[$name] = $was - $now;
    }
    return $movement;
}
