<?php

/**
 * Enrich rankings with current season and all-time games (maps) and sets W-L from DB.
 *
 * @param array<int,array<string,mixed>> $rankings
 * @return array<int,array<string,mixed>>
 */
function enrich_rankings_with_records(array $rankings, PDO $db): array
{
    if (empty($rankings)) {
        return $rankings;
    }
    $currentSeason = null;
    try {
        $row = $db->query('SELECT MAX(season) as s FROM fsl_matches')->fetch(PDO::FETCH_ASSOC);
        $currentSeason = $row && isset($row['s']) ? (int) $row['s'] : null;
    } catch (PDOException $e) {
        $currentSeason = null;
    }
    $names = array_unique(array_filter(array_map(static function ($p) {
        return trim((string) ($p['name'] ?? ''));
    }, $rankings)));
    $defaults = ['season_gw' => 0, 'season_gl' => 0, 'season_sw' => 0, 'season_sl' => 0, 'alltime_gw' => 0, 'alltime_gl' => 0, 'alltime_sw' => 0, 'alltime_sl' => 0];
    $records = [];
    $nameToRace = [];
    if (!empty($names)) {
        $racePlaceholders = implode(',', array_fill(0, count($names), '?'));
        $raceStmt = $db->prepare("
            SELECT p.Real_Name, fs.Race
            FROM Players p
            JOIN FSL_STATISTICS fs ON p.Player_ID = fs.Player_ID
            WHERE p.Real_Name IN ($racePlaceholders)
            ORDER BY p.Real_Name, (fs.MapsW + fs.MapsL) DESC, FIELD(fs.Division, 'S', 'A', 'B')
        ");
        $raceStmt->execute(array_values($names));
        while ($row = $raceStmt->fetch(PDO::FETCH_ASSOC)) {
            $realName = trim((string) ($row['Real_Name'] ?? ''));
            if ($realName === '' || isset($nameToRace[$realName])) {
                continue;
            }
            $race = trim((string) ($row['Race'] ?? ''));
            if ($race !== '') {
                $nameToRace[$realName] = $race;
                $nameToRace[strtolower($realName)] = $race;
            }
        }
    }
    if (empty($names) || $currentSeason === null) {
        foreach ($rankings as $i => $p) {
            foreach ($defaults as $k => $v) {
                $rankings[$i][$k] = $v;
            }
            $name = trim((string) ($p['name'] ?? ''));
            $race = $nameToRace[$name] ?? $nameToRace[strtolower($name)] ?? null;
            if ($race !== null) {
                $rankings[$i]['race'] = $race;
            }
            $rank = (int) ($rankings[$i]['rank'] ?? $i + 1);
            $rankings[$i]['group'] = (int) ceil($rank / 4);
        }

        return $rankings;
    }
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $stmt = $db->prepare("SELECT Player_ID, Real_Name FROM Players WHERE Real_Name IN ($placeholders)");
    $stmt->execute(array_values($names));
    $nameToPid = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $nameToPid[trim($row['Real_Name'])] = (int) $row['Player_ID'];
        $nameToPid[strtolower(trim($row['Real_Name']))] = (int) $row['Player_ID'];
    }
    foreach ($names as $name) {
        $pid = $nameToPid[$name] ?? $nameToPid[strtolower($name)] ?? null;
        $rec = $defaults;
        if ($pid !== null) {
            $stmt = $db->prepare('
                SELECT
                    COALESCE(SUM(CASE WHEN winner_player_id = ? THEN 1 ELSE 0 END), 0) as sw,
                    COALESCE(SUM(CASE WHEN loser_player_id = ? THEN 1 ELSE 0 END), 0) as sl,
                    COALESCE(SUM(CASE WHEN winner_player_id = ? THEN map_win ELSE map_loss END), 0) as gw,
                    COALESCE(SUM(CASE WHEN winner_player_id = ? THEN map_loss ELSE map_win END), 0) as gl
                FROM fsl_matches WHERE season = ? AND (winner_player_id = ? OR loser_player_id = ?)
            ');
            $stmt->execute([$pid, $pid, $pid, $pid, $currentSeason, $pid, $pid]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $rec['season_sw'] = (int) ($r['sw'] ?? 0);
            $rec['season_sl'] = (int) ($r['sl'] ?? 0);
            $rec['season_gw'] = (int) ($r['gw'] ?? 0);
            $rec['season_gl'] = (int) ($r['gl'] ?? 0);
            $stmt = $db->prepare('
                SELECT
                    COALESCE(SUM(CASE WHEN winner_player_id = ? THEN 1 ELSE 0 END), 0) as sw,
                    COALESCE(SUM(CASE WHEN loser_player_id = ? THEN 1 ELSE 0 END), 0) as sl,
                    COALESCE(SUM(CASE WHEN winner_player_id = ? THEN map_win ELSE map_loss END), 0) as gw,
                    COALESCE(SUM(CASE WHEN winner_player_id = ? THEN map_loss ELSE map_win END), 0) as gl
                FROM fsl_matches WHERE winner_player_id = ? OR loser_player_id = ?
            ');
            $stmt->execute([$pid, $pid, $pid, $pid, $pid, $pid]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $rec['alltime_sw'] = (int) ($r['sw'] ?? 0);
            $rec['alltime_sl'] = (int) ($r['sl'] ?? 0);
            $rec['alltime_gw'] = (int) ($r['gw'] ?? 0);
            $rec['alltime_gl'] = (int) ($r['gl'] ?? 0);
        }
        $records[$name] = $rec;
        $records[strtolower($name)] = $rec;
    }
    foreach ($rankings as $i => $p) {
        $name = trim((string) ($p['name'] ?? ''));
        $rec = $records[$name] ?? $records[strtolower($name)] ?? $defaults;
        foreach ($defaults as $k => $v) {
            $rankings[$i][$k] = $rec[$k];
        }
        $race = $nameToRace[$name] ?? $nameToRace[strtolower($name)] ?? null;
        if ($race !== null) {
            $rankings[$i]['race'] = $race;
        }
        $rank = (int) ($rankings[$i]['rank'] ?? $i + 1);
        $rankings[$i]['group'] = (int) ceil($rank / 4);
    }

    return $rankings;
}
