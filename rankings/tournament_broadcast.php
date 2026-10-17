<?php
/**
 * FSL Individual Championships — broadcast schedule (Code B → A → S anticipation).
 * Live-adjusts status from tournament results; broadcast numbers stay stable.
 *
 * ?cap=4           matches per broadcast (default 4)
 * ?format=json     machine-readable schedule
 * ?phase=0         allow mixing codes in one show (default phase=1)
 */

ob_start();
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/voting_logic.php';
require_once __DIR__ . '/tournament_logic.php';

$rankingsFile = __DIR__ . '/rankings.json';
$rankings = [];
if (is_readable($rankingsFile)) {
    $decoded = json_decode((string) file_get_contents($rankingsFile), true);
    if (is_array($decoded)) {
        $rankings = $decoded;
    }
}
foreach ($rankings as $i => $p) {
    $rank = (int) ($p['rank'] ?? $i + 1);
    $rankings[$i]['rank'] = $rank;
    $rankings[$i]['group'] = isset($p['group']) ? (int) $p['group'] : (int) ceil($rank / 4);
    $rankings[$i]['name'] = trim((string) ($p['name'] ?? ''));
}

$codeTiers = tournament_default_code_tiers(count($rankings));
$configFile = __DIR__ . '/rankings_config.json';
if (is_readable($configFile)) {
    $loaded = json_decode((string) file_get_contents($configFile), true);
    if (is_array($loaded)) {
        foreach (['codeS', 'codeA', 'codeB'] as $tier) {
            if (isset($loaded[$tier]['minRank'], $loaded[$tier]['maxRank'])) {
                $codeTiers[$tier] = [
                    'minRank' => (int) $loaded[$tier]['minRank'],
                    'maxRank' => (int) $loaded[$tier]['maxRank'],
                ];
            }
        }
    }
}

$canEdit = false;
$uid = '';
if (isset($_SESSION['user_id'])) {
    $uid = trim((string) $_SESSION['user_id']);
    if ($uid !== '') {
        $canEdit = rankings_user_has_permission($db, $uid, RANKINGS_PERM_SUPER);
    }
}

$cap = (int) ($_GET['cap'] ?? 4);
if ($cap < 1) {
    $cap = 1;
}
if ($cap > 12) {
    $cap = 12;
}
$phaseByCode = !isset($_GET['phase']) || (string) $_GET['phase'] !== '0';

$state = tournament_load_state();
$bracket = tournament_build_bracket($rankings, $state, $codeTiers);
$schedule = tournament_broadcast_schedule($bracket, [
    'matches_per_broadcast' => $cap,
    'code_order' => ['B', 'A', 'S'],
    'same_broadcast_chaining' => true,
    'phase_by_code' => $phaseByCode,
    'broadcast_dates' => $state['broadcast_dates'] ?? [],
    'broadcast_assignments' => $state['broadcast_assignments'] ?? [],
]);

$deps = tournament_broadcast_dependencies($bracket);
$dependents = tournament_broadcast_dependents_map($deps);
$totalBroadcasts = (int) ($schedule['total_broadcasts'] ?? 0);
$hasManual = ($state['broadcast_assignments'] ?? []) !== [];

/**
 * @return array{prev:bool,next:bool,prev_reason:?string,next_reason:?string}
 */
function tb_move_flags(string $matchId, array $byMatch, array $deps, array $dependents, int $totalBroadcasts): array
{
    $cur = (int) ($byMatch[$matchId]['broadcast'] ?? 0);
    $prevOk = false;
    $nextOk = false;
    $prevReason = null;
    $nextReason = null;
    if ($cur < 1) {
        return ['prev' => false, 'next' => false, 'prev_reason' => 'Unassigned', 'next_reason' => 'Unassigned'];
    }
    if ($cur <= 1) {
        $prevReason = 'Already first broadcast';
    } else {
        $err = tournament_broadcast_move_validate($matchId, $cur - 1, $byMatch, $deps, $dependents);
        if ($err === null) {
            $prevOk = true;
        } else {
            $prevReason = $err;
        }
    }
    $errNext = tournament_broadcast_move_validate($matchId, $cur + 1, $byMatch, $deps, $dependents);
    if ($errNext === null) {
        $nextOk = true;
    } else {
        $nextReason = $errNext;
    }
    return [
        'prev' => $prevOk,
        'next' => $nextOk,
        'prev_reason' => $prevReason,
        'next_reason' => $nextReason,
    ];
}

