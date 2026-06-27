<?php
/**
 * Lineup Planner – Captains pick Team A vs Team B; each slot shows allowed opponents by ranking rule.
 * Reads fsl/rankings (rankings.json). Rule: player can play up to N groups above/below (default 2).
 * Group = ceil(rank / playersPerGroup); default 4 players per group.
 */
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/season_utils.php';
require_once __DIR__ . '/includes/map_veto_store.php';

/**
 * Resolve enabled maps for a FSL season (season_fsl_N → season_fsl_10 → default).
 *
 * @return list<array{id: string, name: string}>
 */
function lineup_resolve_map_pool(int $season): array
{
    $seasons = map_veto_load_seasons();
    $maps = map_veto_load_maps();
    $mapById = [];
    foreach ($maps as $m) {
        $id = isset($m['id']) ? (string) $m['id'] : '';
        if ($id !== '' && ($m['is_active'] ?? true)) {
            $mapById[$id] = $m;
        }
    }
    $seasonIds = ['season_fsl_' . $season, 'season_fsl_10', 'season_fsl_default'];
    $enabledIds = [];
    foreach ($seasonIds as $sid) {
        foreach ($seasons as $s) {
            if (($s['id'] ?? '') === $sid && !empty($s['enabled_map_ids']) && is_array($s['enabled_map_ids'])) {
                $enabledIds = $s['enabled_map_ids'];
                break 2;
            }
        }
    }
    $pool = [];
    foreach ($enabledIds as $id) {
        $id = (string) $id;
        if (!isset($mapById[$id])) {
            continue;
        }
        $pool[] = [
            'id' => $id,
            'name' => (string) ($mapById[$id]['name'] ?? $id),
        ];
    }
    usort($pool, static function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    return $pool;
}

$pageTitle = 'Lineup Planner';
$rankingsFile = __DIR__ . '/rankings/rankings.json';
$rankings = [];
if (file_exists($rankingsFile)) {
    $rankings = json_decode(file_get_contents($rankingsFile), true) ?? [];
}

$teamsQuery = "
    SELECT t.Team_Name, p.Real_Name AS Player_Name
    FROM Teams t
    LEFT JOIN Players p ON p.Team_ID = t.Team_ID AND p.Status = 'active'
    WHERE COALESCE(t.Status, 'active') = 'active'
    ORDER BY t.Team_Name, p.Real_Name
";
$teams = [];
try {
    $rows = $db->query($teamsQuery)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $tn = $row['Team_Name'];
        if (!isset($teams[$tn])) {
            $teams[$tn] = [];
        }
        if (!empty($row['Player_Name'])) {
            $teams[$tn][] = trim($row['Player_Name']);
        }
    }
    $teams = array_map('array_values', $teams);
    ksort($teams, SORT_FLAG_CASE | SORT_STRING);
} catch (PDOException $e) {
    // leave $teams empty
}

$teamNames = array_keys($teams);

/**
 * Match a URL team param to a canonical roster team name (case-insensitive).
 */
function lineup_resolve_team_param(string $raw, array $teamNames): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    foreach ($teamNames as $name) {
        if (strcasecmp($name, $raw) === 0) {
            return $name;
        }
    }
    return '';
}

/** @var array<int, string> */
$teamNameById = [];
/** @var array<string, int> */
$teamIdByName = [];
try {
    $teamIdRows = $db->query('SELECT Team_ID, Team_Name FROM Teams')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($teamIdRows as $row) {
        $tid = (int) ($row['Team_ID'] ?? 0);
        $tname = trim((string) ($row['Team_Name'] ?? ''));
        if ($tid < 1 || $tname === '') {
            continue;
        }
        $teamNameById[$tid] = $tname;
        $teamIdByName[$tname] = $tid;
    }
} catch (PDOException $e) {
    $teamNameById = [];
    $teamIdByName = [];
}

/**
 * teamA/teamB URL params accept Team_ID (preferred) or team name (legacy links).
 */
function lineup_resolve_team_from_url(string $raw, array $teamNames, array $teamNameById): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (ctype_digit($raw)) {
        $tid = (int) $raw;
        return $teamNameById[$tid] ?? '';
    }
    return lineup_resolve_team_param($raw, $teamNames);
}

/** @var array<int, string> */
$playerNameById = [];
/** @var array<string, int> */
$playerIdByName = [];
try {
    $playerIdRows = $db->query("SELECT Player_ID, Real_Name FROM Players WHERE Status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($playerIdRows as $row) {
        $pid = (int) ($row['Player_ID'] ?? 0);
        $pname = trim((string) ($row['Real_Name'] ?? ''));
        if ($pid < 1 || $pname === '') {
            continue;
        }
        $playerNameById[$pid] = $pname;
        $playerIdByName[$pname] = $pid;
    }
} catch (PDOException $e) {
    $playerNameById = [];
    $playerIdByName = [];
}

$urlTeamA = lineup_resolve_team_from_url((string) ($_GET['teamA'] ?? $_GET['team_a'] ?? ''), $teamNames, $teamNameById);
$urlTeamB = lineup_resolve_team_from_url((string) ($_GET['teamB'] ?? $_GET['team_b'] ?? ''), $teamNames, $teamNameById);
$urlRule = isset($_GET['rule']) ? max(0, min(20, (int) $_GET['rule'])) : 2;
$urlPlayersPerGroup = isset($_GET['playersPerGroup']) ? max(1, min(20, (int) $_GET['playersPerGroup'])) : 4;
$urlDescription = trim((string) ($_GET['description'] ?? ''));
$urlSlots = [];
if (!empty($_GET['slots'])) {
    $decodedSlots = json_decode((string) $_GET['slots'], true);
    if (is_array($decodedSlots)) {
        $urlSlots = array_slice($decodedSlots, 0, 20);
    }
}
$urlHasLineupState = ($urlTeamA !== '' || $urlTeamB !== '' || !empty($urlSlots));

/**
 * Human-readable label for a schedule week option.
 */
function lineup_format_week_label(int $season, ?string $matchDateStr, int $weekNum): string
{
    $base = 'Week ' . $weekNum;
    if (empty($matchDateStr)) {
        return $base . ' · TBD';
    }
    if ((int) $season >= 11) {
        $sat = new DateTime($matchDateStr);
        $fri = clone $sat;
        $fri->modify('-1 day');
        return $base . ' · Fri ' . $fri->format('M j') . ' · Sat ' . $sat->format('M j, Y');
    }
    $dt = new DateTime($matchDateStr);
    return $base . ' · ' . $dt->format('M j, Y g:i A') . ' ET';
}

/** @var array<int, list<array<string, mixed>>> */
$scheduleWeeksBySeason = [];
try {
    $stmt = $db->query("
        SELECT s.schedule_id, s.season, s.week_number, s.match_date, s.status, s.notes,
               COALESCE(t1.Team_Name, 'TBD') AS team1_name,
               COALESCE(t2.Team_Name, 'TBD') AS team2_name
        FROM fsl_schedule s
        LEFT JOIN Teams t1 ON s.team1_id = t1.Team_ID
        LEFT JOIN Teams t2 ON s.team2_id = t2.Team_ID
        ORDER BY s.season DESC, s.week_number ASC
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $season = (int) ($row['season'] ?? 0);
        $weekNum = (int) ($row['week_number'] ?? 0);
        if ($season < 1 || $weekNum < 1) {
            continue;
        }
        $matchDateStr = isset($row['match_date']) ? (string) $row['match_date'] : '';
        $dateYmd = ($matchDateStr !== '') ? date('Y-m-d', strtotime($matchDateStr)) : '';
        $entry = [
            'schedule_id' => (int) ($row['schedule_id'] ?? 0),
            'week_number' => $weekNum,
            'date' => $dateYmd,
            'status' => (string) ($row['status'] ?? 'scheduled'),
            'team1_name' => (string) ($row['team1_name'] ?? 'TBD'),
            'team2_name' => (string) ($row['team2_name'] ?? 'TBD'),
            'label' => lineup_format_week_label($season, $matchDateStr !== '' ? $matchDateStr : null, $weekNum),
            'notes' => (string) ($row['notes'] ?? ''),
        ];
        if ($season >= 11 && $matchDateStr !== '') {
            $sat = new DateTime($matchDateStr);
            $fri = clone $sat;
            $fri->modify('-1 day');
            $entry['fri_short'] = $fri->format('M j');
            $entry['sat_short'] = $sat->format('M j, Y');
            $entry['fri_long'] = $fri->format('l, F j');
            $entry['sat_long'] = $sat->format('l, F j, Y');
        } elseif ($matchDateStr !== '') {
            $dt = new DateTime($matchDateStr);
            $entry['match_long'] = $dt->format('l, F j, Y \a\t g:i A');
        }
        if (!isset($scheduleWeeksBySeason[$season])) {
            $scheduleWeeksBySeason[$season] = [];
        }
        $scheduleWeeksBySeason[$season][] = $entry;
    }
} catch (PDOException $e) {
    $scheduleWeeksBySeason = [];
}

$currentSeason = getCurrentSeason($db);
$seasons = [];
try {
    $stmt = $db->query("SELECT DISTINCT season FROM fsl_schedule ORDER BY season DESC");
    $seasons = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $seasons = [$currentSeason];
}
if (empty($seasons)) {
    $seasons = [$currentSeason];
}

require_once __DIR__ . '/includes/fsl_schedule_permission.php';
// Send button: admin always; else fsl_manager or manage fsl schedule permission
$canSendToSchedule = false;
$lineupSendKey = '';
if (isset($_SESSION['user_id'])) {
    try {
        $canSendToSchedule = fsl_can_manage_schedule($db, $_SESSION['user_id']);
        if ($canSendToSchedule) {
            $lineupSendKey = fsl_lineup_send_key();
        }
    } catch (PDOException $e) {
        $canSendToSchedule = false;
    }
}

$urlSeason = isset($_GET['season']) ? (int) $_GET['season'] : 0;
$urlWeekRaw = isset($_GET['week']) ? trim((string) $_GET['week']) : '';
$urlCustom = (strtolower($urlWeekRaw) === 'custom');
$urlWeek = (!$urlCustom && $urlWeekRaw !== '' && ctype_digit($urlWeekRaw)) ? (int) $urlWeekRaw : 0;
$lineupSeason = $currentSeason;
if ($urlSeason > 0 && in_array($urlSeason, array_map('intval', $seasons), true)) {
    $lineupSeason = $urlSeason;
} elseif ($urlWeek > 0) {
    $seasonSearch = array_unique(array_merge([$currentSeason], array_map('intval', $seasons)));
    foreach ($seasonSearch as $s) {
        $weeks = $scheduleWeeksBySeason[$s] ?? $scheduleWeeksBySeason[(string) $s] ?? [];
        foreach ($weeks as $w) {
            if ((int) ($w['week_number'] ?? 0) === $urlWeek) {
                $lineupSeason = (int) $s;
                break 2;
            }
        }
    }
}

