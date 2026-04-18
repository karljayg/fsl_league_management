<?php
/**
 * Resolve ladder rank / group from rankings/rankings.json by player name.
 */

declare(strict_types=1);

require_once __DIR__ . '/map_veto_constants.php';

/**
 * Decode rankings JSON once per request (cached).
 *
 * @return list<array<string, mixed>>
 */
function map_veto_load_rankings_rows(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $path = MAP_VETO_RANKINGS_FILE;
    if (!is_readable($path)) {
        $cache = [];

        return $cache;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        $cache = [];

        return $cache;
    }
    $rows = json_decode($raw, true);
    $cache = is_array($rows) ? $rows : [];

    return $cache;
}

/**
 * Match is case-insensitive on trimmed names (same as ladder export).
 *
 * @return array{name: string, rank: int, group: int|null}|null
 */
function map_veto_lookup_ranking_full_by_name(string $name): ?array
{
    $needle = mb_strtolower(trim($name));
    if ($needle === '') {
        return null;
    }
    foreach (map_veto_load_rankings_rows() as $row) {
        if (!isset($row['name'], $row['rank'])) {
            continue;
        }
        if (mb_strtolower(trim((string) $row['name'])) === $needle) {
            $g = null;
            if (array_key_exists('group', $row) && $row['group'] !== null && $row['group'] !== '') {
                $g = (int) $row['group'];
            }

            return [
                'name' => (string) $row['name'],
                'rank' => (int) $row['rank'],
                'group' => $g,
            ];
        }
    }

    return null;
}

/**
 * @return array{name: string, rank: int}|null
 */
function map_veto_lookup_ranking_by_name(string $name): ?array
{
    $full = map_veto_lookup_ranking_full_by_name($name);
    if ($full === null) {
        return null;
    }

    return ['name' => $full['name'], 'rank' => $full['rank']];
}
