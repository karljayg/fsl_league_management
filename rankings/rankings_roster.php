<?php
/**
 * Keep rankings.json in step with Players.Status.
 *
 * rankings.json holds active players only, ranked 1..N (groups of 4 follow rank).
 * rankings_inactive.json remembers the last rank of everyone who left the list.
 *
 * Going inactive: removed, rank saved, everyone below moves up.
 * Coming back:    placed directly below whoever holds their saved rank now; everyone below moves down.
 * Never ranked:   appended at the bottom.
 *
 * Batches: removals first, then returners in saved-rank order (ties by name). Each returner is
 * anchored to the post-removal list, so the result does not depend on the order changes were made.
 */

declare(strict_types=1);

require_once __DIR__ . '/voting_logic.php';
require_once __DIR__ . '/rankings_enrich.php';

const RANKINGS_ROSTER_RANKINGS_FILE = __DIR__ . '/rankings.json';
const RANKINGS_ROSTER_INACTIVE_FILE = __DIR__ . '/rankings_inactive.json';
const RANKINGS_ROSTER_LOCK_FILE = __DIR__ . '/.rankings_roster.lock';
const RANKINGS_ROSTER_LOG_FILE = __DIR__ . '/roster_sync_log.txt';

/** @return array<int,array<string,mixed>> */
function rankings_roster_read_json_list(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? array_values($data) : [];
}

/**
 * @return array<string,array{name:string,status:string}> lowercase name => Real_Name + Status
 */
function rankings_roster_db_players(PDO $db): array
{
    $out = [];
    $stmt = $db->query('SELECT Real_Name, Status FROM Players');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $name = trim((string) $row['Real_Name']);
        if ($name !== '') {
            $out[strtolower($name)] = ['name' => $name, 'status' => (string) $row['Status']];
        }
    }
    return $out;
}

/**
 * Pure planning step. No I/O.
 *
 * @param array<int,array<string,mixed>> $rankings ordered active list
 * @param array<int,array<string,mixed>> $inactive saved entries
 * @param array<string,array{name:string,status:string}> $dbPlayers
 * @return array{
 *   rankings: array<int,array<string,mixed>>,
 *   inactive: array<int,array<string,mixed>>,
 *   deactivated: array<int,array<string,mixed>>,
 *   reactivated: array<int,array<string,mixed>>,
 *   added: array<int,array<string,mixed>>,
 *   unmatched: array<int,string>,
 *   changed: bool
 * }
 */
