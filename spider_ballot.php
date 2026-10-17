<?php
/**
 * One-vote spider ballot opened from a Mathison !spider whisper.
 * The token is the voter. Sharing the link does not create a second vote.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/spider_ballot.php';
require_once __DIR__ . '/includes/team_logo.php';

date_default_timezone_set($config['timezone'] ?? 'America/New_York');

$token = trim((string) ($_GET['t'] ?? $_POST['t'] ?? ''));
$error = '';
$notice = '';
$row = null;
$matches = [];
$selected = null;
$choices = [];
$liveMatchId = 0;

if (!spider_tables_ready($db)) {
    $error = 'Spider ballots are not set up yet.';
} else {
    try {
        spider_fold_expired_sessions($db);
        $row = spider_token_row($db, $token);
        if (!$row) {
            $error = 'This voting link is not valid.';
        } else {
            $active = spider_active_session($db);
            $liveMatchId = $active ? (int) $active['fsl_match_id'] : 0;
            $pin = [(int) $row['preselect_match_id']];
            if ($liveMatchId > 0) {
                $pin[] = $liveMatchId;
            }
            $matches = spider_recent_matches($db, $pin);
            if ($liveMatchId > 0) {
                usort($matches, function ($a, $b) use ($liveMatchId) {
                    $aLive = (int) $a['fsl_match_id'] === $liveMatchId;
                    $bLive = (int) $b['fsl_match_id'] === $liveMatchId;
                    if ($aLive === $bLive) {
                        return 0;
                    }
                    return $aLive ? -1 : 1;
                });
            }

            $requested = (int) ($_POST['match_id'] ?? $_GET['match'] ?? 0);
            if ($requested <= 0) {
                if ($liveMatchId > 0) {
                    $requested = $liveMatchId;
                } elseif ($matches) {
                    $requested = (int) $matches[0]['fsl_match_id'];
                } else {
                    $requested = (int) $row['preselect_match_id'];
                }
            }
            foreach ($matches as $match) {
                if ((int) $match['fsl_match_id'] === $requested) {
                    $selected = $match;
                    break;
                }
            }
            if (!$selected && $matches) {
                $selected = $matches[0];
            }

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                if (!$selected) {
                    $error = 'That match is not in the recent match list.';
                } else {
                    $posted = [];
                    foreach (SPIDER_ATTRIBUTES as $attr) {
                        if (array_key_exists($attr, $_POST)) {
                            $posted[$attr] = (string) $_POST[$attr];
                        }
                    }
                    spider_save_choices($db, $row['twitch_login'], (int) $selected['fsl_match_id'], $posted);
                    header('Location: spider_ballot.php?t=' . urlencode($token) . '&match=' . (int) $selected['fsl_match_id'] . '&saved=1');
                    exit;
                }
            }

            if ($selected) {
                $choices = spider_choices_for($db, $row['twitch_login'], (int) $selected['fsl_match_id']);
            }
            if (($_GET['saved'] ?? '') === '1') {
                $notice = 'Saved. You can pick another match below. This link is tied to your Twitch login — sharing it lets someone else change your vote.';
            }
        }
    } catch (Throwable $e) {
        error_log('spider_ballot: ' . $e->getMessage());
        $error = 'Could not save that vote. Try again.';
    }
}

$labels = [
    'micro' => 'Micro',
    'macro' => 'Macro',
    'clutch' => 'Clutch',
    'creativity' => 'Creativity',
    'aggression' => 'Aggression',
    'strategy' => 'Strategy',
];
$hints = [
    'micro' => 'Unit control in fights',
    'macro' => 'Economy and production',
    'clutch' => 'Big moments under pressure',
    'creativity' => 'Surprising or off-meta plays',
    'aggression' => 'Pressure and attacks',
    'strategy' => 'Plan and adaptation',
];

function spider_checked(array $choices, string $attr, string $value): string
{
    if (!isset($choices[$attr])) {
        return '';
    }
    return (string) $choices[$attr]['side'] === $value ? ' checked' : '';
}

$matchDetail = null;
if ($selected && !$error) {
    $matchDetail = spider_ballot_match_detail($db, (int) $selected['fsl_match_id']);
}

$p1Hint = $matchDetail ? htmlspecialchars($matchDetail['player1_name']) : ($selected ? htmlspecialchars($selected['player1_name']) : 'P1');
$p2Hint = $matchDetail ? htmlspecialchars($matchDetail['player2_name']) : ($selected ? htmlspecialchars($selected['player2_name']) : 'P2');

$pageTitle = 'Rate a match';
include __DIR__ . '/includes/header.php';
?>

<style>
    .spider-ballot-wrap {
        --spider-p1: #ff5252;
        --spider-p1-soft: rgba(220, 53, 69, 0.18);
        --spider-p1-border: rgba(255, 82, 82, 0.9);
        --spider-p2: #4dabf7;
        --spider-p2-soft: rgba(13, 110, 253, 0.18);
        --spider-p2-border: rgba(77, 171, 247, 0.9);
        max-width: 720px;
        margin: 0 auto 2rem;
    }

    .spider-ballot-hero {
        text-align: center;
        margin-bottom: 1.25rem;
    }

    .spider-ballot-hero h1 {
        font-size: 1.75rem;
        font-weight: 700;
        color: #00d4ff;
        margin-bottom: 0.35rem;
    }

    .spider-ballot-hero p {
        color: #adb5bd;
        margin: 0;
        font-size: 0.95rem;
    }

    .spider-chat-option {
        border-left: 4px solid #9146ff;
        background: rgba(145, 70, 255, 0.08);
        border-radius: 0.5rem;
        padding: 1rem 1.15rem;
        margin-bottom: 1.25rem;
    }

    .spider-chat-option h2 {
        font-size: 1rem;
        font-weight: 700;
        margin: 0 0 0.5rem;
        color: #e9ecef;
    }

    .spider-chat-option p {
        margin: 0 0 0.65rem;
        font-size: 0.9rem;
        color: #ced4da;
        line-height: 1.45;
    }

    .spider-chat-codes {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        font-size: 0.85rem;
        background: rgba(0, 0, 0, 0.35);
        border-radius: 0.35rem;
        padding: 0.65rem 0.75rem;
        color: #f8f9fa;
        margin-bottom: 0.65rem;
        word-break: break-word;
    }

    .spider-chat-legend {
        font-size: 0.82rem;
        color: #adb5bd;
        margin: 0;
    }

    .spider-chat-legend code {
        color: #00d4ff;
        background: transparent;
        padding: 0;
    }

    .spider-ballot-card {
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.65rem;
        padding: 1.25rem 1.35rem;
    }

    .spider-voter-tag {
        font-size: 0.85rem;
        color: #adb5bd;
        margin-bottom: 1rem;
    }

    .spider-voter-tag strong {
        color: #00d4ff;
    }

    .spider-match-select label {
        font-weight: 600;
        color: #dee2e6;
    }

    .spider-match-select .form-control {
        background: #1a1a1a;
        border-color: #495057;
        color: #f8f9fa;
    }

    .spider-match-note {
        font-size: 0.82rem;
        color: #868e96;
        margin: 0.5rem 0 1.25rem;
    }

    .spider-skill-block {
        padding: 0.85rem 0;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }

    .spider-skill-block:last-of-type {
        border-bottom: none;
        padding-bottom: 0;
    }

    .spider-skill-name {
        font-weight: 600;
        color: #f8f9fa;
        margin-bottom: 0.15rem;
    }

    .spider-skill-hint {
        font-size: 0.8rem;
        color: #868e96;
        margin-bottom: 0.55rem;
    }

    .spider-vote-choices {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.45rem;
    }

    @media (min-width: 480px) {
        .spider-vote-choices {
            grid-template-columns: repeat(4, 1fr);
        }
    }

    .spider-vote-legend {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem 1rem;
        align-items: center;
        font-size: 0.82rem;
        color: #adb5bd;
        margin: 0 0 1rem;
        padding: 0.65rem 0.75rem;
        background: rgba(0, 0, 0, 0.25);
        border-radius: 0.45rem;
    }

    .spider-vote-legend strong {
        color: #dee2e6;
        font-weight: 600;
    }

    .spider-side-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-weight: 600;
    }

    .spider-side-chip::before {
        content: '';
        width: 0.65rem;
        height: 0.65rem;
        border-radius: 50%;
        flex-shrink: 0;
    }

    .spider-side-chip.p1 {
        color: var(--spider-p1);
    }

    .spider-side-chip.p1::before {
        background: var(--spider-p1);
        box-shadow: 0 0 8px rgba(255, 82, 82, 0.5);
    }

    .spider-side-chip.p2 {
        color: var(--spider-p2);
    }

    .spider-side-chip.p2::before {
        background: var(--spider-p2);
        box-shadow: 0 0 8px rgba(77, 171, 247, 0.45);
    }

    .spider-skills-heading {
        font-size: 1rem;
        font-weight: 700;
        color: #00d4ff;
        margin: 0 0 0.35rem;
    }

    .spider-skills-sub {
        font-size: 0.82rem;
        color: #868e96;
        margin: 0 0 0.75rem;
    }

    .spider-vote-choices label {
        position: relative;
        margin: 0;
        text-align: center;
        border: 2px solid #495057;
        border-radius: 0.4rem;
        padding: 0.5rem 0.35rem 0.55rem;
        font-size: 0.82rem;
        background: #1a1a1a;
        color: #dee2e6;
        cursor: pointer;
        transition: border-color 0.15s, background 0.15s, box-shadow 0.15s;
    }

    .spider-vote-choices label::before {
        content: '';
        display: block;
        height: 3px;
        border-radius: 2px;
        margin-bottom: 0.4rem;
        opacity: 0.85;
    }

    .spider-vote-choices input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .spider-vote-choices label.choice-p1 {
        border-color: rgba(255, 82, 82, 0.35);
    }

    .spider-vote-choices label.choice-p1::before {
        background: var(--spider-p1);
    }

    .spider-vote-choices label.choice-p1:hover {
        border-color: var(--spider-p1-border);
        background: var(--spider-p1-soft);
    }

    .spider-vote-choices label.choice-p1:has(input:checked) {
        background: var(--spider-p1-soft);
        border-color: var(--spider-p1);
        color: #fff;
        font-weight: 700;
        box-shadow: 0 0 0 1px var(--spider-p1);
    }

    .spider-vote-choices label.choice-p2 {
        border-color: rgba(77, 171, 247, 0.35);
    }

    .spider-vote-choices label.choice-p2::before {
        background: var(--spider-p2);
    }

    .spider-vote-choices label.choice-p2:hover {
        border-color: var(--spider-p2-border);
        background: var(--spider-p2-soft);
    }

    .spider-vote-choices label.choice-p2:has(input:checked) {
        background: var(--spider-p2-soft);
        border-color: var(--spider-p2);
        color: #fff;
        font-weight: 700;
        box-shadow: 0 0 0 1px var(--spider-p2);
    }

    .spider-vote-choices label.choice-tie::before {
        background: #868e96;
    }

    .spider-vote-choices label.choice-tie:hover,
    .spider-vote-choices label.skip-choice:hover {
        border-color: #868e96;
    }

    .spider-vote-choices label.choice-tie:has(input:checked) {
        background: rgba(134, 142, 150, 0.2);
        border-color: #adb5bd;
        font-weight: 600;
    }

    .spider-vote-choices label.skip-choice::before {
        background: transparent;
        height: 0;
        margin: 0;
    }

    .spider-vote-choices label.skip-choice:has(input:checked) {
        background: rgba(108, 117, 125, 0.25);
        border-color: #6c757d;
        color: #ced4da;
    }

    .spider-save-btn {
        margin-top: 1.25rem;
        background: #00d4ff;
        border-color: #00d4ff;
        color: #0f0c29;
        font-weight: 600;
    }

    .spider-save-btn:hover {
        background: #33ddff;
        border-color: #33ddff;
        color: #0f0c29;
    }

    .spider-guide-link {
        font-size: 0.88rem;
    }

    .spider-match-showcase {
        background: linear-gradient(145deg, rgba(15, 12, 41, 0.85), rgba(36, 36, 62, 0.6));
        border: 1px solid rgba(0, 212, 255, 0.25);
        border-radius: 0.65rem;
        padding: 1.15rem 1rem 1rem;
        margin: 1rem 0 1.25rem;
    }

    .spider-match-showcase-head {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        margin-bottom: 1rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }

    .spider-match-showcase-head h2 {
        font-size: 1.1rem;
        font-weight: 700;
        margin: 0;
        color: #f8f9fa;
    }

    .spider-match-showcase-head h2 a {
        color: inherit;
        text-decoration: none;
    }

    .spider-match-showcase-head h2 a:hover {
        color: #00d4ff;
    }

    .spider-match-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
    }

    .spider-match-badge {
        font-size: 0.72rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 0.2rem 0.5rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.08);
        color: #dee2e6;
    }

    .spider-match-badge.live {
        background: rgba(220, 53, 69, 0.25);
        color: #ff8a9a;
    }

    .spider-match-badge.code {
        background: rgba(0, 212, 255, 0.15);
        color: #00d4ff;
    }

    .spider-match-duel {
        display: flex;
        align-items: stretch;
        justify-content: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .spider-duel-player {
        flex: 1 1 140px;
        max-width: 220px;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        border-radius: 0.55rem;
        padding: 0.65rem 0.5rem 0.75rem;
    }

    .spider-duel-player.side-p1 {
        border: 2px solid var(--spider-p1-border);
        background: linear-gradient(180deg, var(--spider-p1-soft), transparent 70%);
        box-shadow: inset 0 0 24px rgba(255, 82, 82, 0.08);
    }

    .spider-duel-player.side-p2 {
        border: 2px solid var(--spider-p2-border);
        background: linear-gradient(180deg, var(--spider-p2-soft), transparent 70%);
        box-shadow: inset 0 0 24px rgba(77, 171, 247, 0.08);
    }

    .spider-duel-intro {
        width: 100%;
        max-width: 200px;
        height: 130px;
        border-radius: 0.5rem;
        overflow: hidden;
        border: 2px solid rgba(255, 255, 255, 0.12);
        background: rgba(0, 0, 0, 0.35);
        margin-bottom: 0.65rem;
    }

    .spider-duel-player.side-p1 .spider-duel-intro {
        border-color: var(--spider-p1);
    }

    .spider-duel-player.side-p2 .spider-duel-intro {
        border-color: var(--spider-p2);
    }

    .spider-duel-intro .player-intro-container {
        width: 100%;
        height: 100%;
    }

    .spider-duel-name {
        font-weight: 700;
        font-size: 1rem;
        color: #fff;
        line-height: 1.25;
    }

    .spider-duel-name a {
        color: inherit;
        text-decoration: none;
    }

    .spider-duel-player.side-p1 .spider-duel-name a:hover {
        color: var(--spider-p1);
    }

    .spider-duel-player.side-p2 .spider-duel-name a:hover {
        color: var(--spider-p2);
    }

    .spider-duel-meta {
        font-size: 0.78rem;
        color: #adb5bd;
        margin-top: 0.25rem;
    }

    .spider-race-pill {
        display: inline-block;
        font-size: 0.7rem;
        font-weight: 700;
        padding: 0.1rem 0.35rem;
        border-radius: 0.25rem;
        margin-left: 0.25rem;
        vertical-align: middle;
    }

    .side-p1 .spider-race-pill {
        background: var(--spider-p1-soft);
        color: var(--spider-p1);
    }

    .side-p2 .spider-race-pill {
        background: var(--spider-p2-soft);
        color: var(--spider-p2);
    }

    .spider-side-tag {
        display: inline-block;
        font-size: 0.65rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        padding: 0.12rem 0.4rem;
        border-radius: 0.25rem;
        margin-bottom: 0.35rem;
    }

    .side-p1 .spider-side-tag {
        background: var(--spider-p1-soft);
        color: var(--spider-p1);
        border: 1px solid var(--spider-p1-border);
    }

    .side-p2 .spider-side-tag {
        background: var(--spider-p2-soft);
        color: var(--spider-p2);
        border: 1px solid var(--spider-p2-border);
    }

    .spider-duel-center {
        flex: 0 0 auto;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-width: 4rem;
        padding: 0.5rem 0;
    }

    .spider-duel-vs {
        font-size: 0.85rem;
        font-weight: 700;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }

    .spider-duel-score {
        font-size: 1.35rem;
        font-weight: 800;
        color: #00d4ff;
        margin-top: 0.25rem;
    }

    .spider-match-facts {
        margin-top: 1rem;
        padding-top: 0.85rem;
        border-top: 1px solid rgba(255, 255, 255, 0.08);
        font-size: 0.85rem;
        color: #ced4da;
        line-height: 1.5;
    }

    .spider-match-facts p {
        margin: 0 0 0.35rem;
    }

    .spider-match-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 0.75rem;
    }

    .spider-match-actions .btn {
        font-size: 0.85rem;
        font-weight: 600;
    }

    .spider-btn-vod {
        background: #9146ff;
        border-color: #9146ff;
        color: #fff;
    }

    .spider-btn-vod:hover {
        background: #a970ff;
        border-color: #a970ff;
        color: #fff;
    }

    .spider-btn-outline {
        border-color: #495057;
        color: #dee2e6;
    }

    .spider-btn-outline:hover {
        background: rgba(255, 255, 255, 0.06);
        border-color: #00d4ff;
        color: #00d4ff;
    }

    @media (max-width: 520px) {
        .spider-match-duel {
            flex-direction: column;
            align-items: center;
        }

        .spider-duel-center {
            flex-direction: row;
            gap: 0.5rem;
            min-width: 0;
        }

        .spider-duel-intro {
            height: 110px;
        }
    }
</style>

<div class="spider-ballot-wrap">
    <div class="spider-ballot-hero">
        <h1>Rate this game</h1>
        <p>Help build FSL spider charts — pick who played each skill better.</p>
    </div>

    <aside class="spider-chat-option" aria-labelledby="chat-vote-heading">
        <h2 id="chat-vote-heading"><i class="fab fa-twitch" aria-hidden="true"></i> Prefer Twitch chat?</h2>
        <p>While stream ratings are <strong>open</strong>, you can vote in channel instead of this page. Type short codes (one message can include several):</p>
        <div class="spider-chat-codes">
            mic1 mac2 clut &nbsp;·&nbsp; cre1 agg2 strt
        </div>
        <p class="spider-chat-legend">
            <code>1</code> = <span class="spider-side-chip p1"><?= $p1Hint ?></span> (Player 1 / red),
            <code>2</code> = <span class="spider-side-chip p2"><?= $p2Hint ?></span> (Player 2 / blue),
            <code>t</code> = tie.
            Skills: <code>mic</code> micro, <code>mac</code> macro, <code>clu</code> clutch,
            <code>cre</code> creativity, <code>agg</code> aggression, <code>str</code> strategy.
            Type <code>!ratings</code> in chat when voting is open for the full list.
        </p>
        <a href="voting_guide.php" class="spider-guide-link" target="_blank" rel="noopener">Full voting guide →</a>
    </aside>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php else: ?>
        <?php if ($notice): ?>
            <div class="alert alert-success"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>

        <div class="spider-ballot-card">
            <p class="spider-voter-tag">Voting as <strong><?= htmlspecialchars($row['twitch_login']) ?></strong> · this link is one vote</p>

            <?php if (!$matches): ?>
                <div class="alert alert-warning mb-0">No recent matches to vote on yet. Try again after the next broadcast.</div>
            <?php else: ?>
                <form method="get" class="spider-match-select" action="spider_ballot.php">
                    <input type="hidden" name="t" value="<?= htmlspecialchars($token) ?>">
                    <label for="match">Match</label>
                    <select id="match" name="match" class="form-control" onchange="this.form.submit()">
                        <?php foreach ($matches as $match): ?>
                            <?php $id = (int) $match['fsl_match_id']; ?>
                            <option value="<?= $id ?>" <?= $selected && $id === (int) $selected['fsl_match_id'] ? 'selected' : '' ?>>
                                <?= $id === $liveMatchId ? 'LIVE · ' : '' ?><?= htmlspecialchars(spider_match_label($match)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <?php if ($selected && $matchDetail): ?>
                    <?php
                    $mid = (int) $matchDetail['fsl_match_id'];
                    $isLiveMatch = $mid === $liveMatchId;
                    $matchWhen = !empty($matchDetail['match_date'])
                        ? date('l, M j, Y · g:i A', strtotime($matchDetail['match_date']))
                        : null;
                    $team1Logo = !empty($matchDetail['player1_team']) ? getTeamLogo($matchDetail['player1_team'], '64px') : null;
                    $team2Logo = !empty($matchDetail['player2_team']) ? getTeamLogo($matchDetail['player2_team'], '64px') : null;
                    ?>
                    <section class="spider-match-showcase" aria-labelledby="match-showcase-title">
                        <div class="spider-match-showcase-head">
                            <h2 id="match-showcase-title">
                                <a href="view_match.php?id=<?= $mid ?>" target="_blank" rel="noopener">Match #<?= $mid ?></a>
                            </h2>
                            <div class="spider-match-badges">
                                <?php if ($isLiveMatch): ?>
                                    <span class="spider-match-badge live">Live ratings</span>
                                <?php endif; ?>
                                <?php if (!empty($matchDetail['t_code'])): ?>
                                    <span class="spider-match-badge code">Code <?= htmlspecialchars($matchDetail['t_code']) ?></span>
                                <?php endif; ?>
                                <span class="spider-match-badge">Season <?= (int) $matchDetail['season'] ?></span>
                                <?php if (!empty($matchDetail['best_of'])): ?>
                                    <span class="spider-match-badge">Bo<?= (int) $matchDetail['best_of'] ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="spider-match-duel">
                            <div class="spider-duel-player side-p1">
                                <span class="spider-side-tag">Player 1</span>
                                <div class="spider-duel-intro">
                                    <?php
                                    $introPlayerName = $matchDetail['player1_name'];
                                    $introPlayerId = (int) $matchDetail['winner_player_id'];
                                    $introVideoUrl = $matchDetail['player1_intro'] ?? null;
                                    $uniqueId = 'ballot_p1_' . $mid;
                                    include __DIR__ . '/view_player_intro.php';
                                    ?>
                                </div>
                                <div class="spider-duel-name">
                                    <a href="view_player.php?name=<?= urlencode($matchDetail['player1_name']) ?>" target="_blank" rel="noopener">
                                        <?= htmlspecialchars($matchDetail['player1_name']) ?>
                                    </a>
                                    <?php if (!empty($matchDetail['player1_race'])): ?>
                                        <span class="spider-race-pill"><?= htmlspecialchars($matchDetail['player1_race']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="spider-duel-meta">
                                    Player 1 · series winner
                                    <?php if (!empty($matchDetail['player1_team'])): ?>
                                        <br>
                                        <?php if ($team1Logo): ?>
                                            <img src="<?= htmlspecialchars($team1Logo) ?>" alt="" width="18" height="18" style="vertical-align: middle; border-radius: 2px;">
                                        <?php endif; ?>
                                        <?= htmlspecialchars($matchDetail['player1_team']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="spider-duel-center">
                                <span class="spider-duel-vs">vs</span>
                                <span class="spider-duel-score"><?= (int) $matchDetail['map_win'] ?>–<?= (int) $matchDetail['map_loss'] ?></span>
                            </div>

                            <div class="spider-duel-player side-p2">
                                <span class="spider-side-tag">Player 2</span>
                                <div class="spider-duel-intro">
                                    <?php
                                    $introPlayerName = $matchDetail['player2_name'];
                                    $introPlayerId = (int) $matchDetail['loser_player_id'];
                                    $introVideoUrl = $matchDetail['player2_intro'] ?? null;
                                    $uniqueId = 'ballot_p2_' . $mid;
                                    include __DIR__ . '/view_player_intro.php';
                                    ?>
                                </div>
                                <div class="spider-duel-name">
                                    <a href="view_player.php?name=<?= urlencode($matchDetail['player2_name']) ?>" target="_blank" rel="noopener">
                                        <?= htmlspecialchars($matchDetail['player2_name']) ?>
                                    </a>
                                    <?php if (!empty($matchDetail['player2_race'])): ?>
                                        <span class="spider-race-pill"><?= htmlspecialchars($matchDetail['player2_race']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="spider-duel-meta">
                                    Player 2
                                    <?php if (!empty($matchDetail['player2_team'])): ?>
                                        <br>
                                        <?php if ($team2Logo): ?>
                                            <img src="<?= htmlspecialchars($team2Logo) ?>" alt="" width="18" height="18" style="vertical-align: middle; border-radius: 2px;">
                                        <?php endif; ?>
                                        <?= htmlspecialchars($matchDetail['player2_team']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="spider-match-facts">
                            <?php if ($matchWhen): ?>
                                <p><strong>Broadcast:</strong> <?= htmlspecialchars($matchWhen) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($matchDetail['season_extra_info'])): ?>
                                <p><strong>Round:</strong> <?= htmlspecialchars($matchDetail['season_extra_info']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($matchDetail['notes'])): ?>
                                <p><strong>Notes:</strong> <?= htmlspecialchars($matchDetail['notes']) ?></p>
                            <?php endif; ?>
                            <p class="spider-match-note mb-0">Rate who played each skill better — not who won the series.</p>
                            <div class="spider-match-actions">
                                <?php if (!empty($matchDetail['vod'])): ?>
                                    <a href="<?= htmlspecialchars($matchDetail['vod']) ?>" class="btn btn-sm spider-btn-vod" target="_blank" rel="noopener">Watch VOD</a>
                                <?php endif; ?>
                                <a href="view_match.php?id=<?= $mid ?>" class="btn btn-sm btn-outline-secondary spider-btn-outline" target="_blank" rel="noopener">Full match page</a>
                            </div>
                        </div>
                    </section>

                    <h3 class="spider-skills-heading">Your skill ratings</h3>
                    <p class="spider-skills-sub">Tap a colored button — same red/blue as the players above.</p>
                    <div class="spider-vote-legend" aria-hidden="true">
                        <span class="spider-side-chip p1"><?= htmlspecialchars($matchDetail['player1_name']) ?></span>
                        <span class="spider-side-chip p2"><?= htmlspecialchars($matchDetail['player2_name']) ?></span>
                        <span>Gray = tie or skip</span>
                    </div>

                    <form method="post" action="spider_ballot.php?t=<?= urlencode($token) ?>&amp;match=<?= (int) $selected['fsl_match_id'] ?>">
                        <input type="hidden" name="t" value="<?= htmlspecialchars($token) ?>">
                        <input type="hidden" name="match_id" value="<?= (int) $selected['fsl_match_id'] ?>">
                        <?php foreach (SPIDER_ATTRIBUTES as $attr): ?>
                            <div class="spider-skill-block">
                                <div class="spider-skill-name"><?= htmlspecialchars($labels[$attr]) ?></div>
                                <div class="spider-skill-hint"><?= htmlspecialchars($hints[$attr]) ?></div>
                                <div class="spider-vote-choices">
                                    <label class="choice-p1">
                                        <input type="radio" name="<?= $attr ?>" value="1"<?= spider_checked($choices, $attr, '1') ?>>
                                        <span><?= htmlspecialchars($selected['player1_name']) ?></span>
                                    </label>
                                    <label class="choice-p2">
                                        <input type="radio" name="<?= $attr ?>" value="2"<?= spider_checked($choices, $attr, '2') ?>>
                                        <span><?= htmlspecialchars($selected['player2_name']) ?></span>
                                    </label>
                                    <label class="choice-tie">
                                        <input type="radio" name="<?= $attr ?>" value="0"<?= spider_checked($choices, $attr, '0') ?>>
                                        <span>Tie</span>
                                    </label>
                                    <label class="skip-choice">
                                        <input type="radio" name="<?= $attr ?>" value="skip"<?= isset($choices[$attr]) ? '' : ' checked' ?>>
                                        <span>Skip</span>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-primary btn-block spider-save-btn">Save this match</button>
                    </form>
                <?php elseif ($selected): ?>
                    <div class="alert alert-warning">Could not load match details.</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
