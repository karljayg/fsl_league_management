<?php
/**
 * Lineup Planner – Captains pick Team A vs Team B; each slot shows allowed opponents by ranking rule.
 * Reads fsl/rankings (rankings.json). Rule: player can play up to N groups above/below (default 2).
 * Group = ceil(rank / playersPerGroup); default 4 players per group.
 */
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/season_utils.php';

$pageTitle = 'Lineup Planner';
$rankingsFile = __DIR__ . '/rankings/rankings.json';
$rankings = [];
if (file_exists($rankingsFile)) {
    $rankings = json_decode(file_get_contents($rankingsFile), true) ?? [];
}

// Teams and players (active only for lineup)
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
.lineup-planner { max-width: 900px; margin: 0 auto; padding: 0 1rem; }
.lineup-planner .lineup-header { text-align: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid rgba(108, 92, 231, 0.3); }
.lineup-planner .lineup-header h1 { font-family: 'Rajdhani', sans-serif; font-size: 2.2rem; font-weight: 700; background: linear-gradient(135deg, #6c5ce7, #a29bfe); -webkit-background-clip: text; -webkit-text-fill-color: transparent; text-transform: uppercase; letter-spacing: 2px; margin: 0; }
.lineup-planner .help-text { font-size: 0.9rem; color: #888; margin-top: 0.5rem; }
.lineup-planner .card { background: rgba(0, 0, 0, 0.3); border-radius: 10px; padding: 1.25rem; margin-bottom: 1rem; border: 1px solid rgba(108, 92, 231, 0.2); }
.lineup-planner label { font-weight: 500; color: #aaa; }
.lineup-planner .form-row { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; margin-bottom: 0.75rem; justify-content: center; }
.lineup-planner .form-row:last-child { margin-bottom: 0; }
.lineup-planner .form-row label { min-width: 90px; }
.lineup-planner input[type="date"] {
    padding: 0.4rem 0.5rem;
    background: rgba(0,0,0,0.3);
    border: 1px solid rgba(108, 92, 231, 0.4);
    color: #fff;
    border-radius: 6px;
    color-scheme: dark;
}
.lineup-planner input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1); opacity: 0.7; }
/* Team selection: logos + dropdowns in a clear two-column layout, centered */
.lineup-planner .team-select-section { display: grid; grid-template-columns: 1fr auto 1fr; gap: 1rem 0; align-items: start; margin-bottom: 1rem; justify-items: center; }
.lineup-planner .team-select-section .team-side { display: flex; flex-direction: column; align-items: center; gap: 0.75rem; min-width: 0; }
.lineup-planner .team-select-section .team-logo-box { display: flex; flex-direction: column; align-items: center; gap: 0.4rem; width: 100%; max-width: 180px; min-height: 4.5rem; }
.lineup-planner .team-select-section .team-logo-box img { width: 56px; height: 56px; object-fit: contain; border-radius: 8px; border: 1px solid rgba(108, 92, 231, 0.3); }
.lineup-planner .team-select-section .team-logo-box .team-logo-label { font-size: 0.85rem; color: #aaa; text-align: center; line-height: 1.2; }
.lineup-planner .team-select-section .team-logo-box .team-logo-label.has-team { color: #e0e0e0; font-weight: 600; }
.lineup-planner .team-select-section .vs-divider { color: #00b894; font-weight: 700; font-size: 1.1rem; padding-top: 1.5rem; align-self: center; }
.lineup-planner .team-select-section .team-side select { width: 100%; max-width: 220px; }
.lineup-planner .card select { min-width: 140px; padding: 0.5rem; background: rgba(0,0,0,0.3); border: 1px solid rgba(108, 92, 231, 0.4); color: #fff; border-radius: 6px; }
.lineup-planner input[type="number"] { width: 60px; padding: 0.4rem 0.5rem; background: rgba(0,0,0,0.3); border: 1px solid rgba(108, 92, 231, 0.4); color: #fff; border-radius: 6px; }
/* Season + Date row */
.lineup-planner .form-row.settings-row { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.06); }
/* Group rules on their own row */
.lineup-planner .form-row.rules-row { margin-top: 0.5rem; padding-top: 0.75rem; border-top: 1px solid rgba(255,255,255,0.06); }
.lineup-planner .form-row.settings-row label { color: #666; font-size: 0.9rem; }
.lineup-planner .form-row.settings-row input[type="number"] { background: rgba(0,0,0,0.2); border-color: rgba(255,255,255,0.1); color: #888; }
.lineup-planner .form-row.rules-row label { color: #666; font-size: 0.9rem; }
.lineup-planner .form-row.rules-row input[type="number"] { background: rgba(0,0,0,0.2); border-color: rgba(255,255,255,0.1); color: #888; }
.lineup-planner .form-row.settings-row .settings-hint { color: #555; font-size: 0.8rem; margin-left: 0.25rem; }
@media (max-width: 600px) {
    .lineup-planner .team-select-section { grid-template-columns: 1fr; }
    .lineup-planner .team-select-section .vs-divider { padding-top: 0; }
}
.lineup-slots { margin-top: 1rem; }
.lineup-slot { display: grid; grid-template-columns: 2rem 2.25rem 2.5rem minmax(0,1fr) auto minmax(0,1fr) auto; gap: 0.75rem; align-items: center; padding: 0.75rem 1rem 0.75rem 1rem; margin-bottom: 0.5rem; background: rgba(0, 0, 0, 0.2); border-radius: 8px; position: relative; overflow: hidden; transition: background 0.2s; }
.lineup-slot:hover { background: rgba(108, 92, 231, 0.12); }
.lineup-slot .slot-drag-handle { cursor: grab; color: #666; padding: 0.25rem; user-select: none; }
.lineup-slot .slot-drag-handle:active { cursor: grabbing; }
.lineup-slot.dragging { opacity: 0.6; }
.lineup-slot.drag-over { outline: 2px dashed rgba(108, 92, 231, 0.6); outline-offset: 2px; }
.lineup-slot .slot-type-wrap { width: 2.5rem; min-width: 2.5rem; overflow: hidden; display: block; }
.lineup-slot .slot-type-wrap .slot-type { width: 100%; min-width: 0; max-width: none; padding: 0.35rem 0.1rem; background: rgba(0,0,0,0.3); border: 1px solid rgba(108, 92, 231, 0.4); color: #fff; border-radius: 6px; font-size: 0.75rem; box-sizing: border-box; appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23aaa' d='M6 8L1 3h10z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 0.15rem center; background-size: 0.5rem; padding-right: 1rem; }
.lineup-slot .slot-num { color: #888; font-weight: 600; font-family: 'Rajdhani', sans-serif; }
.lineup-slot .slot-1v1 { display: contents; }
.lineup-slot .slot-2v2 { display: none; grid-column: 4 / 7; grid-template-columns: subgrid; align-items: center; gap: 0.5rem; }
.lineup-slot.slot-type-2v2 { grid-template-rows: auto auto; }
.lineup-slot.slot-type-2v2 .slot-1v1 { display: none; }
.lineup-slot.slot-type-2v2 .slot-2v2 { display: grid; }
.lineup-slot .slot-2v2 .team-pair { display: flex; flex-direction: column; gap: 0.35rem; min-width: 0; }
.lineup-slot .slot-2v2 .team-pair select { max-width: 160px; }
.lineup-slot .differential-warning { grid-column: 1 / -1; grid-row: 2; font-size: 0.85rem; color: #f0ad4e; margin-top: 0.25rem; display: none; }
.lineup-slot.slot-type-2v2 .differential-warning.is-visible { display: block; }
.lineup-slot .player-cell { display: flex; flex-direction: column; gap: 0.35rem; min-width: 0; }
.lineup-slot select { width: 100%; max-width: 200px; padding: 0.45rem 0.5rem; background: rgba(0,0,0,0.3); border: 1px solid rgba(108, 92, 231, 0.4); color: #fff; border-radius: 6px; font-family: 'Exo 2', sans-serif; }
.lineup-slot .player-info { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; min-height: 28px; }
.lineup-slot .player-info .rank-badge { width: 26px; height: 26px; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #6c5ce7, #a29bfe); border-radius: 50%; font-family: 'Rajdhani', sans-serif; font-size: 0.8rem; font-weight: 700; color: #fff; flex-shrink: 0; }
.lineup-slot .player-info .race-icon { width: 20px; height: 20px; flex-shrink: 0; }
.lineup-slot .player-info .group-badge { font-size: 0.75rem; color: #a29bfe; background: rgba(108, 92, 231, 0.25); padding: 0.15rem 0.5rem; border-radius: 12px; }
.lineup-slot .vs { color: #00b894; font-weight: 700; font-family: 'Rajdhani', sans-serif; font-size: 1rem; }
.lineup-slot .slot-reset { padding: 0.35rem 0.6rem; background: rgba(108, 92, 231, 0.2); border: 1px solid rgba(108, 92, 231, 0.4); border-radius: 5px; color: #a29bfe; cursor: pointer; font-size: 0.8rem; }
.lineup-slot .slot-reset:hover { background: rgba(108, 92, 231, 0.4); color: #fff; }
.lineup-slot .skill-band { position: absolute; left: 0; top: 0; bottom: 0; width: 8px; border-radius: 8px 0 0 8px; }
.lineup-slot .skill-band.g1  { background: linear-gradient(180deg, #00d4aa, #00b894); }
.lineup-slot .skill-band.g2  { background: linear-gradient(180deg, #00b894, #00a187); }
.lineup-slot .skill-band.g3  { background: linear-gradient(180deg, #00a187, #008f7a); }
.lineup-slot .skill-band.g4  { background: linear-gradient(180deg, #55a3ff, #4a90e2); }
.lineup-slot .skill-band.g5  { background: linear-gradient(180deg, #4a90e2, #3d7bc7); }
.lineup-slot .skill-band.g6  { background: linear-gradient(180deg, #3d7bc7, #3066ac); }
.lineup-slot .skill-band.g7  { background: linear-gradient(180deg, #9b7fd4, #8b6fc4); }
.lineup-slot .skill-band.g8  { background: linear-gradient(180deg, #8b6fc4, #7b5fb4); }
.lineup-slot .skill-band.g9  { background: linear-gradient(180deg, #7b5fb4, #6b4fa4); }
.lineup-slot .skill-band.g10 { background: linear-gradient(180deg, #6b6b7b, #5b5b6b); }
.lineup-slot .skill-band.g11 { background: linear-gradient(180deg, #5b5b6b, #4b4b5b); }
.lineup-slot .skill-band.g12 { background: linear-gradient(180deg, #4b4b5b, #3b3b4b); }
.lineup-planner .btn-download { margin-top: 1rem; padding: 0.6rem 1.25rem; background: linear-gradient(135deg, #6c5ce7, #a29bfe); border: none; border-radius: 5px; color: #fff; font-weight: 600; cursor: pointer; }
.lineup-planner .btn-download:hover { opacity: 0.95; box-shadow: 0 4px 15px rgba(108, 92, 231, 0.4); }
.lineup-planner .lineup-output-actions { display: flex; gap: 0.75rem; margin-top: 1rem; flex-wrap: wrap; }
.lineup-planner .btn-outline-light { background: transparent; border: 1px solid rgba(255,255,255,0.4); color: #e0e0e0; }
.lineup-planner .btn-outline-light:hover { background: rgba(255,255,255,0.1); color: #fff; }
@media (max-width: 768px) {
    .lineup-slot { grid-template-columns: 1.75rem 1.75rem 2rem 1fr auto; grid-template-rows: auto auto; gap: 0.5rem; }
    .lineup-slot .slot-num { grid-row: 1 / -1; align-self: center; }
    .lineup-slot .slot-type-wrap { grid-row: 1 / -1; align-self: center; }
    .lineup-slot .slot-1v1 { grid-column: 4 / 6; }
    .lineup-slot .player-a-wrap { grid-column: 4; grid-row: 1; }
    .lineup-slot .vs { grid-column: 5; grid-row: 1; }
    .lineup-slot .player-b-wrap { grid-column: 4; grid-row: 2; }
    .lineup-slot .slot-2v2 { grid-column: 4 / 6; }
    .lineup-slot .slot-reset { grid-column: 5; grid-row: 2; align-self: start; }
    .lineup-slot select { max-width: none; }
}
</style>

<div class="lineup-planner-wrapper">
<div class="lineup-planner">
    <div class="lineup-header">
        <h1>Lineup Planner</h1>
        <p class="help-text">Select Team A and Team B to set rosters. On each line pick a player from either side first; the other dropdown then shows only allowed opponents (by group rule). Use Reset to clear a line.</p>
    </div>

    <div class="card">
        <div class="team-select-section">
            <div class="team-side">
                <div class="team-logo-box" id="teamALogoBox">
                    <img id="teamALogo" src="" alt="" style="display: none;">
                    <span class="team-logo-label" id="teamALabel">Team A</span>
                </div>
                <select id="teamA" aria-label="Team A">
                    <option value="">— Select team —</option>
                    <?php foreach ($teamNames as $name): ?>
                        <option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <span class="vs-divider">vs</span>
            <div class="team-side">
                <div class="team-logo-box" id="teamBLogoBox">
                    <img id="teamBLogo" src="" alt="" style="display: none;">
                    <span class="team-logo-label" id="teamBLabel">Team B</span>
                </div>
                <select id="teamB" aria-label="Team B">
                    <option value="">— Select team —</option>
                    <?php foreach ($teamNames as $name): ?>
                        <option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row settings-row">
            <label for="lineupSeason">Season</label>
            <select id="lineupSeason" title="Season for records">
                <?php foreach ($seasons as $s): ?>
                    <option value="<?= (int) $s ?>" <?= $s == $currentSeason ? 'selected' : '' ?>><?= (int) $s ?></option>
                <?php endforeach; ?>
            </select>
            <label for="lineupDate">Date</label>
            <input type="date" id="lineupDate" title="Lineup / match date" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-row rules-row">
            <label for="rule">Group rule<span class="settings-hint">(± groups)</span></label>
            <input type="number" id="rule" min="0" max="20" value="2" title="Player can play up to this many groups above or below">
            <label for="playersPerGroup">Players per group</label>
            <input type="number" id="playersPerGroup" min="1" max="20" value="4" title="Group size for ranking">
        </div>
    </div>

    <div class="card">
        <h5 class="mb-3">Lineup (up to 6)</h5>
        <div class="lineup-slots" id="lineupSlots">
            <?php for ($i = 1; $i <= 6; $i++): ?>
            <div class="lineup-slot" data-slot="<?= $i ?>">
                <div class="skill-band" data-slot-band="<?= $i ?>"></div>
                <span class="slot-drag-handle" draggable="true" title="Drag to reorder" aria-label="Drag to reorder"><i class="fas fa-grip-vertical"></i></span>
                <span class="slot-num"><?= $i ?>.</span>
                <div class="slot-type-wrap">
                    <select class="slot-type" data-slot="<?= $i ?>" aria-label="Line type slot <?= $i ?>">
                        <option value="1vs1" selected>1vs1</option>
                        <option value="2v2">2v2</option>
                    </select>
                </div>
                <div class="slot-1v1">
                    <div class="player-a-wrap player-cell">
                        <select class="player-a" data-slot="<?= $i ?>" aria-label="Team A player slot <?= $i ?>">
                            <option value="">— Team A —</option>
                        </select>
                        <div class="player-info player-a-info" data-slot="<?= $i ?>"></div>
                    </div>
                    <span class="vs">vs</span>
                    <div class="player-b-wrap player-cell">
                        <select class="player-b" data-slot="<?= $i ?>" aria-label="Team B player slot <?= $i ?>">
                            <option value="">— Team B —</option>
                        </select>
                        <div class="player-info player-b-info" data-slot="<?= $i ?>"></div>
                    </div>
                </div>
                <div class="slot-2v2">
                    <div class="team-pair team-a-pair">
                        <select class="player-a1" data-slot="<?= $i ?>" aria-label="Team A player 1 slot <?= $i ?>"><option value="">— A1 —</option></select>
                        <div class="player-info player-a1-info" data-slot="<?= $i ?>"></div>
                        <select class="player-a2" data-slot="<?= $i ?>" aria-label="Team A player 2 slot <?= $i ?>"><option value="">— A2 —</option></select>
                        <div class="player-info player-a2-info" data-slot="<?= $i ?>"></div>
                    </div>
                    <span class="vs">vs</span>
                    <div class="team-pair team-b-pair">
                        <select class="player-b1" data-slot="<?= $i ?>" aria-label="Team B player 1 slot <?= $i ?>"><option value="">— B1 —</option></select>
                        <div class="player-info player-b1-info" data-slot="<?= $i ?>"></div>
                        <select class="player-b2" data-slot="<?= $i ?>" aria-label="Team B player 2 slot <?= $i ?>"><option value="">— B2 —</option></select>
                        <div class="player-info player-b2-info" data-slot="<?= $i ?>"></div>
                    </div>
                </div>
                <button type="button" class="slot-reset" data-slot="<?= $i ?>" title="Clear this line">Reset</button>
                <div class="differential-warning" data-slot="<?= $i ?>" role="status"></div>
            </div>
            <?php endfor; ?>
        </div>
        <div class="lineup-output-actions">
            <button type="button" class="btn btn-primary btn-download" id="btnDownloadHtml">Download HTML</button>
            <button type="button" class="btn btn-outline-light btn-download" id="btnPrintPdf">Print (PDF)</button>
        </div>
    </div>
</div>
</div>

<script>
(function() {
    const rankings = <?= json_encode($rankings) ?>;
    const teams = <?= json_encode($teams) ?>;
    const raceIcons = <?= json_encode($raceIcons) ?>;
    const teamLogos = <?= json_encode($teamLogos) ?>;

    const nameToRank = {};
    const nameToPlayer = {};
    function norm(n) { return (n || '').trim().toLowerCase(); }
    rankings.forEach(function(p) {
        const name = (p.name || '').trim();
        if (name) {
            var rank = parseInt(p.rank, 10);
            nameToRank[name] = rank;
            nameToRank[norm(name)] = rank;
            nameToPlayer[name] = { rank: rank, name: name, race: (p.race || 'R') };
            nameToPlayer[norm(name)] = nameToPlayer[name];
        }
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

    function groupForRank(rank, playersPerGroup) {
        if (!rank || rank < 1) return null;
        return Math.ceil(rank / playersPerGroup);
    }

    function allowedOpponentRanks(myRank, rule, playersPerGroup) {
        const g = groupForRank(myRank, playersPerGroup);
        if (g == null) return null;
        const maxRank = rankings.length;
        const numGroups = Math.ceil(maxRank / playersPerGroup);
        const minG = Math.max(1, g - rule);
        const maxG = Math.min(numGroups, g + rule);
        const minRank = (minG - 1) * playersPerGroup + 1;
        const maxR = Math.min(maxRank, maxG * playersPerGroup);
        return { minRank: minRank, maxRank: maxR };
    }

    const ruleEl = document.getElementById('rule');
    const playersPerGroupEl = document.getElementById('playersPerGroup');
    const teamAEl = document.getElementById('teamA');
    const teamBEl = document.getElementById('teamB');

    function getPlayers(teamKey) {
        if (!teamKey) return [];
        return teams[teamKey] || [];
    }

    /** Build player options sorted by rank ascending, label "(rank) Name - G{group}" */
    function formatPlayerOptions(playerNames) {
        const ppg = parseInt(playersPerGroupEl.value, 10) || 4;
        return (playerNames || []).map(function(name) {
            var p = getPlayer(name);
            var rank = p ? p.rank : null;
            var group = rank != null ? groupForRank(rank, ppg) : null;
            var displayLabel = rank != null ? '(' + rank + ') ' + name + ' - G' + group : name;
            return { name: name, displayLabel: displayLabel };
        }).sort(function(a, b) {
            var rA = rankForName(a.name);
            var rB = rankForName(b.name);
            if (rA == null && rB == null) return (a.name || '').localeCompare(b.name || '');
            if (rA == null) return 1;
            if (rB == null) return -1;
            return rA - rB;
        });
    }

    function fillSelect(select, options, valueAttr, labelAttr, emptyLabel) {
        const current = select.value;
        select.innerHTML = '';
        const empty = document.createElement('option');
        empty.value = '';
        empty.textContent = emptyLabel || '—';
        select.appendChild(empty);
        (options || []).forEach(function(opt) {
            const o = document.createElement('option');
            o.value = typeof opt === 'object' ? (opt[valueAttr] || opt[labelAttr] || '') : opt;
            o.textContent = typeof opt === 'object' ? (opt[labelAttr] || opt[valueAttr] || '') : opt;
            if (o.value === current) o.selected = true;
            select.appendChild(o);
        });
    }

    function allowedOpponents(playerName, opponentRoster) {
        const rule = parseInt(ruleEl.value, 10) || 0;
        const ppg = parseInt(playersPerGroupEl.value, 10) || 4;
        if (!playerName || !rule) return opponentRoster;
        const myRank = rankForName(playerName);
        if (myRank == null) return opponentRoster;
        const allowed = allowedOpponentRanks(myRank, rule, ppg);
        if (!allowed) return opponentRoster;
        return opponentRoster.filter(function(name) {
            const r = rankForName(name);
            if (r == null) return false;
            return r >= allowed.minRank && r <= allowed.maxRank;
        });
    }

    function getSlotRow(slotIndex) {
        return document.querySelector('.lineup-slot[data-slot="' + slotIndex + '"]');
    }

    function getSlotType(row) {
        const sel = row ? row.querySelector('.slot-type') : null;
        return (sel && sel.value === '2v2') ? '2v2' : '1vs1';
    }

    function updateSlot(slotIndex) {
        const row = getSlotRow(slotIndex);
        if (!row) return;
        const is2v2 = getSlotType(row) === '2v2';
        if (is2v2) {
            updateSlot2v2(slotIndex, row);
            return;
        }
        const selA = row.querySelector('.player-a');
        const selB = row.querySelector('.player-b');
        const teamAPlayers = getPlayers(teamAEl.value);
        const teamBPlayers = getPlayers(teamBEl.value);
        const playerA = selA ? selA.value : '';
        const playerB = selB ? selB.value : '';
        var optionsA = teamAPlayers;
        var optionsB = teamBPlayers;
        if (playerB) optionsA = allowedOpponents(playerB, teamAPlayers);
        if (playerA) optionsB = allowedOpponents(playerA, teamBPlayers);
        const curA = selA ? selA.value : '';
        const curB = selB ? selB.value : '';
        fillSelect(selA, formatPlayerOptions(optionsA), 'name', 'displayLabel', '— Team A —');
        fillSelect(selB, formatPlayerOptions(optionsB), 'name', 'displayLabel', '— Team B —');
        if (optionsA.indexOf(curA) !== -1) selA.value = curA;
        else if (curA) selA.value = '';
        if (optionsB.indexOf(curB) !== -1) selB.value = curB;
        else if (curB) selB.value = '';
        updatePlayerInfo(slotIndex);
    }

    function updateSlot2v2(slotIndex, row) {
        const teamAPlayers = getPlayers(teamAEl.value);
        const teamBPlayers = getPlayers(teamBEl.value);
        const optsA = formatPlayerOptions(teamAPlayers);
        const optsB = formatPlayerOptions(teamBPlayers);
        ['a1', 'a2', 'b1', 'b2'].forEach(function(key) {
            const sel = row.querySelector('.player-' + key);
            if (!sel) return;
            const cur = sel.value;
            fillSelect(sel, key.indexOf('a') === 0 ? optsA : optsB, 'name', 'displayLabel', key.indexOf('a') === 0 ? '— A' + key.slice(-1) + ' —' : '— B' + key.slice(-1) + ' —');
            if ((key.indexOf('a') === 0 ? teamAPlayers : teamBPlayers).indexOf(cur) !== -1) sel.value = cur;
            else if (cur) sel.value = '';
        });
        updatePlayerInfo2v2(slotIndex, row);
        updateDifferentialWarning(row);
    }

    function updateDifferentialWarning(row) {
        const warnEl = row.querySelector('.differential-warning');
        if (!warnEl) return;
        const a1 = row.querySelector('.player-a1');
        const a2 = row.querySelector('.player-a2');
        const b1 = row.querySelector('.player-b1');
        const b2 = row.querySelector('.player-b2');
        const hasA = (a1 && a1.value) || (a2 && a2.value);
        const hasB = (b1 && b1.value) || (b2 && b2.value);
        if (!hasA || !hasB) {
            warnEl.textContent = '';
            warnEl.classList.remove('is-visible');
            return;
        }
        const ppg = parseInt(playersPerGroupEl.value, 10) || 4;
        const g = function(name) {
            if (!name) return null;
            var p = getPlayer(name);
            return p ? groupForRank(p.rank, ppg) : null;
        };
        const ga1 = a1 && a1.value ? g(a1.value) : null;
        const ga2 = a2 && a2.value ? g(a2.value) : null;
        const gb1 = b1 && b1.value ? g(b1.value) : null;
        const gb2 = b2 && b2.value ? g(b2.value) : null;
        const sumA = (ga1 != null ? ga1 : 0) + (ga2 != null ? ga2 : 0);
        const sumB = (gb1 != null ? gb1 : 0) + (gb2 != null ? gb2 : 0);
        const diff = Math.abs(sumA - sumB);
        if (diff >= 3) {
            warnEl.textContent = 'Note: the skill differential based on groups is ' + diff + '.';
            warnEl.classList.add('is-visible');
        } else {
            warnEl.textContent = '';
            warnEl.classList.remove('is-visible');
        }
    }

    function updatePlayerInfo2v2(slotIndex, row) {
        var ppg = parseInt(playersPerGroupEl.value, 10) || 4;
        ['a1', 'a2', 'b1', 'b2'].forEach(function(key) {
            var sel = row.querySelector('.player-' + key);
            var infoEl = row.querySelector('.player-' + key + '-info');
            if (!infoEl) return;
            infoEl.innerHTML = '';
            var name = sel ? sel.value : '';
            var player = getPlayer(name);
            if (!player) return;
            var rank = player.rank;
            var group = groupForRank(rank, ppg);
            var race = player.race || 'R';
            var icon = raceIcons[race] || raceIcons['R'] || 'random_icon.png';
            infoEl.innerHTML = '<span class="rank-badge">' + rank + '</span><img src="images/' + icon + '" alt="" class="race-icon" title="' + race + '"><span class="group-badge">G' + group + '</span>';
        });
        var band = row.querySelector('.skill-band');
        if (band) {
            var sels = [row.querySelector('.player-a1'), row.querySelector('.player-a2'), row.querySelector('.player-b1'), row.querySelector('.player-b2')];
            var g = null;
            sels.forEach(function(sel) {
                if (sel && sel.value) {
                    var p = getPlayer(sel.value);
                    if (p) { var gg = groupForRank(p.rank, ppg); if (gg) g = g == null ? gg : Math.min(g, gg); }
                }
            });
            band.className = 'skill-band' + (g ? ' g' + g : '');
            band.style.display = g ? 'block' : 'none';
        }
    }

    function updatePlayerInfo(slotIndex) {
        var row = getSlotRow(slotIndex);
        if (!row) return;
        var selA = row.querySelector('.player-a');
        var selB = row.querySelector('.player-b');
        var infoA = row.querySelector('.player-a-info');
        var infoB = row.querySelector('.player-b-info');
        var band = row.querySelector('.skill-band');
        var ppg = parseInt(playersPerGroupEl.value, 10) || 4;
        function renderInfo(sel, infoEl) {
            if (!infoEl) return;
            infoEl.innerHTML = '';
            var name = sel ? sel.value : '';
            var player = getPlayer(name);
            if (!player) return;
            var rank = player.rank;
            var group = groupForRank(rank, ppg);
            var race = player.race || 'R';
            var icon = raceIcons[race] || raceIcons['R'] || 'random_icon.png';
            var groupClass = group ? ' g' + group : '';
            infoEl.innerHTML = '<span class="rank-badge">' + rank + '</span><img src="images/' + icon + '" alt="" class="race-icon" title="' + race + '"><span class="group-badge">G' + group + '</span>';
            return group;
        }
        var gA = renderInfo(selA, infoA);
        var gB = renderInfo(selB, infoB);
        if (band) {
            var g = gA || gB;
            band.className = 'skill-band' + (g ? ' g' + g : '');
            band.style.display = g ? 'block' : 'none';
        }
    }

    function refillAllSlots() {
        const teamAPlayers = getPlayers(teamAEl.value);
        const teamBPlayers = getPlayers(teamBEl.value);
        const optsA = formatPlayerOptions(teamAPlayers);
        const optsB = formatPlayerOptions(teamBPlayers);
        document.querySelectorAll('.lineup-slot').forEach(function(row) {
            const slotIndex = parseInt(row.getAttribute('data-slot'), 10);
            if (getSlotType(row) === '2v2') {
                ['a1', 'a2', 'b1', 'b2'].forEach(function(key) {
                    const sel = row.querySelector('.player-' + key);
                    if (sel) fillSelect(sel, key.indexOf('a') === 0 ? optsA : optsB, 'name', 'displayLabel', key.indexOf('a') === 0 ? '— A' + key.slice(-1) + ' —' : '— B' + key.slice(-1) + ' —');
                });
                updateSlot2v2(slotIndex, row);
            } else {
                const selA = row.querySelector('.player-a');
                const selB = row.querySelector('.player-b');
                fillSelect(selA, optsA, 'name', 'displayLabel', '— Team A —');
                fillSelect(selB, optsB, 'name', 'displayLabel', '— Team B —');
            }
        });
        [1,2,3,4,5,6].forEach(updateSlot);
    }

    function updateTeamLogos() {
        var logoA = document.getElementById('teamALogo');
        var labelA = document.getElementById('teamALabel');
        var logoB = document.getElementById('teamBLogo');
        var labelB = document.getElementById('teamBLabel');
        var teamA = teamAEl.value;
        var teamB = teamBEl.value;
        if (teamA && teamLogos[teamA]) {
            logoA.src = teamLogos[teamA];
            logoA.alt = teamA;
            logoA.style.display = 'block';
            labelA.textContent = teamA;
            labelA.classList.add('has-team');
        } else {
            logoA.src = '';
            logoA.style.display = 'none';
            labelA.textContent = 'Team A';
            labelA.classList.remove('has-team');
        }
        if (teamB && teamLogos[teamB]) {
            logoB.src = teamLogos[teamB];
            logoB.alt = teamB;
            logoB.style.display = 'block';
            labelB.textContent = teamB;
            labelB.classList.add('has-team');
        } else {
            logoB.src = '';
            logoB.style.display = 'none';
            labelB.textContent = 'Team B';
            labelB.classList.remove('has-team');
        }
    }
    teamAEl.addEventListener('change', function() { updateTeamLogos(); refillAllSlots(); });
    teamBEl.addEventListener('change', function() { updateTeamLogos(); refillAllSlots(); });
    ruleEl.addEventListener('input', function() { [1,2,3,4,5,6].forEach(updateSlot); });
    ruleEl.addEventListener('change', function() { [1,2,3,4,5,6].forEach(updateSlot); });
    playersPerGroupEl.addEventListener('input', function() { [1,2,3,4,5,6].forEach(updateSlot); });
    playersPerGroupEl.addEventListener('change', function() { [1,2,3,4,5,6].forEach(updateSlot); });

    document.querySelectorAll('.player-a').forEach(function(sel) {
        sel.addEventListener('change', function() {
            updateSlot(parseInt(sel.getAttribute('data-slot'), 10));
        });
    });
    document.querySelectorAll('.player-b').forEach(function(sel) {
        sel.addEventListener('change', function() {
            updateSlot(parseInt(sel.getAttribute('data-slot'), 10));
        });
    });

    document.querySelectorAll('.slot-type').forEach(function(sel) {
        sel.addEventListener('change', function() {
            const slotIndex = parseInt(sel.getAttribute('data-slot'), 10);
            const row = getSlotRow(slotIndex);
            if (!row) return;
            row.classList.toggle('slot-type-2v2', sel.value === '2v2');
            if (sel.value === '2v2') {
                const teamAPlayers = getPlayers(teamAEl.value);
                const teamBPlayers = getPlayers(teamBEl.value);
                ['a1', 'a2', 'b1', 'b2'].forEach(function(key) {
                    const s = row.querySelector('.player-' + key);
                    if (s) fillSelect(s, formatPlayerOptions(key.indexOf('a') === 0 ? teamAPlayers : teamBPlayers), 'name', 'displayLabel', key.indexOf('a') === 0 ? '— A' + key.slice(-1) + ' —' : '— B' + key.slice(-1) + ' —');
                });
                row.querySelector('.player-a').value = '';
                row.querySelector('.player-b').value = '';
            }
            updateSlot(slotIndex);
        });
    });

    document.querySelectorAll('.lineup-slots').forEach(function(container) {
        container.addEventListener('change', function(e) {
            var t = e.target;
            if (t.matches('.player-a1, .player-a2, .player-b1, .player-b2')) {
                var row = t.closest('.lineup-slot');
                if (row && getSlotType(row) === '2v2') {
                    var slotIndex = parseInt(row.getAttribute('data-slot'), 10);
                    updateSlot2v2(slotIndex, row);
                }
            }
        });
    });

    document.querySelectorAll('.slot-reset').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const slotIndex = parseInt(btn.getAttribute('data-slot'), 10);
            const row = getSlotRow(slotIndex);
            if (!row) return;
            if (getSlotType(row) === '2v2') {
                ['a1', 'a2', 'b1', 'b2'].forEach(function(key) {
                    const s = row.querySelector('.player-' + key);
                    if (s) s.value = '';
                });
                updateSlot2v2(slotIndex, row);
            } else {
                const selA = row.querySelector('.player-a');
                const selB = row.querySelector('.player-b');
                if (selA) selA.value = '';
                if (selB) selB.value = '';
                updateSlot(slotIndex);
            }
        });
    });

    (function initDragDrop() {
        var slotsContainer = document.getElementById('lineupSlots');
        if (!slotsContainer) return;
        var dragged = null;
        slotsContainer.addEventListener('dragstart', function(e) {
            if (!e.target.closest('.slot-drag-handle')) return;
            var row = e.target.closest('.lineup-slot');
            if (!row) return;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', row.getAttribute('data-slot'));
            dragged = row;
            row.classList.add('dragging');
        });
        slotsContainer.addEventListener('dragend', function(e) {
            if (dragged) dragged.classList.remove('dragging');
            slotsContainer.querySelectorAll('.lineup-slot.drag-over').forEach(function(r) { r.classList.remove('drag-over'); });
            dragged = null;
        });
        slotsContainer.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            var row = e.target.closest('.lineup-slot');
            if (row && dragged && row !== dragged) row.classList.add('drag-over');
        });
        slotsContainer.addEventListener('dragleave', function(e) {
            var row = e.target.closest('.lineup-slot');
            if (row) row.classList.remove('drag-over');
        });
        slotsContainer.addEventListener('drop', function(e) {
            e.preventDefault();
            var row = e.target.closest('.lineup-slot');
            if (!row || !dragged || row === dragged) return;
            row.classList.remove('drag-over');
            var next = row.nextElementSibling;
            if (next && next.classList.contains('lineup-slot')) dragged.parentNode.insertBefore(dragged, next);
            else dragged.parentNode.appendChild(dragged);
            var slots = slotsContainer.querySelectorAll('.lineup-slot');
            slots.forEach(function(s, i) {
                var idx = i + 1;
                s.setAttribute('data-slot', idx);
                var numEl = s.querySelector('.slot-num');
                if (numEl) numEl.textContent = idx + '.';
                s.querySelectorAll('[data-slot]').forEach(function(el) { el.setAttribute('data-slot', idx); });
            });
            dragged = null;
        });
    })();

    function buildLineupParams(extra) {
        const teamA = teamAEl.value;
        const teamB = teamBEl.value;
        const rule = ruleEl.value;
        const ppg = playersPerGroupEl.value;
        const season = document.getElementById('lineupSeason').value;
        const date = document.getElementById('lineupDate').value;
        const slots = [];
        document.querySelectorAll('.lineup-slot').forEach(function(row) {
            const type = getSlotType(row);
            if (type === '2v2') {
                const a1 = (row.querySelector('.player-a1') || {}).value || '';
                const a2 = (row.querySelector('.player-a2') || {}).value || '';
                const b1 = (row.querySelector('.player-b1') || {}).value || '';
                const b2 = (row.querySelector('.player-b2') || {}).value || '';
                if (a1 || a2 || b1 || b2) slots.push({ type: '2v2', playerA1: a1, playerA2: a2, playerB1: b1, playerB2: b2 });
            } else {
                const a = (row.querySelector('.player-a') || {}).value || '';
                const b = (row.querySelector('.player-b') || {}).value || '';
                if (a || b) slots.push({ type: '1vs1', playerA: a, playerB: b });
            }
        });
        var p = {
            teamA: teamA,
            teamB: teamB,
            rule: rule,
            playersPerGroup: ppg,
            season: season,
            date: date,
            slots: JSON.stringify(slots)
        };
        if (extra) for (var k in extra) p[k] = extra[k];
        return new URLSearchParams(p);
    }

    document.getElementById('btnDownloadHtml').addEventListener('click', function() {
        const teamA = teamAEl.value;
        const teamB = teamBEl.value;
        if (!teamA || !teamB) {
            alert('Please select both Team A and Team B.');
            return;
        }
        window.location.href = 'lineup_pdf.php?' + buildLineupParams({ output: 'html' }).toString();
    });

    document.getElementById('btnPrintPdf').addEventListener('click', function() {
        const teamA = teamAEl.value;
        const teamB = teamBEl.value;
        if (!teamA || !teamB) {
            alert('Please select both Team A and Team B.');
            return;
        }
        window.open('lineup_pdf.php?' + buildLineupParams().toString(), 'lineup_pdf', 'width=800,height=700,scrollbars=yes');
    });

    // No initial team selection – dropdowns stay empty until user picks
})();
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