function rankings_roster_plan(array $rankings, array $inactive, array $dbPlayers, string $nowIso): array
{
    usort($rankings, static fn($a, $b) => (int) ($a['rank'] ?? 0) <=> (int) ($b['rank'] ?? 0));

    $inactiveByKey = [];
    foreach ($inactive as $entry) {
        $key = strtolower(trim((string) ($entry['name'] ?? '')));
        if ($key !== '') {
            $inactiveByKey[$key] = $entry;
        }
    }

    $deactivated = [];
    $unmatched = [];
    $kept = [];
    foreach ($rankings as $i => $row) {
        $name = trim((string) ($row['name'] ?? ''));
        $key = strtolower($name);
        $db = $dbPlayers[$key] ?? null;
        if ($db === null) {
            $unmatched[] = $name;
            $kept[] = $row;
            continue;
        }
        if ($db['status'] === 'active') {
            $kept[] = $row;
            continue;
        }
        $rank = $i + 1;
        $entry = [
            'name' => $db['name'],
            'last_rank' => $rank,
            'last_group' => (int) ceil($rank / 4),
            'above' => $i > 0 ? (string) $rankings[$i - 1]['name'] : null,
            'below' => isset($rankings[$i + 1]) ? (string) $rankings[$i + 1]['name'] : null,
            'race' => $row['race'] ?? null,
            'status' => $db['status'],
            'removed_at' => $nowIso,
        ];
        $inactiveByKey[$key] = $entry;
        $deactivated[] = $entry;
    }

    $rankedKeys = [];
    foreach ($kept as $row) {
        $key = strtolower(trim((string) $row['name']));
        $rankedKeys[$key] = true;
        unset($inactiveByKey[$key]);
    }

    $returning = [];
    $added = [];
    foreach ($dbPlayers as $key => $db) {
        if ($db['status'] !== 'active' || isset($rankedKeys[$key])) {
            continue;
        }
        if (isset($inactiveByKey[$key])) {
            $returning[] = $inactiveByKey[$key] + ['_key' => $key, '_name' => $db['name']];
        } else {
            $added[] = ['name' => $db['name']];
        }
    }

    usort($returning, static function ($a, $b): int {
        $byRank = (int) $a['last_rank'] <=> (int) $b['last_rank'];
        return $byRank !== 0 ? $byRank : strcasecmp((string) $a['_name'], (string) $b['_name']);
    });
    usort($added, static fn($a, $b) => strcasecmp($a['name'], $b['name']));

    // Anchor each returner to the post-removal list: below whoever holds their saved rank.
    $count = count($kept);
    $afterIndex = [];
    $atTop = [];
    $reactivated = [];
    foreach ($returning as $entry) {
        $saved = max(1, (int) $entry['last_rank']);
        $row = [
            'rank' => 0,
            'name' => $entry['_name'],
            'race' => $entry['race'] ?? 'R',
        ];
        if ($count === 0) {
            $atTop[] = $row;
            $anchor = null;
        } else {
            $anchorIdx = min($saved, $count) - 1;
            $afterIndex[$anchorIdx][] = $row;
            $anchor = (string) $kept[$anchorIdx]['name'];
        }
        $reactivated[] = [
            'name' => $entry['_name'],
            'saved_rank' => $saved,
            'placed_below' => $anchor,
        ];
        unset($inactiveByKey[$entry['_key']]);
    }

    $merged = $atTop;
    foreach ($kept as $i => $row) {
        $merged[] = $row;
        foreach ($afterIndex[$i] ?? [] as $returner) {
            $merged[] = $returner;
        }
    }
    foreach ($added as $row) {
        $merged[] = ['rank' => 0, 'name' => $row['name'], 'race' => 'R'];
    }

    foreach ($merged as $i => &$row) {
        $row['rank'] = $i + 1;
        $row['group'] = (int) ceil(($i + 1) / 4);
    }
    unset($row);

    $byName = [];
    foreach ($merged as $row) {
        $byName[strtolower((string) $row['name'])] = (int) $row['rank'];
    }
    foreach ($reactivated as &$r) {
        $r['new_rank'] = $byName[strtolower($r['name'])] ?? null;
    }
    unset($r);
    foreach ($added as &$a) {
        $a['new_rank'] = $byName[strtolower($a['name'])] ?? null;
    }
    unset($a);

    $inactiveOut = array_values($inactiveByKey);
    usort($inactiveOut, static fn($a, $b) => (int) $a['last_rank'] <=> (int) $b['last_rank']);

    return [
        'rankings' => $merged,
        'inactive' => $inactiveOut,
        'deactivated' => $deactivated,
        'reactivated' => $reactivated,
        'added' => $added,
        'unmatched' => $unmatched,
        'changed' => $deactivated !== [] || $reactivated !== [] || $added !== [],
    ];
}

/**
 * Plan and (unless $dryRun) write rankings.json + rankings_inactive.json.
 *
 * @return array<string,mixed> plan summary plus success / error / backup
 */