$mapPool = lineup_resolve_map_pool($lineupSeason);

require_once __DIR__ . '/includes/team_logo.php';
$teamLogos = [];
foreach ($teamNames as $name) {
    $logo = getTeamLogo($name);
    $teamLogos[$name] = $logo ?: '';
}
$raceIcons = ['T' => 'terran_icon.png', 'P' => 'protoss_icon.png', 'Z' => 'zerg_icon.png', 'R' => 'random_icon.png'];
include_once __DIR__ . '/includes/header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&family=Exo+2:wght@400;600;700&display=swap" rel="stylesheet">
<style>
.lineup-planner-wrapper { background: linear-gradient(135deg, #0a0a0f 0%, #1a1a2e 50%, #0a0a0f 100%); min-height: 100vh; color: #fff; padding-bottom: 2rem; }
.lineup-planner { max-width: 980px; margin: 0 auto; padding: 0 1rem; }
.lineup-planner .lineup-header { text-align: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid rgba(108, 92, 231, 0.3); }
.lineup-planner .lineup-header h1 { font-family: 'Rajdhani', sans-serif; font-size: 2.2rem; font-weight: 700; background: linear-gradient(135deg, #6c5ce7, #a29bfe); -webkit-background-clip: text; -webkit-text-fill-color: transparent; text-transform: uppercase; letter-spacing: 2px; margin: 0; }
.lineup-planner .help-text { font-size: 0.9rem; color: #888; margin-top: 0.5rem; max-width: 720px; margin-left: auto; margin-right: auto; }
.lineup-planner .card { background: rgba(0, 0, 0, 0.3); border-radius: 10px; padding: 1.25rem; margin-bottom: 1rem; border: 1px solid rgba(108, 92, 231, 0.2); }
.lineup-planner .section-title { font-family: 'Rajdhani', sans-serif; font-size: 1.1rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin: 0 0 1rem; color: #e0e0e0; }
.lineup-planner label { font-weight: 500; color: #aaa; }
.lineup-planner .form-row { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; margin-bottom: 0.75rem; justify-content: center; }
.lineup-planner .form-row:last-child { margin-bottom: 0; }
.lineup-planner input[type="date"],
.lineup-planner input[type="text"],
.lineup-planner select,
.lineup-planner input[type="number"] {
    padding: 0.45rem 0.55rem;
    background: rgba(0,0,0,0.35);
    border: 1px solid rgba(255,255,255,0.15);
    color: #fff;
    border-radius: 6px;
    color-scheme: dark;
}
.lineup-planner input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1); opacity: 0.7; }
.lineup-planner .settings-row { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.06); }
.lineup-planner .rules-row { margin-top: 0.5rem; padding-top: 0.75rem; border-top: 1px solid rgba(255,255,255,0.06); }
.lineup-planner .settings-row label,
.lineup-planner .rules-row label { color: #666; font-size: 0.9rem; }
.lineup-planner .schedule-first-row { display: grid; grid-template-columns: auto 1fr; gap: 0.75rem 1rem; align-items: center; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(255,255,255,0.08); }
.lineup-planner .schedule-first-row label { font-family: 'Rajdhani', sans-serif; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #aaa; min-width: 4rem; }
.lineup-planner .schedule-first-row select { width: 100%; max-width: none; font-size: 0.95rem; }
.lineup-planner .custom-description-row { display: none; grid-column: 1 / -1; gap: 0.75rem 1rem; align-items: center; margin-top: 0.25rem; }
.lineup-planner .custom-description-row.is-visible { display: grid; grid-template-columns: auto 1fr; }
.lineup-planner .custom-description-row input[type="text"] { width: 100%; font-size: 0.95rem; }
.lineup-planner .custom-description-hint { grid-column: 2; font-size: 0.8rem; color: #666; margin: -0.25rem 0 0; }
.lineup-planner .team-setup.teams-locked select { pointer-events: none; background: rgba(0,0,0,0.2); color: #ccc; }
.lineup-planner .teams-from-schedule-hint { display: none; text-align: center; font-size: 0.8rem; color: #666; margin: -0.35rem 0 0.75rem; }
.lineup-planner .teams-from-schedule-hint.is-visible { display: block; }
.lineup-planner .settings-row input[type="number"],
.lineup-planner .rules-row input[type="number"] { width: 60px; background: rgba(0,0,0,0.2); border-color: rgba(255,255,255,0.1); color: #888; }
.lineup-planner .settings-hint { color: #555; font-size: 0.8rem; margin-left: 0.25rem; }

/* Team setup — left purple (A), right teal (B) */
.lineup-planner .team-setup { display: grid; grid-template-columns: 1fr auto 1fr; gap: 0.75rem; align-items: stretch; }
.lineup-planner .team-setup-side { border-radius: 10px; padding: 1rem; display: flex; flex-direction: column; align-items: center; gap: 0.65rem; min-width: 0; }
.lineup-planner .team-setup-side.team-a-side { background: rgba(108, 92, 231, 0.14); border: 1px solid rgba(108, 92, 231, 0.35); }
.lineup-planner .team-setup-side.team-b-side { background: rgba(0, 184, 148, 0.1); border: 1px solid rgba(0, 184, 148, 0.35); }
.lineup-planner .team-setup-side .side-label { font-family: 'Rajdhani', sans-serif; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; }
.lineup-planner .team-a-side .side-label { color: #a29bfe; }
.lineup-planner .team-b-side .side-label { color: #00d4aa; }
.lineup-planner .team-logo-box img { width: 52px; height: 52px; object-fit: contain; border-radius: 8px; }
.lineup-planner .team-logo-label { font-size: 0.9rem; color: #ccc; text-align: center; line-height: 1.2; font-weight: 600; }
.lineup-planner .team-setup-side select { width: 100%; max-width: 240px; }
.lineup-planner .team-setup .vs-divider { align-self: center; color: #666; font-weight: 700; font-family: 'Rajdhani', sans-serif; font-size: 1.2rem; }
.lineup-planner .share-link-row { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; justify-content: center; margin-top: 1rem; padding-top: 0.85rem; border-top: 1px solid rgba(255,255,255,0.06); }
.lineup-planner .share-link-row input { flex: 1; min-width: 200px; max-width: 100%; font-size: 0.8rem; color: #888; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; padding: 0.4rem 0.55rem; }
.lineup-planner .btn-copy-link { padding: 0.4rem 0.85rem; background: rgba(108, 92, 231, 0.25); border: 1px solid rgba(108, 92, 231, 0.45); border-radius: 6px; color: #a29bfe; font-size: 0.85rem; cursor: pointer; white-space: nowrap; }
.lineup-planner .btn-copy-link:hover { background: rgba(108, 92, 231, 0.4); color: #fff; }

/* Broadcast board */
.lineup-planner .broadcast-board { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.lineup-planner .day-column { border-radius: 10px; padding: 1rem; min-height: 180px; background: rgba(0,0,0,0.25); }
.lineup-planner .day-column.friday { border: 1px solid rgba(0, 184, 148, 0.35); box-shadow: inset 0 3px 0 #00b894; }
.lineup-planner .day-column.saturday { border: 1px solid rgba(85, 163, 255, 0.35); box-shadow: inset 0 3px 0 #55a3ff; }
.lineup-planner .day-column-header { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 0.75rem; gap: 0.5rem; }
.lineup-planner .day-column-header h6 { font-family: 'Rajdhani', sans-serif; font-size: 1rem; font-weight: 700; margin: 0; text-transform: uppercase; letter-spacing: 1px; }
.lineup-planner .day-column.friday .day-column-header h6 { color: #00b894; }
.lineup-planner .day-column.saturday .day-column-header h6 { color: #55a3ff; }
.lineup-planner .day-column-header .day-time { font-size: 0.75rem; color: #666; }
.lineup-planner .day-column-header .day-count { font-size: 0.75rem; color: #888; background: rgba(255,255,255,0.06); padding: 0.15rem 0.5rem; border-radius: 10px; }
.lineup-planner .day-matchups { display: flex; flex-direction: column; gap: 0.6rem; }
.lineup-planner .day-empty { color: #555; font-size: 0.85rem; font-style: italic; text-align: center; padding: 1.5rem 0.5rem; border: 1px dashed rgba(255,255,255,0.08); border-radius: 8px; }

/* Matchup cards on board */
.lineup-planner .matchup-card { border-radius: 8px; overflow: hidden; border: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.35); transition: border-color 0.15s, box-shadow 0.15s; }
.lineup-planner .matchup-card.is-editing { border-color: rgba(255, 193, 7, 0.6); box-shadow: 0 0 0 1px rgba(255, 193, 7, 0.25); }
.lineup-planner .matchup-card-head { display: flex; align-items: center; justify-content: space-between; padding: 0.35rem 0.6rem; background: rgba(255,255,255,0.04); border-bottom: 1px solid rgba(255,255,255,0.06); }
.lineup-planner .matchup-card-head .type-badge { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #888; }
.lineup-planner .matchup-card-actions { display: flex; gap: 0.25rem; align-items: center; }
.lineup-planner .matchup-card-actions button { border: none; background: transparent; color: #888; cursor: pointer; padding: 0.2rem 0.45rem; border-radius: 4px; font-size: 0.8rem; }
.lineup-planner .matchup-card-actions button:hover:not(:disabled) { background: rgba(255,255,255,0.08); color: #fff; }
.lineup-planner .matchup-card-actions button:disabled { opacity: 0.25; cursor: default; }
.lineup-planner .matchup-card-actions .btn-delete:hover:not(:disabled) { color: #ff7675; }
.lineup-planner .matchup-card-actions .btn-move-up,
.lineup-planner .matchup-card-actions .btn-move-down { font-size: 0.72rem; padding: 0.2rem 0.35rem; }
.lineup-planner .matchup-card-body { display: grid; grid-template-columns: 1fr auto 1fr; gap: 0; align-items: stretch; }
.lineup-planner .matchup-side { padding: 0.55rem 0.65rem; min-width: 0; }
.lineup-planner .matchup-side.side-a { background: rgba(108, 92, 231, 0.12); border-right: 1px solid rgba(108, 92, 231, 0.2); }
.lineup-planner .matchup-side.side-b { background: rgba(0, 184, 148, 0.08); border-left: 1px solid rgba(0, 184, 148, 0.2); }
.lineup-planner .matchup-side .player-name { font-weight: 600; font-size: 0.88rem; line-height: 1.3; word-break: break-word; }
.lineup-planner .matchup-side .player-meta { display: flex; align-items: center; gap: 0.3rem; flex-wrap: wrap; margin-top: 0.25rem; }
.lineup-planner .matchup-vs { display: flex; align-items: center; justify-content: center; color: #666; font-weight: 700; font-family: 'Rajdhani', sans-serif; font-size: 0.85rem; padding: 0 0.25rem; }
.lineup-planner .matchup-card-maps { padding: 0.35rem 0.65rem; font-size: 0.75rem; color: #888; border-top: 1px solid rgba(255,255,255,0.06); background: rgba(0,0,0,0.2); }
.lineup-planner .rank-badge { width: 22px; height: 22px; display: inline-flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #6c5ce7, #a29bfe); border-radius: 50%; font-family: 'Rajdhani', sans-serif; font-size: 0.7rem; font-weight: 700; color: #fff; flex-shrink: 0; }
.lineup-planner .race-icon { width: 18px; height: 18px; flex-shrink: 0; }
.lineup-planner .group-badge { font-size: 0.68rem; color: #a29bfe; background: rgba(108, 92, 231, 0.25); padding: 0.1rem 0.4rem; border-radius: 10px; }

/* Editor */
.lineup-planner .editor-card { border-color: rgba(255,255,255,0.12); }
.lineup-planner .editor-card.is-editing-mode { border-color: rgba(255, 193, 7, 0.4); }
.lineup-planner .editor-meta { display: flex; flex-wrap: wrap; gap: 0.75rem 1.25rem; align-items: center; margin-bottom: 1rem; padding-bottom: 0.85rem; border-bottom: 1px solid rgba(255,255,255,0.06); }
.lineup-planner .editor-meta .meta-group { display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem; }
.lineup-planner .editor-meta .meta-label { font-size: 0.75rem; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-right: 0.15rem; }
.lineup-planner .segmented { display: inline-flex; border-radius: 6px; overflow: hidden; border: 1px solid rgba(255,255,255,0.15); }
.lineup-planner .segmented button { border: none; background: rgba(0,0,0,0.3); color: #aaa; padding: 0.35rem 0.75rem; font-size: 0.85rem; cursor: pointer; }
.lineup-planner .segmented button.active { background: rgba(108, 92, 231, 0.45); color: #fff; }
.lineup-planner .segmented.day-pick button.active.friday-active { background: rgba(0, 184, 148, 0.35); color: #00d4aa; }
.lineup-planner .segmented.day-pick button.active.saturday-active { background: rgba(85, 163, 255, 0.35); color: #55a3ff; }
.lineup-planner .editor-players { display: grid; grid-template-columns: 1fr auto 1fr; gap: 0.5rem; align-items: start; margin-bottom: 0.75rem; }
.lineup-planner .editor-zone { border-radius: 8px; padding: 0.75rem; min-width: 0; }
.lineup-planner .editor-zone.zone-a { background: rgba(108, 92, 231, 0.14); border: 1px solid rgba(108, 92, 231, 0.3); }
.lineup-planner .editor-zone.zone-b { background: rgba(0, 184, 148, 0.1); border: 1px solid rgba(0, 184, 148, 0.3); }
.lineup-planner .editor-zone .zone-title { font-family: 'Rajdhani', sans-serif; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 0.5rem; }
.lineup-planner .zone-a .zone-title { color: #a29bfe; }
.lineup-planner .zone-b .zone-title { color: #00d4aa; }
.lineup-planner .editor-zone select { width: 100%; margin-bottom: 0.4rem; }
.lineup-planner .editor-zone .player-info { min-height: 26px; display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap; }
.lineup-planner .editor-vs { align-self: center; color: #666; font-weight: 700; font-family: 'Rajdhani', sans-serif; padding-top: 1.5rem; }
.lineup-planner .editor-maps { display: flex; flex-wrap: wrap; gap: 0.5rem 1rem; align-items: center; margin-bottom: 0.85rem; }
.lineup-planner .editor-maps select { min-width: 150px; max-width: 200px; }
.lineup-planner .editor-maps select:disabled { opacity: 0.55; cursor: not-allowed; color: #888; }
.lineup-planner .editor-maps label { font-size: 0.8rem; color: #666; margin: 0; }
.lineup-planner .editor-warning { font-size: 0.85rem; color: #f0ad4e; margin-bottom: 0.75rem; display: none; }
.lineup-planner .editor-warning.is-visible { display: block; }
.lineup-planner .editor-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
.lineup-planner .btn-add-matchup { padding: 0.55rem 1.1rem; background: linear-gradient(135deg, #6c5ce7, #a29bfe); border: none; border-radius: 6px; color: #fff; font-weight: 600; cursor: pointer; }
.lineup-planner .btn-add-matchup:hover { opacity: 0.95; }
.lineup-planner .btn-cancel-edit { padding: 0.55rem 1rem; background: transparent; border: 1px solid rgba(255,255,255,0.25); border-radius: 6px; color: #aaa; cursor: pointer; }
.lineup-planner .btn-cancel-edit:hover { color: #fff; border-color: rgba(255,255,255,0.4); }
.lineup-planner .editor-hint { font-size: 0.8rem; color: #555; margin-left: auto; }

.lineup-planner .lineup-output-actions { display: flex; gap: 0.75rem; margin-top: 0.5rem; flex-wrap: wrap; }
.lineup-planner .btn-download { padding: 0.6rem 1.25rem; background: linear-gradient(135deg, #6c5ce7, #a29bfe); border: none; border-radius: 5px; color: #fff; font-weight: 600; cursor: pointer; }
.lineup-planner .btn-outline-light { background: transparent; border: 1px solid rgba(255,255,255,0.4); color: #e0e0e0; padding: 0.6rem 1.25rem; border-radius: 5px; cursor: pointer; }
.lineup-planner .btn-outline-light:hover { background: rgba(255,255,255,0.1); color: #fff; }
.lineup-planner .btn-send-schedule { padding: 0.6rem 1.25rem; background: linear-gradient(135deg, #00b894, #55efc4); border: none; border-radius: 5px; color: #0a0a0f; font-weight: 700; cursor: pointer; }
.lineup-planner .btn-send-schedule:hover { opacity: 0.92; color: #0a0a0f; }
.lineup-planner .btn-send-schedule:disabled { opacity: 0.5; cursor: not-allowed; }
.lineup-planner .send-schedule-status { margin-top: 0.75rem; font-size: 0.9rem; color: #aaa; min-height: 1.25rem; }
.lineup-planner .send-schedule-status.is-success { color: #55efc4; }
.lineup-planner .send-schedule-status.is-error { color: #ff7675; }

@media (max-width: 768px) {
    .lineup-planner .schedule-first-row { grid-template-columns: 1fr; }
    .lineup-planner .schedule-first-row label { min-width: 0; }
    .lineup-planner .team-setup { grid-template-columns: 1fr; }
    .lineup-planner .team-setup .vs-divider { text-align: center; }
    .lineup-planner .broadcast-board { grid-template-columns: 1fr; }
    .lineup-planner .editor-players { grid-template-columns: 1fr; }
    .lineup-planner .editor-vs { padding: 0; text-align: center; }
    .lineup-planner .matchup-card-body { grid-template-columns: 1fr; }
    .lineup-planner .matchup-vs { padding: 0.25rem; }
    .lineup-planner .matchup-side.side-a { border-right: none; border-bottom: 1px solid rgba(108, 92, 231, 0.2); }
    .lineup-planner .matchup-side.side-b { border-left: none; }
    .lineup-planner .editor-hint { margin-left: 0; width: 100%; }
}
</style>

<div class="lineup-planner-wrapper">
<div class="lineup-planner">
    <div class="lineup-header">
        <h1>Lineup Planner</h1>
        <p class="help-text">Pick the season and schedule week first — teams auto-fill from the match. Choose <strong>Custom</strong> for unscheduled lineups and pick teams manually. Left is Team A, right is Team B. Assign matchups to Friday or Saturday on the board below (<a href="rankings" target="_new">rankings</a> group rule applies).</p>
    </div>

    <div class="card">
        <div class="section-title">Match Setup</div>
        <div class="schedule-first-row">
            <label for="lineupSeason">Season</label>
            <select id="lineupSeason" title="Season for records">
                <?php foreach ($seasons as $s): ?>
                    <option value="<?= (int) $s ?>" <?= (int) $s === (int) $lineupSeason ? 'selected' : '' ?>><?= (int) $s ?><?= (int) $s === (int) $currentSeason ? ' (current)' : '' ?></option>
                <?php endforeach; ?>
            </select>
            <label for="lineupWeek">Week</label>
            <select id="lineupWeek" title="Schedule week for this lineup">
                <option value="">— Select week —</option>
            </select>
            <input type="hidden" id="lineupDate" value="">
            <div class="custom-description-row" id="customDescriptionRow">
                <label for="lineupCustomDescription">Description</label>
                <input type="text" id="lineupCustomDescription" maxlength="100" placeholder="e.g. Showmatch · Team League exhibition" value="<?= $urlCustom ? htmlspecialchars($urlDescription) : '' ?>">
                <p class="custom-description-hint">Required for Custom when sending to the matches DB — used as Season Extra Info and Notes.</p>
            </div>
        </div>
        <p class="teams-from-schedule-hint" id="teamsFromScheduleHint">Teams set from schedule — switch Week to Custom to change.</p>
        <div class="team-setup" id="teamSetup">
            <div class="team-setup-side team-a-side">
                <span class="side-label">Team A · Left</span>
                <div class="team-logo-box">
                    <img id="teamALogo" src="" alt="" style="display: none;">
                    <span class="team-logo-label" id="teamALabel">Select team</span>
                </div>
                <select id="teamA" aria-label="Team A">
                    <option value="">— Select team —</option>
                    <?php foreach ($teamNames as $name): ?>
                        <option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <span class="vs-divider">vs</span>
            <div class="team-setup-side team-b-side">
                <span class="side-label">Team B · Right</span>
                <div class="team-logo-box">
                    <img id="teamBLogo" src="" alt="" style="display: none;">
                    <span class="team-logo-label" id="teamBLabel">Select team</span>
                </div>
                <select id="teamB" aria-label="Team B">
                    <option value="">— Select team —</option>
                    <?php foreach ($teamNames as $name): ?>
                        <option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="share-link-row">
            <input type="text" id="shareLinkInput" readonly aria-label="Shareable lineup planner link">
            <button type="button" class="btn-copy-link" id="btnCopyLink" title="Copy link with full lineup state">Copy link</button>
        </div>
        <div class="form-row rules-row">
            <label for="rule">Group rule<span class="settings-hint">(± groups)</span></label>
            <input type="number" id="rule" min="0" max="20" value="<?= (int) $urlRule ?>" title="Player can play up to this many groups above or below">
            <label for="playersPerGroup">Players per group</label>
            <input type="number" id="playersPerGroup" min="1" max="20" value="<?= (int) $urlPlayersPerGroup ?>" title="Group size for ranking">
        </div>
    </div>

    <div class="card">
        <div class="section-title">Broadcast Schedule</div>
        <div class="broadcast-board">
            <div class="day-column friday">
                <div class="day-column-header">
                    <div>
                        <h6>Friday</h6>
                        <span class="day-time" id="fridayDateLabel">7:00 PM ET</span>
                    </div>
                    <span class="day-count" id="fridayCount">0 matchups</span>
                </div>
                <div class="day-matchups" id="fridayBoard">
                    <div class="day-empty">No Friday matchups yet</div>
                </div>
            </div>
            <div class="day-column saturday">
                <div class="day-column-header">
                    <div>
                        <h6>Saturday</h6>
                        <span class="day-time" id="saturdayDateLabel">12:00 PM ET</span>
                    </div>
                    <span class="day-count" id="saturdayCount">0 matchups</span>
                </div>
                <div class="day-matchups" id="saturdayBoard">
                    <div class="day-empty">No Saturday matchups yet</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card editor-card" id="editorCard">
        <div class="section-title" id="editorTitle">Add Matchup</div>
        <div class="editor-meta">
            <div class="meta-group">
                <span class="meta-label">Type</span>
                <div class="segmented" id="editorTypeSeg">
                    <button type="button" class="active" data-type="1vs1">1v1</button>
                    <button type="button" data-type="2v2">2v2</button>
                </div>
            </div>
            <div class="meta-group">
                <span class="meta-label">Day</span>
                <div class="segmented day-pick" id="editorDaySeg">
                    <button type="button" data-day="friday">Friday</button>
                    <button type="button" data-day="saturday">Saturday</button>
                </div>
            </div>
        </div>
        <div class="editor-players">
            <div class="editor-zone zone-a" id="editorZoneA">
                <div class="zone-title" id="editorZoneATitle">Team A</div>
                <div class="editor-1v1">
                    <select class="editor-player-a" aria-label="Team A player"><option value="">— Player —</option></select>
                    <div class="player-info editor-info-a"></div>
                </div>
                <div class="editor-2v2" style="display:none;">
                    <select class="editor-player-a1" aria-label="Team A player 1"><option value="">— Player 1 —</option></select>
                    <div class="player-info editor-info-a1"></div>
                    <select class="editor-player-a2" aria-label="Team A player 2"><option value="">— Player 2 —</option></select>
                    <div class="player-info editor-info-a2"></div>
                </div>
            </div>
            <span class="editor-vs">vs</span>
            <div class="editor-zone zone-b" id="editorZoneB">
                <div class="zone-title" id="editorZoneBTitle">Team B</div>
                <div class="editor-1v1">
                    <select class="editor-player-b" aria-label="Team B player"><option value="">— Player —</option></select>
                    <div class="player-info editor-info-b"></div>
                </div>
                <div class="editor-2v2" style="display:none;">
                    <select class="editor-player-b1" aria-label="Team B player 1"><option value="">— Player 1 —</option></select>
                    <div class="player-info editor-info-b1"></div>
                    <select class="editor-player-b2" aria-label="Team B player 2"><option value="">— Player 2 —</option></select>
                    <div class="player-info editor-info-b2"></div>
                </div>
            </div>
        </div>
        <div class="editor-maps">
            <label for="editorMap1">Map 1</label>
            <select id="editorMap1" aria-label="Map 1">
                <option value="">— Optional —</option>
                <?php foreach ($mapPool as $map): ?>
                    <option value="<?= htmlspecialchars($map['id']) ?>"><?= htmlspecialchars($map['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="editorMap2">Map 2</label>
            <select id="editorMap2" aria-label="Map 2">
                <option value="">— Optional —</option>
                <?php foreach ($mapPool as $map): ?>
                    <option value="<?= htmlspecialchars($map['id']) ?>"><?= htmlspecialchars($map['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="editor-warning" id="editorWarning" role="status"></div>
        <div class="editor-actions">
            <button type="button" class="btn-add-matchup" id="btnSaveMatchup">Add to lineup</button>
            <button type="button" class="btn-cancel-edit" id="btnCancelEdit" style="display:none;">Cancel edit</button>
            <span class="editor-hint">Pick a player on one side first — the other side filters by group rule.</span>
        </div>
    </div>

    <div class="lineup-output-actions">
        <?php if ($canSendToSchedule): ?>
        <button type="button" class="btn btn-send-schedule" id="btnSendToSchedule" title="Insert matchups into fsl_matches and link to this schedule week">Send to schedule</button>
        <?php endif; ?>
        <button type="button" class="btn btn-primary btn-download" id="btnDownloadHtml">Download HTML</button>
        <button type="button" class="btn btn-outline-light btn-download" id="btnDownloadCsv">Download CSV</button>
        <button type="button" class="btn btn-outline-light btn-download" id="btnPrintPdf">Print (PDF)</button>
    </div>
    <?php if ($canSendToSchedule): ?>
    <div class="send-schedule-status" id="sendScheduleStatus" role="status"></div>
    <?php endif; ?>
</div>
</div>

<script>
(function() {
    const rankings = <?= json_encode($rankings) ?>;
    const teams = <?= json_encode($teams) ?>;
    const raceIcons = <?= json_encode($raceIcons) ?>;
    const teamLogos = <?= json_encode($teamLogos) ?>;
    const mapPool = <?= json_encode($mapPool) ?>;
    const scheduleWeeksBySeason = <?= json_encode($scheduleWeeksBySeason) ?>;
    const urlSeason = <?= (int) $lineupSeason ?>;
    const urlWeek = <?= (int) $urlWeek ?>;
    const urlCustom = <?= $urlCustom ? 'true' : 'false' ?>;
    const urlTeamAInit = <?= json_encode($urlTeamA) ?>;
    const urlTeamBInit = <?= json_encode($urlTeamB) ?>;
    const urlSlotsInit = <?= json_encode($urlSlots) ?>;
    const urlDescriptionInit = <?= json_encode($urlDescription) ?>;
    const urlHasLineupState = <?= $urlHasLineupState ? 'true' : 'false' ?>;
    const canSendToSchedule = <?= $canSendToSchedule ? 'true' : 'false' ?>;
    const lineupSendKey = <?= json_encode($lineupSendKey) ?>;
    const WEEK_CUSTOM = 'custom';
    const playerNameById = <?= json_encode($playerNameById) ?>;
    const playerIdByName = <?= json_encode($playerIdByName) ?>;
    const teamNameById = <?= json_encode($teamNameById) ?>;
    const teamIdByName = <?= json_encode($teamIdByName) ?>;
    const mapNameById = {};
    const mapIdByPoolIndex = {};
    const mapPoolIndexById = {};
    mapPool.forEach(function(m, i) {
        mapNameById[m.id] = m.name;
        var idx = i + 1;
        mapIdByPoolIndex[idx] = m.id;
        mapPoolIndexById[m.id] = idx;
    });

    /** Pool index is 1-based, matching dropdown order for the URL season's map pool. */
    function mapIdFromPoolIndex(idx) {
        var n = parseInt(idx, 10);
        if (!n || n < 1) return '';
        return mapIdByPoolIndex[n] || '';
    }
    function mapPoolIndexFromId(id) {
        if (!id) return 0;
        return mapPoolIndexById[id] || 0;
    }
    function mapFromUrlValue(val) {
        if (val === null || val === undefined || val === '') return '';
        if (typeof val === 'number' || (typeof val === 'string' && /^\d+$/.test(val))) {
            return mapIdFromPoolIndex(val) || '';
        }
        var s = String(val);
        if (mapPoolIndexById[s]) return s;
        return s;
    }

    function playerNameFromId(id) {
        if (id === null || id === undefined || id === '') return '';
        return playerNameById[String(parseInt(id, 10))] || playerNameById[id] || '';
    }
    function playerIdFromName(name) {
        var n = (name || '').trim();
        if (!n) return 0;
        if (playerIdByName[n]) return playerIdByName[n];
        var lower = n.toLowerCase();
        for (var key in playerIdByName) {
            if (key.toLowerCase() === lower) return playerIdByName[key];
        }
        return 0;
    }
    function teamNameFromId(id) {
        if (id === null || id === undefined || id === '') return '';
        return teamNameById[String(parseInt(id, 10))] || teamNameById[id] || '';
    }
    function teamIdFromName(name) {
        return teamIdByName[name] || 0;
    }

    function buildUrlSlots() {
        return matchups.filter(matchupHasPlayers).map(function(m) {
            var slot = { t: m.type === '2v2' ? '2' : '1' };
            if (m.broadcastDay === 'friday') slot.d = 'f';
            else if (m.broadcastDay === 'saturday') slot.d = 's';
            if (m.type !== '2v2') {
                var mapIdx1 = mapPoolIndexFromId(m.map1);
                var mapIdx2 = mapPoolIndexFromId(m.map2);
                if (mapIdx1) slot.m1 = mapIdx1;
                if (mapIdx2) slot.m2 = mapIdx2;
            }
            if (m.type === '2v2') {
                var pa1 = playerIdFromName(m.playerA1);
                var pa2 = playerIdFromName(m.playerA2);
                var pb1 = playerIdFromName(m.playerB1);
                var pb2 = playerIdFromName(m.playerB2);
                if (pa1) slot.pa1 = pa1;
                if (pa2) slot.pa2 = pa2;
                if (pb1) slot.pb1 = pb1;
                if (pb2) slot.pb2 = pb2;
            } else {
                var pa = playerIdFromName(m.playerA);
                var pb = playerIdFromName(m.playerB);
                if (pa) slot.pa = pa;
                if (pb) slot.pb = pb;
            }
            return slot;
        });
    }

    function buildShareUrlParams() {
        var params = new URLSearchParams();
        if (seasonEl && seasonEl.value) params.set('season', seasonEl.value);
        if (weekEl && weekEl.value) params.set('week', weekEl.value);
        var tidA = teamIdFromName(teamAEl.value);
        var tidB = teamIdFromName(teamBEl.value);
        if (tidA) params.set('teamA', String(tidA));
        if (tidB) params.set('teamB', String(tidB));
        params.set('rule', ruleEl.value);
        params.set('playersPerGroup', playersPerGroupEl.value);
        var urlSlots = buildUrlSlots();
        if (urlSlots.length) params.set('slots', JSON.stringify(urlSlots));
        if (isCustomWeek() && customDescriptionEl) {
            var desc = customDescriptionEl.value.trim();
            if (desc) params.set('description', desc);
        }
        return params;
    }

    const nameToRank = {};
    const nameToPlayer = {};
    rankings.forEach(function(p) {
        const name = (p.name || '').trim();
        if (!name) return;
        var rank = parseInt(p.rank, 10);
        nameToRank[name] = rank;
        nameToRank[name.toLowerCase()] = rank;
        nameToPlayer[name] = { rank: rank, name: name, race: (p.race || 'R') };
        nameToPlayer[name.toLowerCase()] = nameToPlayer[name];
    });

    function rankForName(name) {
        const n = (name || '').trim();
        return nameToRank[n] != null ? nameToRank[n] : nameToRank[n.toLowerCase()] ?? null;
    }
    function getPlayer(name) {
        if (!name) return null;
        var n = (name || '').trim();
        return nameToPlayer[n] || nameToPlayer[n.toLowerCase()] || null;
    }
    function groupForRank(rank, ppg) {
        if (!rank || rank < 1) return null;
        return Math.ceil(rank / ppg);
    }
    function allowedOpponentRanks(myRank, rule, ppg) {
        const g = groupForRank(myRank, ppg);
        if (g == null) return null;
        const maxRank = rankings.length;
        const numGroups = Math.ceil(maxRank / ppg);
        const minG = Math.max(1, g - rule);
        const maxG = Math.min(numGroups, g + rule);
        return { minRank: (minG - 1) * ppg + 1, maxRank: Math.min(maxRank, maxG * ppg) };
    }

    const ruleEl = document.getElementById('rule');
    const playersPerGroupEl = document.getElementById('playersPerGroup');
    const teamAEl = document.getElementById('teamA');
    const teamBEl = document.getElementById('teamB');
    const seasonEl = document.getElementById('lineupSeason');
    const weekEl = document.getElementById('lineupWeek');
    const customDescriptionEl = document.getElementById('lineupCustomDescription');
    const customDescriptionRow = document.getElementById('customDescriptionRow');
    const dateEl = document.getElementById('lineupDate');
    const teamSetupEl = document.getElementById('teamSetup');
    const teamsFromScheduleHint = document.getElementById('teamsFromScheduleHint');
    const editorCard = document.getElementById('editorCard');
    const editorTitle = document.getElementById('editorTitle');
    const editorWarning = document.getElementById('editorWarning');
    const btnSave = document.getElementById('btnSaveMatchup');
    const btnCancel = document.getElementById('btnCancelEdit');

    let matchups = [];
    let editingId = null;
    let nextId = 1;
    let editorType = '1vs1';
    let editorDay = '';

    function getPlayers(teamKey) {
        return teamKey && teams[teamKey] ? teams[teamKey] : [];
    }
    function getPpg() { return parseInt(playersPerGroupEl.value, 10) || 4; }
    function getRule() { return parseInt(ruleEl.value, 10) || 0; }

    function formatPlayerOptions(playerNames) {
        const ppg = getPpg();
        return (playerNames || []).map(function(name) {
            var p = getPlayer(name);
            var rank = p ? p.rank : null;
            var group = rank != null ? groupForRank(rank, ppg) : null;
            var label = rank != null ? '(' + rank + ') ' + name + ' · G' + group : name;
            return { name: name, displayLabel: label };
        }).sort(function(a, b) {
            var rA = rankForName(a.name), rB = rankForName(b.name);
            if (rA == null && rB == null) return (a.name || '').localeCompare(b.name || '');
            if (rA == null) return 1;
            if (rB == null) return -1;
            return rA - rB;
        });
    }

    function fillSelect(select, options, emptyLabel) {
        if (!select) return;
        const cur = select.value;
        select.innerHTML = '';
        var empty = document.createElement('option');
        empty.value = '';
        empty.textContent = emptyLabel || '—';
        select.appendChild(empty);
        (options || []).forEach(function(opt) {
            var o = document.createElement('option');
            o.value = opt.name;
            o.textContent = opt.displayLabel;
            if (o.value === cur) o.selected = true;
            select.appendChild(o);
        });
    }

    function allowedOpponents(playerName, opponentRoster) {
        const rule = getRule();
        const ppg = getPpg();
        if (!playerName || !rule) return opponentRoster;
        const myRank = rankForName(playerName);
        if (myRank == null) return opponentRoster;
        const allowed = allowedOpponentRanks(myRank, rule, ppg);
        if (!allowed) return opponentRoster;
        return opponentRoster.filter(function(name) {
            const r = rankForName(name);
            return r != null && r >= allowed.minRank && r <= allowed.maxRank;
        });
    }

    function renderPlayerMeta(name) {
        if (!name) return '';
        var p = getPlayer(name);
        if (!p) return '';
        var group = groupForRank(p.rank, getPpg());
        var icon = raceIcons[p.race] || raceIcons['R'];
        return '<span class="rank-badge">' + p.rank + '</span><img src="images/' + icon + '" alt="" class="race-icon"><span class="group-badge">G' + group + '</span>';
    }

    function updateTeamLabels() {
        var teamA = teamAEl.value;
        var teamB = teamBEl.value;
        var logoA = document.getElementById('teamALogo');
        var logoB = document.getElementById('teamBLogo');
        var labelA = document.getElementById('teamALabel');
        var labelB = document.getElementById('teamBLabel');
        var zoneATitle = document.getElementById('editorZoneATitle');
        var zoneBTitle = document.getElementById('editorZoneBTitle');

        if (teamA && teamLogos[teamA]) {
            logoA.src = teamLogos[teamA]; logoA.alt = teamA; logoA.style.display = 'block';
            labelA.textContent = teamA;
        } else {
            logoA.style.display = 'none'; labelA.textContent = 'Select team';
        }
        if (teamB && teamLogos[teamB]) {
            logoB.src = teamLogos[teamB]; logoB.alt = teamB; logoB.style.display = 'block';
            labelB.textContent = teamB;
        } else {
            logoB.style.display = 'none'; labelB.textContent = 'Select team';
        }
        zoneATitle.textContent = teamA || 'Team A';
        zoneBTitle.textContent = teamB || 'Team B';
        updateShareLink();
    }

    function buildShareUrl() {
        var qs = buildShareUrlParams().toString();
        return window.location.origin + window.location.pathname + (qs ? '?' + qs : '');
    }

    function matchupFromUrlSlot(slot, index) {
        var legacy = slot && (slot.type === '1vs1' || slot.type === '2v2'
            || slot.playerA !== undefined || slot.playerA1 !== undefined);
        var m = {
            id: 'm' + (index + 1),
            type: '1vs1',
            broadcastDay: '',
            map1: '',
            map2: ''
        };
        if (legacy && slot.t === undefined) {
            m.type = (slot.type === '2v2') ? '2v2' : '1vs1';
            m.broadcastDay = slot.broadcastDay || '';
            m.map1 = mapFromUrlValue(slot.map1);
            m.map2 = mapFromUrlValue(slot.map2);
            if (m.type === '2v2') {
                m.playerA1 = slot.playerA1 || '';
                m.playerA2 = slot.playerA2 || '';
                m.playerB1 = slot.playerB1 || '';
                m.playerB2 = slot.playerB2 || '';
                m.map1 = '';
                m.map2 = '';
            } else {
                m.playerA = slot.playerA || '';
                m.playerB = slot.playerB || '';
            }
            return m;
        }
        m.type = (slot.t === '2') ? '2v2' : '1vs1';
        if (slot.d === 'f') m.broadcastDay = 'friday';
        else if (slot.d === 's') m.broadcastDay = 'saturday';
        m.map1 = mapFromUrlValue(slot.m1);
        m.map2 = mapFromUrlValue(slot.m2);
        if (m.type === '2v2') {
            m.playerA1 = playerNameFromId(slot.pa1);
            m.playerA2 = playerNameFromId(slot.pa2);
            m.playerB1 = playerNameFromId(slot.pb1);
            m.playerB2 = playerNameFromId(slot.pb2);
            m.map1 = '';
            m.map2 = '';
        } else {
            m.playerA = playerNameFromId(slot.pa);
            m.playerB = playerNameFromId(slot.pb);
        }
        return m;
    }

    function applyUrlState() {
        if (urlDescriptionInit && customDescriptionEl) {
            customDescriptionEl.value = urlDescriptionInit;
        }
        var hasSlots = urlSlotsInit && urlSlotsInit.length;
        if (urlTeamAInit) teamAEl.value = urlTeamAInit;
        if (urlTeamBInit) teamBEl.value = urlTeamBInit;
        if ((urlTeamAInit || urlTeamBInit) && !isCustomWeek()) {
            setTeamSelectsLocked(false);
        }
        if (hasSlots) {
            matchups = urlSlotsInit.map(matchupFromUrlSlot);
            nextId = matchups.length + 1;
            renderBoard();
        }
        updateTeamLabels();
        refreshEditorSelects();
        updateShareLink();
    }

    function isCustomWeek() {
        return weekEl && weekEl.value === WEEK_CUSTOM;
    }

    function updateCustomDescriptionVisibility() {
        if (!customDescriptionRow) return;
        customDescriptionRow.classList.toggle('is-visible', isCustomWeek());
    }

    function weekHasScheduledTeams(week) {
        if (!week) return false;
        var t1 = week.team1_name || '';
        var t2 = week.team2_name || '';
        return t1 !== '' && t2 !== '' && t1 !== 'TBD' && t2 !== 'TBD';
    }

    function setTeamSelectsLocked(locked) {
        if (teamSetupEl) teamSetupEl.classList.toggle('teams-locked', locked);
        if (teamsFromScheduleHint) teamsFromScheduleHint.classList.toggle('is-visible', locked);
    }

    function applyTeamsFromWeekSelection() {
        updateCustomDescriptionVisibility();
        if (isCustomWeek()) {
            setTeamSelectsLocked(false);
            updateWeekDateFields();
            updateTeamLabels();
            refreshEditorSelects();
            return;
        }
        var week = getSelectedWeekData();
        updateWeekDateFields();
        if (week && weekHasScheduledTeams(week)) {
            teamAEl.value = week.team1_name;
            teamBEl.value = week.team2_name;
            setTeamSelectsLocked(true);
        } else {
            setTeamSelectsLocked(false);
            if (week && !teamAEl.value && !teamBEl.value) {
                teamAEl.value = '';
                teamBEl.value = '';
            }
        }
        updateTeamLabels();
        refreshEditorSelects();
    }

    function getSeasonWeeks() {
        var season = parseInt(seasonEl.value, 10) || 0;
        return scheduleWeeksBySeason[season] || scheduleWeeksBySeason[String(season)] || [];
    }

    function getSelectedWeekData() {
        if (!weekEl || !weekEl.value || isCustomWeek()) return null;
        var weekNum = parseInt(weekEl.value, 10);
        if (!weekNum) return null;
        var weeks = getSeasonWeeks();
        for (var i = 0; i < weeks.length; i++) {
            if (parseInt(weeks[i].week_number, 10) === weekNum) return weeks[i];
        }
        return null;
    }

    function weekExistsInSeason(weekNum, season) {
        var weeks = scheduleWeeksBySeason[season] || scheduleWeeksBySeason[String(season)] || [];
        for (var i = 0; i < weeks.length; i++) {
            if (parseInt(weeks[i].week_number, 10) === weekNum) return true;
        }
        return false;
    }

    function suggestWeekNumber() {
        var weeks = getSeasonWeeks();
        if (!weeks.length) return WEEK_CUSTOM;
        for (var k = 0; k < weeks.length; k++) {
            if (weeks[k].status !== 'completed') {
                return String(weeks[k].week_number);
            }
        }
        return String(weeks[0].week_number);
    }

    function getInitialWeekValue() {
        if (urlCustom) return WEEK_CUSTOM;
        var season = parseInt(seasonEl.value, 10) || urlSeason;
        if (urlWeek && weekExistsInSeason(urlWeek, season)) {
            return String(urlWeek);
        }
        return suggestWeekNumber();
    }

    function updateWeekDateFields() {
        var week = getSelectedWeekData();
        if (dateEl) dateEl.value = week && week.date ? week.date : '';
        var friLabel = document.getElementById('fridayDateLabel');
        var satLabel = document.getElementById('saturdayDateLabel');
        if (week && week.fri_short && week.sat_short) {
            if (friLabel) friLabel.textContent = 'Fri ' + week.fri_short + ' · 7 PM ET';
            if (satLabel) satLabel.textContent = 'Sat ' + week.sat_short + ' · 12 PM ET';
        } else if (week && week.match_long) {
            if (friLabel) friLabel.textContent = week.match_long + ' ET';
            if (satLabel) satLabel.textContent = '';
        } else {
            if (friLabel) friLabel.textContent = '7:00 PM ET';
            if (satLabel) satLabel.textContent = '12:00 PM ET';
        }
    }

    function refreshWeekSelect(preferredWeek) {
        if (!weekEl) return;
        var weeks = getSeasonWeeks();
        var current = preferredWeek || weekEl.value || suggestWeekNumber();
        weekEl.innerHTML = '';
        weeks.forEach(function(w) {
            var opt = document.createElement('option');
            opt.value = String(w.week_number);
            var label = w.label || ('Week ' + w.week_number);
            if (w.team1_name && w.team2_name && w.team1_name !== 'TBD' && w.team2_name !== 'TBD') {
                label += ' · ' + w.team1_name + ' vs ' + w.team2_name;
            } else if (w.team1_name === 'TBD' || w.team2_name === 'TBD') {
                label += ' · TBD';
            }
            if (w.status === 'completed') label += ' (done)';
            opt.textContent = label;
            weekEl.appendChild(opt);
        });
        var customOpt = document.createElement('option');
        customOpt.value = WEEK_CUSTOM;
        customOpt.textContent = 'Custom — pick teams manually';
        weekEl.appendChild(customOpt);
        if (current === WEEK_CUSTOM || weekEl.querySelector('option[value="' + current + '"]')) {
            weekEl.value = current;
        } else if (weeks.length) {
            weekEl.value = String(weeks[0].week_number);
        } else {
            weekEl.value = WEEK_CUSTOM;
        }
        applyTeamsFromWeekSelection();
    }

    function updateShareLink() {
        var url = buildShareUrl();
        var input = document.getElementById('shareLinkInput');
        if (input) input.value = url;
        history.replaceState(null, '', url.replace(window.location.origin, ''));
    }

    function getEditorValues() {
        if (editorType === '2v2') {
            return {
                type: '2v2',
                playerA1: (document.querySelector('.editor-player-a1') || {}).value || '',
                playerA2: (document.querySelector('.editor-player-a2') || {}).value || '',
                playerB1: (document.querySelector('.editor-player-b1') || {}).value || '',
                playerB2: (document.querySelector('.editor-player-b2') || {}).value || ''
            };
        }
        return {
            type: '1vs1',
            playerA: (document.querySelector('.editor-player-a') || {}).value || '',
            playerB: (document.querySelector('.editor-player-b') || {}).value || ''
        };
    }

    function setEditorValues(m) {
        editorDay = m.broadcastDay || '';
        updateDaySeg();
        setEditorType(m.type || '1vs1');
        if (m.type !== '2v2') {
            document.getElementById('editorMap1').value = m.map1 || '';
            document.getElementById('editorMap2').value = m.map2 || '';
        }
        if (m.type === '2v2') {
            document.querySelector('.editor-player-a1').value = m.playerA1 || '';
            document.querySelector('.editor-player-a2').value = m.playerA2 || '';
            document.querySelector('.editor-player-b1').value = m.playerB1 || '';
            document.querySelector('.editor-player-b2').value = m.playerB2 || '';
        } else {
            document.querySelector('.editor-player-a').value = m.playerA || '';
            document.querySelector('.editor-player-b').value = m.playerB || '';
        }
        refreshEditorSelects();
    }

    function clearEditor() {
        editingId = null;
        editorDay = '';
        editorCard.classList.remove('is-editing-mode');
        editorTitle.textContent = 'Add Matchup';
        btnSave.textContent = 'Add to lineup';
        btnCancel.style.display = 'none';
        document.getElementById('editorMap1').value = '';
        document.getElementById('editorMap2').value = '';
        setEditorType('1vs1');
        ['.editor-player-a', '.editor-player-a1', '.editor-player-a2', '.editor-player-b', '.editor-player-b1', '.editor-player-b2'].forEach(function(sel) {
            var el = document.querySelector(sel);
            if (el) el.value = '';
        });
        updateDaySeg();
        refreshEditorSelects();
        editorWarning.classList.remove('is-visible');
        editorWarning.textContent = '';
        document.querySelectorAll('.matchup-card.is-editing').forEach(function(c) { c.classList.remove('is-editing'); });
    }

    function updateEditorMapsForType() {
        var map1 = document.getElementById('editorMap1');
        var map2 = document.getElementById('editorMap2');
        if (!map1 || !map2) return;
        var is2v2 = editorType === '2v2';
        if (is2v2) {
            map1.value = '';
            map2.value = '';
        }
        map1.disabled = is2v2;
        map2.disabled = is2v2;
    }

    function setEditorType(type) {
        editorType = type === '2v2' ? '2v2' : '1vs1';
        document.querySelectorAll('#editorTypeSeg button').forEach(function(btn) {
            btn.classList.toggle('active', btn.getAttribute('data-type') === editorType);
        });
        document.querySelectorAll('#editorZoneA .editor-1v1, #editorZoneB .editor-1v1').forEach(function(el) {
            el.style.display = editorType === '1vs1' ? '' : 'none';
        });
        document.querySelectorAll('#editorZoneA .editor-2v2, #editorZoneB .editor-2v2').forEach(function(el) {
            el.style.display = editorType === '2v2' ? '' : 'none';
        });
        updateEditorMapsForType();
        refreshEditorSelects();
    }

    function updateDaySeg() {
        document.querySelectorAll('#editorDaySeg button').forEach(function(btn) {
            var day = btn.getAttribute('data-day');
            var active = day === editorDay;
            btn.classList.toggle('active', active);
            btn.classList.remove('friday-active', 'saturday-active');
            if (active && day === 'friday') btn.classList.add('friday-active');
            if (active && day === 'saturday') btn.classList.add('saturday-active');
        });
    }

    function refreshEditorSelects() {
        var teamAPlayers = getPlayers(teamAEl.value);
        var teamBPlayers = getPlayers(teamBEl.value);
        var optsA = formatPlayerOptions(teamAPlayers);
        var optsB = formatPlayerOptions(teamBPlayers);

        if (editorType === '2v2') {
            ['a1', 'a2'].forEach(function(k) {
                fillSelect(document.querySelector('.editor-player-' + k), optsA, '— Player ' + k.slice(-1) + ' —');
            });
            ['b1', 'b2'].forEach(function(k) {
                fillSelect(document.querySelector('.editor-player-' + k), optsB, '— Player ' + k.slice(-1) + ' —');
            });
            updateEditor2v2Meta();
            return;
        }

        var selA = document.querySelector('.editor-player-a');
        var selB = document.querySelector('.editor-player-b');
        var playerA = selA ? selA.value : '';
        var playerB = selB ? selB.value : '';
        var optionsA = playerB ? allowedOpponents(playerB, teamAPlayers) : teamAPlayers;
        var optionsB = playerA ? allowedOpponents(playerA, teamBPlayers) : teamBPlayers;
        fillSelect(selA, formatPlayerOptions(optionsA), '— Player —');
        fillSelect(selB, formatPlayerOptions(optionsB), '— Player —');
        if (selA) selA.value = optionsA.indexOf(playerA) !== -1 ? playerA : '';
        if (selB) selB.value = optionsB.indexOf(playerB) !== -1 ? playerB : '';

        document.querySelector('.editor-info-a').innerHTML = renderPlayerMeta(selA ? selA.value : '');
        document.querySelector('.editor-info-b').innerHTML = renderPlayerMeta(selB ? selB.value : '');
        editorWarning.classList.remove('is-visible');
        editorWarning.textContent = '';
    }

    function updateEditor2v2Meta() {
        ['a1', 'a2', 'b1', 'b2'].forEach(function(k) {
            var sel = document.querySelector('.editor-player-' + k);
            var info = document.querySelector('.editor-info-' + k);
            if (info) info.innerHTML = renderPlayerMeta(sel ? sel.value : '');
        });
        var a1 = (document.querySelector('.editor-player-a1') || {}).value || '';
        var a2 = (document.querySelector('.editor-player-a2') || {}).value || '';
        var b1 = (document.querySelector('.editor-player-b1') || {}).value || '';
        var b2 = (document.querySelector('.editor-player-b2') || {}).value || '';
        if (!((a1 || a2) && (b1 || b2))) {
            editorWarning.classList.remove('is-visible');
            return;
        }
        var ppg = getPpg();
        var g = function(name) {
            var p = getPlayer(name);
            return p ? groupForRank(p.rank, ppg) : 0;
        };
        var diff = Math.abs((g(a1) + g(a2)) - (g(b1) + g(b2)));
        if (diff >= 3) {
            editorWarning.textContent = 'Note: skill differential based on groups is ' + diff + '.';
            editorWarning.classList.add('is-visible');
        } else {
            editorWarning.classList.remove('is-visible');
            editorWarning.textContent = '';
        }
    }

    function matchupHasPlayers(m) {
        if (m.type === '2v2') {
            return !!(m.playerA1 || m.playerA2 || m.playerB1 || m.playerB2);
        }
        return !!(m.playerA || m.playerB);
    }

    function validateEditor() {
        if (!weekEl || !weekEl.value) {
            alert('Select a schedule week or Custom.');
            return false;
        }
        if (!teamAEl.value || !teamBEl.value) {
            alert('Select both teams (or pick a scheduled week with assigned teams).');
            return false;
        }
        if (teamAEl.value === teamBEl.value) {
            alert('Team A and Team B must be different.');
            return false;
        }
        if (!editorDay) {
            alert('Choose Friday or Saturday for this matchup.');
            return false;
        }
        var v = getEditorValues();
        if (v.type === '2v2') {
            if (!((v.playerA1 || v.playerA2) && (v.playerB1 || v.playerB2))) {
                alert('Pick at least one player on each side for 2v2.');
                return false;
            }
        } else if (!v.playerA || !v.playerB) {
            alert('Pick a player on both sides.');
            return false;
        }
        return true;
    }

    function saveMatchup() {
        if (!validateEditor()) return;
        var v = getEditorValues();
        var data = {
            id: editingId || ('m' + (nextId++)),
            type: v.type,
            broadcastDay: editorDay,
            map1: v.type === '2v2' ? '' : (document.getElementById('editorMap1').value || ''),
            map2: v.type === '2v2' ? '' : (document.getElementById('editorMap2').value || '')
        };
        if (v.type === '2v2') {
            data.playerA1 = v.playerA1; data.playerA2 = v.playerA2;
            data.playerB1 = v.playerB1; data.playerB2 = v.playerB2;
        } else {
            data.playerA = v.playerA; data.playerB = v.playerB;
        }
        if (editingId) {
            var idx = matchups.findIndex(function(m) { return m.id === editingId; });
            if (idx !== -1) matchups[idx] = data;
        } else {
            matchups.push(data);
        }
        clearEditor();
        renderBoard();
        updateShareLink();
    }

    function deleteMatchup(id) {
        if (!confirm('Remove this matchup?')) return;
        matchups = matchups.filter(function(m) { return m.id !== id; });
        if (editingId === id) clearEditor();
        renderBoard();
        updateShareLink();
    }

    function reorderMatchupInDay(id, delta) {
        var item = matchups.find(function(m) { return m.id === id; });
        if (!item || !item.broadcastDay) return;
        var day = item.broadcastDay;
        var dayItems = matchups.filter(function(m) { return m.broadcastDay === day; });
        var idx = dayItems.findIndex(function(m) { return m.id === id; });
        var newIdx = idx + delta;
        if (idx < 0 || newIdx < 0 || newIdx >= dayItems.length) return;
        var reordered = dayItems.slice();
        var tmp = reordered[idx];
        reordered[idx] = reordered[newIdx];
        reordered[newIdx] = tmp;
        var queue = reordered.slice();
        matchups = matchups.map(function(m) {
            if (m.broadcastDay === day) {
                return queue.shift();
            }
            return m;
        });
        renderBoard();
        updateShareLink();
    }

    function editMatchup(id) {
        var m = matchups.find(function(x) { return x.id === id; });
        if (!m) return;
        editingId = id;
        editorCard.classList.add('is-editing-mode');
        editorTitle.textContent = 'Edit Matchup';
        btnSave.textContent = 'Save changes';
        btnCancel.style.display = '';
        setEditorValues(m);
        document.querySelectorAll('.matchup-card.is-editing').forEach(function(c) { c.classList.remove('is-editing'); });
        var card = document.querySelector('.matchup-card[data-id="' + id + '"]');
        if (card) card.classList.add('is-editing');
        editorCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function sideHtml(names) {
        return names.filter(Boolean).map(function(name) {
            return '<div class="player-name">' + escapeHtml(name) + '</div><div class="player-meta">' + renderPlayerMeta(name) + '</div>';
        }).join('');
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function renderMatchupCard(m, index, totalInDay) {
        var typeLabel = m.type === '2v2' ? '2v2' : '1v1';
        var sideA, sideB;
        if (m.type === '2v2') {
            sideA = sideHtml([m.playerA1, m.playerA2].filter(Boolean));
            sideB = sideHtml([m.playerB1, m.playerB2].filter(Boolean));
        } else {
            sideA = sideHtml([m.playerA]);
            sideB = sideHtml([m.playerB]);
        }
        var mapsHtml = '';
        if (m.type !== '2v2') {
            var maps = [];
            if (m.map1) maps.push('M1: ' + (mapNameById[m.map1] || m.map1));
            if (m.map2) maps.push('M2: ' + (mapNameById[m.map2] || m.map2));
            if (maps.length) {
                mapsHtml = '<div class="matchup-card-maps">' + escapeHtml(maps.join(' · ')) + '</div>';
            }
        }
        var upDisabled = index === 0 ? ' disabled' : '';
        var downDisabled = index >= totalInDay - 1 ? ' disabled' : '';

        return '<div class="matchup-card" data-id="' + escapeHtml(m.id) + '">' +
            '<div class="matchup-card-head">' +
                '<span class="type-badge">' + typeLabel + ' #' + (index + 1) + '</span>' +
                '<div class="matchup-card-actions">' +
                    '<button type="button" class="btn-move btn-move-up" data-id="' + escapeHtml(m.id) + '" title="Move up"' + upDisabled + ' aria-label="Move up"><i class="fas fa-chevron-up"></i></button>' +
                    '<button type="button" class="btn-move btn-move-down" data-id="' + escapeHtml(m.id) + '" title="Move down"' + downDisabled + ' aria-label="Move down"><i class="fas fa-chevron-down"></i></button>' +
                    '<button type="button" class="btn-edit" data-id="' + escapeHtml(m.id) + '" title="Edit"><i class="fas fa-pen"></i></button>' +
                    '<button type="button" class="btn-delete" data-id="' + escapeHtml(m.id) + '" title="Delete"><i class="fas fa-trash"></i></button>' +
                '</div>' +
            '</div>' +
            '<div class="matchup-card-body">' +
                '<div class="matchup-side side-a">' + sideA + '</div>' +
                '<span class="matchup-vs">vs</span>' +
                '<div class="matchup-side side-b">' + sideB + '</div>' +
            '</div>' + mapsHtml +
        '</div>';
    }

    function renderBoard() {
        var friday = matchups.filter(function(m) { return m.broadcastDay === 'friday'; });
        var saturday = matchups.filter(function(m) { return m.broadcastDay === 'saturday'; });
        var fridayBoard = document.getElementById('fridayBoard');
        var saturdayBoard = document.getElementById('saturdayBoard');
        document.getElementById('fridayCount').textContent = friday.length + ' matchup' + (friday.length === 1 ? '' : 's');
        document.getElementById('saturdayCount').textContent = saturday.length + ' matchup' + (saturday.length === 1 ? '' : 's');

        fridayBoard.innerHTML = friday.length
            ? friday.map(function(m, i) { return renderMatchupCard(m, i, friday.length); }).join('')
            : '<div class="day-empty">No Friday matchups yet</div>';
        saturdayBoard.innerHTML = saturday.length
            ? saturday.map(function(m, i) { return renderMatchupCard(m, i, saturday.length); }).join('')
            : '<div class="day-empty">No Saturday matchups yet</div>';

        function bindBoardActions(board) {
            board.querySelectorAll('.btn-move-up').forEach(function(btn) {
                btn.addEventListener('click', function() { reorderMatchupInDay(btn.getAttribute('data-id'), -1); });
            });
            board.querySelectorAll('.btn-move-down').forEach(function(btn) {
                btn.addEventListener('click', function() { reorderMatchupInDay(btn.getAttribute('data-id'), 1); });
            });
            board.querySelectorAll('.btn-edit').forEach(function(btn) {
                btn.addEventListener('click', function() { editMatchup(btn.getAttribute('data-id')); });
            });
            board.querySelectorAll('.btn-delete').forEach(function(btn) {
                btn.addEventListener('click', function() { deleteMatchup(btn.getAttribute('data-id')); });
            });
        }
        bindBoardActions(fridayBoard);
        bindBoardActions(saturdayBoard);
        if (editingId) {
            var card = document.querySelector('.matchup-card[data-id="' + editingId + '"]');
            if (card) card.classList.add('is-editing');
        }
    }

    function buildLineupParams(extra) {
        var slots = matchups.filter(matchupHasPlayers).map(function(m) {
            var slot = {
                type: m.type,
                broadcastDay: m.broadcastDay || '',
                map1: m.type === '2v2' ? '' : (m.map1 || ''),
                map2: m.type === '2v2' ? '' : (m.map2 || '')
            };
            if (m.type === '2v2') {
                slot.playerA1 = m.playerA1 || ''; slot.playerA2 = m.playerA2 || '';
                slot.playerB1 = m.playerB1 || ''; slot.playerB2 = m.playerB2 || '';
            } else {
                slot.playerA = m.playerA || ''; slot.playerB = m.playerB || '';
            }
            return slot;
        });
        var p = {
            teamA: teamAEl.value,
            teamB: teamBEl.value,
            rule: ruleEl.value,
            playersPerGroup: playersPerGroupEl.value,
            season: seasonEl.value,
            week: weekEl ? weekEl.value : '',
            date: dateEl ? dateEl.value : '',
            slots: JSON.stringify(slots)
        };
        if (extra) for (var k in extra) p[k] = extra[k];
        return new URLSearchParams(p);
    }

    teamAEl.addEventListener('change', function() {
        if (!isCustomWeek()) return;
        updateTeamLabels();
        refreshEditorSelects();
    });
    teamBEl.addEventListener('change', function() {
        if (!isCustomWeek()) return;
        updateTeamLabels();
        refreshEditorSelects();
    });
    if (seasonEl) {
        seasonEl.addEventListener('change', function() {
            refreshWeekSelect(suggestWeekNumber());
            updateShareLink();
        });
    }
    if (weekEl) {
        weekEl.addEventListener('change', function() {
            applyTeamsFromWeekSelection();
            updateShareLink();
        });
    }
    ruleEl.addEventListener('input', function() { refreshEditorSelects(); updateShareLink(); });
    ruleEl.addEventListener('change', function() { refreshEditorSelects(); updateShareLink(); });
    playersPerGroupEl.addEventListener('input', function() { refreshEditorSelects(); updateShareLink(); });
    playersPerGroupEl.addEventListener('change', function() { refreshEditorSelects(); updateShareLink(); });
    if (customDescriptionEl) {
        customDescriptionEl.addEventListener('input', updateShareLink);
        customDescriptionEl.addEventListener('change', updateShareLink);
    }

    document.querySelectorAll('#editorTypeSeg button').forEach(function(btn) {
        btn.addEventListener('click', function() { setEditorType(btn.getAttribute('data-type')); });
    });
    document.querySelectorAll('#editorDaySeg button').forEach(function(btn) {
        btn.addEventListener('click', function() {
            editorDay = btn.getAttribute('data-day');
            updateDaySeg();
        });
    });

    document.querySelector('.editor-player-a').addEventListener('change', refreshEditorSelects);
    document.querySelector('.editor-player-b').addEventListener('change', refreshEditorSelects);
    ['a1', 'a2', 'b1', 'b2'].forEach(function(k) {
        var sel = document.querySelector('.editor-player-' + k);
        if (sel) sel.addEventListener('change', updateEditor2v2Meta);
    });

    btnSave.addEventListener('click', saveMatchup);
    btnCancel.addEventListener('click', clearEditor);

    document.getElementById('btnCopyLink').addEventListener('click', function() {
        var url = buildShareUrl();
        var input = document.getElementById('shareLinkInput');
        if (input) {
            input.value = url;
            input.select();
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function() {
                var btn = document.getElementById('btnCopyLink');
                var prev = btn.textContent;
                btn.textContent = 'Copied!';
                setTimeout(function() { btn.textContent = prev; }, 1500);
            });
        }
    });

    document.getElementById('btnDownloadHtml').addEventListener('click', function() {
        if (!weekEl || !weekEl.value) { alert('Select a schedule week or Custom.'); return; }
        if (!teamAEl.value || !teamBEl.value) { alert('Select both teams.'); return; }
        if (!matchups.length) { alert('Add at least one matchup.'); return; }
        window.location.href = 'lineup_pdf.php?' + buildLineupParams({ output: 'html' }).toString();
    });
    document.getElementById('btnPrintPdf').addEventListener('click', function() {
        if (!weekEl || !weekEl.value) { alert('Select a schedule week or Custom.'); return; }
        if (!teamAEl.value || !teamBEl.value) { alert('Select both teams.'); return; }
        if (!matchups.length) { alert('Add at least one matchup.'); return; }
        window.open('lineup_pdf.php?' + buildLineupParams().toString(), 'lineup_pdf', 'width=800,height=700,scrollbars=yes');
    });
    document.getElementById('btnDownloadCsv').addEventListener('click', downloadLineupCsv);

    function orderedMatchupsForExport() {
        var friday = matchups.filter(function(m) { return m.broadcastDay === 'friday' && matchupHasPlayers(m); });
        var saturday = matchups.filter(function(m) { return m.broadcastDay === 'saturday' && matchupHasPlayers(m); });
        return friday.concat(saturday);
    }

    function csvCell(val) {
        if (val === null || val === undefined) val = '';
        return '"' + String(val).replace(/"/g, '""') + '"';
    }

    function csvRow(cells) {
        var row = cells.slice();
        while (row.length < 12) row.push('');
        return row.slice(0, 12).map(csvCell).join(',');
    }

    function csvPlayerLabel(name) {
        if (!name) return '';
        var p = getPlayer(name);
        var race = (p && p.race) ? p.race : 'R';
        return '(' + race + ')' + name;
    }

    function csvMapName(mapId) {
        if (!mapId) return '';
        return mapNameById[mapId] || mapId;
    }

    function csvTypeLabel(type) {
        return type === '2v2' ? '2v2' : '1v1';
    }

    function buildLineupCsv() {
        var weekData = getSelectedWeekData();
        var scheduleId = weekData && weekData.schedule_id ? String(weekData.schedule_id) : '';
        var teamA = teamAEl.value || '';
        var teamB = teamBEl.value || '';
        var lines = [];

        lines.push(csvRow(['', '', teamA, '', '', '', teamB, '', '', '', '', scheduleId]));
        lines.push(csvRow(['', '', '', '', '', '', '', '', '', '', 'map 1', 'map 2']));

        orderedMatchupsForExport().forEach(function(m) {
            var map1 = m.type === '2v2' ? '' : csvMapName(m.map1);
            var map2 = m.type === '2v2' ? '' : csvMapName(m.map2);
            if (m.type === '2v2') {
                lines.push(csvRow([
                    '',
                    csvTypeLabel(m.type),
                    csvPlayerLabel(m.playerA1),
                    csvPlayerLabel(m.playerA2),
                    '',
                    '',
                    csvPlayerLabel(m.playerB1),
                    csvPlayerLabel(m.playerB2),
                    '',
                    '',
                    map1,
                    map2
                ]));
            } else {
                lines.push(csvRow([
                    '',
                    csvTypeLabel(m.type),
                    csvPlayerLabel(m.playerA),
                    '',
                    '',
                    '',
                    csvPlayerLabel(m.playerB),
                    '',
                    '',
                    '',
                    map1,
                    map2
                ]));
            }
        });

        lines.push(csvRow([]));
        return lines.join('\n');
    }

    function downloadLineupCsv() {
        if (!weekEl || !weekEl.value) { alert('Select a schedule week or Custom.'); return; }
        if (!teamAEl.value || !teamBEl.value) { alert('Select both teams.'); return; }
        if (!orderedMatchupsForExport().length) { alert('Add at least one complete matchup.'); return; }

        var season = seasonEl ? seasonEl.value : 'lineup';
        var week = weekEl.value;
        var safeName = function(s) {
            return (s || 'team').replace(/[^\w\-]+/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
        };
        var filename = 'lineup_s' + season + '_w' + week + '_' + safeName(teamAEl.value) + '_vs_' + safeName(teamBEl.value) + '.csv';

        var blob = new Blob(['\uFEFF' + buildLineupCsv()], { type: 'text/csv;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    function buildMatchupsPayload() {
        return matchups.filter(matchupHasPlayers).map(function(m) {
            var row = {
                type: m.type,
                broadcastDay: m.broadcastDay || '',
                map1: m.type === '2v2' ? '' : (m.map1 || ''),
                map2: m.type === '2v2' ? '' : (m.map2 || '')
            };
            if (m.type === '2v2') {
                row.playerA1 = m.playerA1 || '';
                row.playerA2 = m.playerA2 || '';
                row.playerB1 = m.playerB1 || '';
                row.playerB2 = m.playerB2 || '';
            } else {
                row.playerA = m.playerA || '';
                row.playerB = m.playerB || '';
            }
            return row;
        });
    }

    function setSendStatus(message, kind) {
        var el = document.getElementById('sendScheduleStatus');
        if (!el) return;
        el.textContent = message || '';
        el.classList.remove('is-success', 'is-error');
        if (kind) el.classList.add(kind === 'success' ? 'is-success' : 'is-error');
    }

    function sendToSchedule(confirmDuplicates) {
        if (!canSendToSchedule) return;
        if (!weekEl || !weekEl.value) {
            alert('Select a schedule week or Custom.');
            return;
        }
        if (isCustomWeek()) {
            var desc = customDescriptionEl ? customDescriptionEl.value.trim() : '';
            if (!desc) {
                alert('Enter a description for this custom lineup (used as Season Extra Info and Notes).');
                if (customDescriptionEl) customDescriptionEl.focus();
                return;
            }
        }
        if (!teamAEl.value || !teamBEl.value) {
            alert('Select both teams.');
            return;
        }
        var payload = buildMatchupsPayload();
        if (!payload.length) {
            alert('Add at least one complete matchup.');
            return;
        }
        var btn = document.getElementById('btnSendToSchedule');
        if (btn) btn.disabled = true;
        setSendStatus('Saving…', null);

        var form = new FormData();
        form.append('action', 'send_to_schedule');
        form.append('season', seasonEl.value);
        form.append('week', weekEl.value);
        form.append('teamA', teamAEl.value);
        form.append('teamB', teamBEl.value);
        form.append('matchups', JSON.stringify(payload));
        if (lineupSendKey) form.append('send_key', lineupSendKey);
        if (isCustomWeek() && customDescriptionEl) {
            form.append('description', customDescriptionEl.value.trim());
        }
        if (confirmDuplicates) form.append('confirm_duplicates', '1');

        fetch('lineup_planner_handler.php', { method: 'POST', body: form, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 'confirm') {
                    if (confirm(data.message || 'Possible duplicate match(es). Add anyway?')) {
                        sendToSchedule(true);
                    } else {
                        setSendStatus('Cancelled.', null);
                    }
                    return;
                }
                if (data.status === 'success') {
                    var msg = data.message || 'Saved.';
                    if (data.match_ids && data.match_ids.length) {
                        msg += ' Match IDs: ' + data.match_ids.join(', ') + '.';
                    }
                    if (data.two_vtwo_skipped) {
                        msg += ' 2v2 lineups are not inserted as match rows for Custom.';
                    }
                    setSendStatus(msg, 'success');
                    return;
                }
                setSendStatus(data.message || 'Save failed.', 'error');
            })
            .catch(function() {
                setSendStatus('Network error.', 'error');
            })
            .finally(function() {
                if (btn) btn.disabled = false;
            });
    }

    var btnSend = document.getElementById('btnSendToSchedule');
    if (btnSend) {
        btnSend.addEventListener('click', function() { sendToSchedule(false); });
    }

    refreshWeekSelect(getInitialWeekValue());
    updateCustomDescriptionVisibility();
    if (urlHasLineupState) {
        applyUrlState();
    } else if (isCustomWeek()) {
        if (urlTeamAInit) teamAEl.value = urlTeamAInit;
        if (urlTeamBInit) teamBEl.value = urlTeamBInit;
        updateTeamLabels();
        refreshEditorSelects();
    } else {
        updateShareLink();
    }
    if (!urlHasLineupState) {
        renderBoard();
    }
})();
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