$format = strtolower(trim((string) ($_GET['format'] ?? 'html')));
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'title' => (string) ($state['title'] ?? 'FSL Individual Championships'),
        'participant_count' => (int) ($bracket['participant_count'] ?? 0),
        'updated_at' => $state['updated_at'] ?? null,
        'schedule' => $schedule,
        'can_edit' => $canEdit,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @param mixed $name
 */
function tb_player($name): string
{
    $n = trim((string) ($name ?? ''));
    if ($n === '') {
        return 'TBD';
    }
    return $n;
}

/**
 * @param array<string,mixed> $m
 */
function tb_status_class(array $m): string
{
    $st = (string) ($m['status'] ?? '');
    if ($st === 'done') {
        return 'done';
    }
    if ($st === 'live') {
        return 'live';
    }
    if ($st === 'ready') {
        return 'ready';
    }
    return 'waiting';
}

/**
 * Numeric score label for schedule rows (en-dash, like live bracket / d2 diagram).
 * Null side shows as –. Returns null when neither score is set.
 *
 * @param array<string,mixed> $m
 */
function tb_score_text(array $m): ?string
{
    $s1 = $m['score1'] ?? null;
    $s2 = $m['score2'] ?? null;
    $has1 = $s1 !== null && $s1 !== '';
    $has2 = $s2 !== null && $s2 !== '';
    if (!$has1 && !$has2) {
        return null;
    }
    $a = $has1 ? (string) (int) $s1 : '–';
    $b = $has2 ? (string) (int) $s2 : '–';
    return $a . '–' . $b;
}