function rankings_roster_sync(PDO $db, bool $dryRun = false): array
{
    $lock = fopen(RANKINGS_ROSTER_LOCK_FILE, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        return ['success' => false, 'error' => 'Could not lock rankings for sync'];
    }

    try {
        $plan = rankings_roster_plan(
            rankings_roster_read_json_list(RANKINGS_ROSTER_RANKINGS_FILE),
            rankings_roster_read_json_list(RANKINGS_ROSTER_INACTIVE_FILE),
            rankings_roster_db_players($db),
            date('c')
        );

        $summary = [
            'success' => true,
            'dry_run' => $dryRun,
            'changed' => $plan['changed'],
            'deactivated' => $plan['deactivated'],
            'reactivated' => $plan['reactivated'],
            'added' => $plan['added'],
            'unmatched' => $plan['unmatched'],
            'active_count' => count($plan['rankings']),
            'inactive_count' => count($plan['inactive']),
        ];

        if ($dryRun || !$plan['changed']) {
            return $summary;
        }

        if (rankings_voting_blocks_canonical_edit(rankings_voting_load_session())) {
            return ['success' => false, 'error' => 'A community vote session is active; sync after it is closed, published, or cancelled.'] + $summary;
        }

        $backup = RANKINGS_ROSTER_RANKINGS_FILE . '.bak.' . date('Ymd_His');
        if (is_file(RANKINGS_ROSTER_RANKINGS_FILE) && !copy(RANKINGS_ROSTER_RANKINGS_FILE, $backup)) {
            return ['success' => false, 'error' => 'Could not back up rankings.json'] + $summary;
        }

        $enriched = enrich_rankings_with_records($plan['rankings'], $db);
        $okRankings = file_put_contents(RANKINGS_ROSTER_RANKINGS_FILE, json_encode($enriched, JSON_PRETTY_PRINT), LOCK_EX) !== false;
        $okInactive = file_put_contents(RANKINGS_ROSTER_INACTIVE_FILE, json_encode($plan['inactive'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
        if (!$okRankings || !$okInactive) {
            return ['success' => false, 'error' => 'Could not write rankings files'] + $summary;
        }

        rankings_roster_log($plan);
        return $summary + ['backup' => basename($backup)];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Follow a Players.Real_Name change in both files; otherwise the next sync sees a new player.
 * Never throws.
 */
function rankings_roster_rename(string $oldName, string $newName): void
{
    $oldKey = strtolower(trim($oldName));
    $newName = trim($newName);
    if ($oldKey === '' || $newName === '' || trim($oldName) === $newName) {
        return;
    }
    $lock = fopen(RANKINGS_ROSTER_LOCK_FILE, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        error_log('rankings roster rename: could not lock');
        return;
    }
    try {
        foreach ([RANKINGS_ROSTER_RANKINGS_FILE, RANKINGS_ROSTER_INACTIVE_FILE] as $path) {
            $rows = rankings_roster_read_json_list($path);
            $hit = false;
            foreach ($rows as &$row) {
                if (strtolower(trim((string) ($row['name'] ?? ''))) === $oldKey) {
                    $row['name'] = $newName;
                    $hit = true;
                }
            }
            unset($row);
            if ($hit) {
                file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT), LOCK_EX);
            }
        }
    } catch (Throwable $e) {
        error_log('rankings roster rename failed: ' . $e->getMessage());
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Call after a Players.Status change. Never throws; returns a short note for the UI or ''. */
function rankings_roster_sync_quiet(PDO $db): string
{
    try {
        $result = rankings_roster_sync($db);
    } catch (Throwable $e) {
        error_log('rankings roster sync failed: ' . $e->getMessage());
        return 'Rankings sync failed; run Sync with roster on the rankings page.';
    }
    if (empty($result['success'])) {
        return 'Rankings not synced: ' . ($result['error'] ?? 'unknown error');
    }
    if (empty($result['changed'])) {
        return '';
    }
    return sprintf(
        'Rankings synced (%d inactive, %d returned, %d added).',
        count($result['deactivated']),
        count($result['reactivated']),
        count($result['added'])
    );
}

/** @param array<string,mixed> $plan */
function rankings_roster_log(array $plan): void
{
    $lines = [];
    foreach ($plan['deactivated'] as $d) {
        $lines[] = "INACTIVE {$d['name']} was #{$d['last_rank']} ({$d['status']})";
    }
    foreach ($plan['reactivated'] as $r) {
        $below = $r['placed_below'] ?? '(top)';
        $lines[] = "RETURNED {$r['name']} saved #{$r['saved_rank']} -> #{$r['new_rank']} below {$below}";
    }
    foreach ($plan['added'] as $a) {
        $lines[] = "ADDED {$a['name']} -> #{$a['new_rank']} (no history)";
    }
    $stamp = '[' . gmdate('Y-m-d\TH:i:s\Z') . '] ';
    file_put_contents(RANKINGS_ROSTER_LOG_FILE, $stamp . implode("\n" . $stamp, $lines) . "\n", FILE_APPEND | LOCK_EX);
}