$inCount = (int) ($bracket['participant_count'] ?? 0);
$current = $schedule['current_broadcast'];
$currentWhen = null;
if ($current !== null) {
    foreach ($schedule['broadcasts'] as $bShow) {
        if ((int) ($bShow['broadcast'] ?? 0) === (int) $current) {
            $currentWhen = $bShow['broadcast_when'] ?? null;
            break;
        }
    }
}
$doneMatches = 0;
foreach ($schedule['by_match'] as $m) {
    if (($m['status'] ?? '') === 'done') {
        $doneMatches++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Broadcast schedule · Individual Championships</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root {
            --bg: #0b1020;
            --panel: #121a2c;
            --text: #edf2ff;
            --muted: #9aa7bd;
            --line: rgba(255,255,255,.12);
            --accent: #a29bfe;
            --ready: #00d4aa;
            --live: #ffd76a;
            --done: #6bcf8e;
            --waiting: #7a8699;
        }
        body {
            margin: 0;
            color: var(--text);
            background: var(--bg);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, sans-serif;
            min-height: 100vh;
        }
        .wrap { width: min(960px, calc(100% - 28px)); margin: 0 auto; padding: 36px 0 64px; }
        h1 { font-size: 1.75rem; letter-spacing: -.02em; margin: 0 0 6px; }
        .sub { color: var(--muted); margin-bottom: 22px; }
        .stat-row { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; }
        .stat {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px 14px;
            min-width: 120px;
        }
        .stat strong { display: block; font-size: 1.35rem; line-height: 1.1; }
        .stat span { color: var(--muted); font-size: .82rem; }
        .controls {
            display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
            margin-bottom: 22px;
        }
        .controls a, .controls .btn-sm {
            border-radius: 999px;
        }
        .btn-outline-light { border-color: var(--line); color: var(--muted); }
        .btn-outline-light.active, .btn-outline-light:hover {
            background: rgba(162,155,254,.2);
            border-color: var(--accent);
            color: #fff;
        }
        .show-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 14px;
            margin-bottom: 14px;
            overflow: hidden;
        }
        .show-card.current { border-color: rgba(0,212,170,.55); }
        .show-card.complete { opacity: .72; }
        .show-head {
            display: flex; flex-wrap: wrap; gap: 8px 14px; align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid var(--line);
        }
        .show-head h2 { font-size: 1.05rem; margin: 0; }
        .pill {
            display: inline-block;
            font-size: .75rem;
            font-weight: 600;
            padding: 3px 9px;
            border-radius: 999px;
            border: 1px solid var(--line);
            color: var(--muted);
        }
        .pill.when { border-color: rgba(162,155,254,.45); color: #c7d2fe; }
        .pill.now { border-color: rgba(0,212,170,.5); color: var(--ready); }
        .pill.code-B { color: #9ad0ff; }
        .pill.code-A { color: #ffc78a; }
        .pill.code-S { color: #ffd700; }
        .pill.champ {
            font-weight: 700;
            letter-spacing: .01em;
        }
        .pill.champ.code-B {
            border-color: rgba(205, 127, 50, .55);
            color: #cd7f32;
            background: rgba(205, 127, 50, .12);
        }
        .pill.champ.code-A {
            border-color: rgba(192, 192, 192, .5);
            color: #d0d0d0;
            background: rgba(192, 192, 192, .1);
        }
        .pill.champ.code-S {
            border-color: rgba(255, 215, 0, .5);
            color: #ffd700;
            background: rgba(255, 215, 0, .1);
        }
        table.matches { width: 100%; margin: 0; }
        table.matches td, table.matches th {
            border-top: 1px solid var(--line);
            padding: 10px 14px;
            vertical-align: middle;
            font-size: .92rem;
        }
        table.matches tr:first-child td { border-top: 0; }
        .slot { color: var(--muted); width: 3rem; font-variant-numeric: tabular-nums; }
        .status {
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            width: 5.5rem;
        }
        .status.ready { color: var(--ready); }
        .status.live { color: var(--live); }
        .status.done { color: var(--done); }
        .status.waiting { color: var(--waiting); }
        .vs { color: var(--muted); margin: 0 .35rem; }
        .score { color: var(--muted); font-variant-numeric: tabular-nums; }
        .note { color: var(--muted); font-size: .85rem; margin-top: 18px; line-height: 1.45; }
        a.link { color: var(--accent); }
        code { color: #c7d2fe; }
        .move-cell { width: 5.5rem; white-space: nowrap; text-align: right; }
        .btn-move {
            border: 1px solid var(--line);
            background: transparent;
            color: var(--muted);
            border-radius: 8px;
            padding: 2px 8px;
            line-height: 1.2;
            font-size: .85rem;
        }
        .btn-move:hover:not(:disabled) {
            border-color: var(--accent);
            color: #fff;
            background: rgba(162,155,254,.15);
        }
        .btn-move:disabled { opacity: .35; cursor: not-allowed; }
        .admin-hint { color: var(--muted); font-size: .82rem; margin: -8px 0 16px; }
        #tbFlash {
            display: none;
            margin-bottom: 14px;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid rgba(255,120,120,.45);
            background: rgba(255,80,80,.12);
            color: #ffc9c9;
            font-size: .9rem;
        }
    </style>
</head>
<body>
<?php include_once __DIR__ . '/../includes/nav.php'; ?>
<div class="wrap">
    <h1>Broadcast schedule</h1>
    <p class="sub">
        <?= (int) $inCount ?> players · <?= (int) $schedule['total_matches'] ?> matches ·
        Code B → A → S (Code S held for anticipation) ·
        <?= (int) $cap ?> matches / show<?= $phaseByCode ? '' : ' · mixed codes' ?>
        <?= $hasManual ? ' · manual overrides' : '' ?>
    </p>
    <?php if ($canEdit): ?>
        <p class="admin-hint">
            Default <?= (int) $cap ?>/show · move stated side-board matches between adjacent shows with ← / →.
            Winner-chain deps stay intact. After compact/renumber, re-check broadcast dates.
        </p>
    <?php endif; ?>
    <div id="tbFlash"></div>

    <div class="stat-row">
        <div class="stat"><strong><?= (int) $schedule['total_broadcasts'] ?></strong><span>Broadcasts</span></div>
        <div class="stat"><strong><?= (int) $doneMatches ?>/<?= (int) $schedule['total_matches'] ?></strong><span>Matches done</span></div>
        <div class="stat"><strong><?= $current !== null ? htmlspecialchars(tournament_broadcast_label((int) $current), ENT_QUOTES, 'UTF-8') : 'Done' ?></strong><span><?= $currentWhen ? htmlspecialchars((string) $currentWhen, ENT_QUOTES, 'UTF-8') : 'Current show' ?></span></div>
    </div>

    <div class="controls">
        <span class="text-muted small mr-1">Cap:</span>
        <?php foreach ([4, 5, 6] as $optCap): ?>
            <a class="btn btn-sm btn-outline-light<?= $cap === $optCap ? ' active' : '' ?>"
               href="?cap=<?= (int) $optCap ?><?= $phaseByCode ? '' : '&amp;phase=0' ?>">
                <?= (int) $optCap ?>/show
            </a>
        <?php endforeach; ?>
        <a class="btn btn-sm btn-outline-light" href="tournament.php">Bracket</a>
        <a class="btn btn-sm btn-outline-light" href="tournament_diagram.php?action=view&amp;format=png&amp;scale=50" target="_blank" rel="noopener">Diagram</a>
        <a class="btn btn-sm btn-outline-light" href="?cap=<?= (int) $cap ?>&amp;format=json">JSON</a>
        <?php if ($canEdit && $hasManual): ?>
            <button type="button" class="btn btn-sm btn-outline-light" id="btnResetAssignments">Reset overrides</button>
        <?php endif; ?>
    </div>

    <?php foreach ($schedule['broadcasts'] as $show): ?>
        <?php
        $bNum = (int) $show['broadcast'];
        $isCurrent = ($current !== null && $bNum === (int) $current);
        $cls = 'show-card';
        if ($isCurrent) {
            $cls .= ' current';
        }
        if (!empty($show['all_done'])) {
            $cls .= ' complete';
        }
        ?>
        <section class="<?= $cls ?>">
            <div class="show-head">
                <h2><?= htmlspecialchars((string) $show['broadcast_label'], ENT_QUOTES, 'UTF-8') ?></h2>
                <span class="pill when"><?= htmlspecialchars((string) ($show['broadcast_when'] ?? 'TBD'), ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($isCurrent): ?>
                    <span class="pill now">Now</span>
                <?php elseif (!empty($show['all_done'])): ?>
                    <span class="pill">Complete</span>
                <?php endif; ?>
                <?php foreach ($show['codes'] as $code): ?>
                    <span class="pill code-<?= htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8') ?>">Code <?= htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endforeach; ?>
                <span class="pill"><?= (int) $show['match_count'] ?> matches</span>
            </div>
            <table class="matches">
                <tbody>
                <?php foreach ($show['matches'] as $m): ?>
                    <?php
                    $st = tb_status_class($m);
                    $mid = (string) ($m['id'] ?? '');
                    $flags = $canEdit && $mid !== ''
                        ? tb_move_flags($mid, $schedule['by_match'], $deps, $dependents, $totalBroadcasts)
                        : null;
                    ?>
                    <tr>
                        <?php
                        $champCode = strtoupper(trim((string) ($m['code_championship'] ?? '')));
                        $champLabel = trim((string) ($m['code_championship_label'] ?? ''));
                        if ($champLabel === '' && $champCode !== '') {
                            $champLabel = tournament_code_championship_label($champCode);
                        }
                        ?>
                        <td class="slot">#<?= (int) $m['slot'] ?></td>
                        <td class="status <?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <span class="pill code-<?= htmlspecialchars((string) $m['code'], ENT_QUOTES, 'UTF-8') ?>">G<?= (int) $m['group'] ?></span>
                            <?= htmlspecialchars(tb_player($m['p1'] ?? null), ENT_QUOTES, 'UTF-8') ?>
                            <span class="vs">vs</span>
                            <?= htmlspecialchars(tb_player($m['p2'] ?? null), ENT_QUOTES, 'UTF-8') ?>
                            <?php
                            $scoreTxt = tb_score_text($m);
                            if ($scoreTxt !== null):
                            ?>
                                <span class="vs score">· <?= htmlspecialchars($scoreTxt, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                            <?php if ($champLabel !== ''): ?>
                                <span class="pill champ<?= $champCode !== '' ? ' code-' . htmlspecialchars($champCode, ENT_QUOTES, 'UTF-8') : '' ?>"><?= htmlspecialchars($champLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                            <?php if ($st === 'done' && !empty($m['winner'])): ?>
                                <span class="vs">· W:</span>
                                <?= htmlspecialchars((string) $m['winner'], ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </td>
                        <?php if ($canEdit && $flags !== null): ?>
                            <td class="move-cell">
                                <button type="button" class="btn-move" data-move="prev" data-match="<?= htmlspecialchars($mid, ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $flags['prev'] ? '' : 'disabled' ?>
                                    title="<?= htmlspecialchars((string) ($flags['prev_reason'] ?? 'Move to previous broadcast'), ENT_QUOTES, 'UTF-8') ?>">←</button>
                                <button type="button" class="btn-move" data-move="next" data-match="<?= htmlspecialchars($mid, ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $flags['next'] ? '' : 'disabled' ?>
                                    title="<?= htmlspecialchars((string) ($flags['next_reason'] ?? 'Move to next broadcast'), ENT_QUOTES, 'UTF-8') ?>">→</button>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endforeach; ?>

    <p class="note">
        Same-show chaining is on (later slots may use earlier results from that night).
        Winner-chain order is enforced across broadcasts; adjacent moves are for parallel stated matches.
        Compacting empty shows renumbers broadcasts — dates stay on absolute numbers (re-check after moves).
        Reuse via <code>tournament_broadcast_schedule()</code> /
        <code>tournament_annotate_broadcasts()</code> in <code>tournament_logic.php</code>.
        <a class="link" href="tournament.php">Open live bracket</a>
    </p>
</div>
<?php if ($canEdit): ?>
<script>
(function () {
    const flash = document.getElementById('tbFlash');
    function showErr(msg) {
        if (!flash) return;
        flash.textContent = msg || 'Request failed';
        flash.style.display = 'block';
    }
    async function api(action, payload) {
        const res = await fetch('tournament.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ action }, payload || {}))
        });
        let data;
        try { data = await res.json(); }
        catch (e) { showErr('Bad server response'); throw e; }
        if (!data.success) {
            showErr(data.error || 'Request failed');
            throw new Error(data.error || 'fail');
        }
        return data;
    }
    document.querySelectorAll('.btn-move').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (btn.disabled) return;
            const matchId = btn.getAttribute('data-match');
            const direction = btn.getAttribute('data-move');
            btn.disabled = true;
            try {
                await api('move_broadcast_match', { match_id: matchId, direction });
                location.reload();
            } catch (e) {
                location.reload();
            }
        });
    });
    const resetBtn = document.getElementById('btnResetAssignments');
    if (resetBtn) {
        resetBtn.addEventListener('click', async () => {
            if (!confirm('Clear manual broadcast overrides and restore auto pack?')) return;
            try {
                await api('reset_broadcast_assignments', {});
                location.reload();
            } catch (e) { /* flash already set */ }
        });
    }
})();
</script>
<?php endif; ?>
</body>
</html>
