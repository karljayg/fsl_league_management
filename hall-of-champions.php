<?php
session_start();
require_once 'includes/team_logo.php';

function normalizeKey($value) {
    return strtolower(preg_replace('/[^a-z0-9]/', '', (string) $value));
}

function getPlayerThumbnail($playerName) {
    // Match player_network.php thumbnail naming convention.
    $safeName = preg_replace('/[^a-zA-Z0-9_\\-]/', '', str_replace(' ', '_', (string) $playerName));
    $pngPath = 'images/player_thumbnails/' . $safeName . '.png';
    $jpgPath = 'images/player_thumbnails/' . $safeName . '.jpg';
    if (file_exists(__DIR__ . '/' . $pngPath)) {
        return $pngPath;
    }
    if (file_exists(__DIR__ . '/' . $jpgPath)) {
        return $jpgPath;
    }

    // Fallback: same folder, case-insensitive filename match.
    static $thumbMap = null;
    if ($thumbMap === null) {
        $thumbMap = [];
        $files = glob(__DIR__ . '/images/player_thumbnails/*.{png,jpg,jpeg}', GLOB_BRACE) ?: [];
        foreach ($files as $file) {
            $base = pathinfo($file, PATHINFO_FILENAME);
            $normalized = strtolower($base);
            $thumbMap[$normalized] = 'images/player_thumbnails/' . basename($file);
        }
    }

    $fallbackKey = strtolower($safeName);
    if (isset($thumbMap[$fallbackKey])) {
        return $thumbMap[$fallbackKey];
    }
    return null;
}

function renderPlayerEntry($tierLabel, $tierClass, $starsClass, $starsText, $playerName) {
    $thumb = getPlayerThumbnail($playerName);
    $isTbd = strtoupper(trim($playerName)) === 'TBD';
    $playerUrl = 'view_player.php?name=' . urlencode($playerName);
    ?>
    <li>
        <?php if ($isTbd): ?>
        <span class="entity-logo-box player-logo-box" title="<?= htmlspecialchars($playerName) ?>">
            <span class="entity-fallback">?</span>
        </span>
        <?php else: ?>
        <a class="entity-logo-box player-logo-box" href="<?= htmlspecialchars($playerUrl) ?>" title="<?= htmlspecialchars($playerName) ?>">
            <?php if ($thumb): ?>
            <img src="<?= htmlspecialchars($thumb) ?>" alt="<?= htmlspecialchars($playerName) ?>">
            <?php else: ?>
            <span class="entity-fallback">P</span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
        <span class="tier <?= htmlspecialchars($tierClass) ?>"><?= htmlspecialchars($tierLabel) ?></span>
        <?php if ($isTbd): ?>
        <span class="name player-name-link"><?= htmlspecialchars($playerName) ?></span>
        <?php else: ?>
        <a class="name player-name-link" href="<?= htmlspecialchars($playerUrl) ?>"><?= htmlspecialchars($playerName) ?></a>
        <?php endif; ?>
    </li>
    <?php
}

function renderTeamBadge($teamName) {
    $teamLogo = getTeamLogo($teamName);
    $teamUrl = 'view_team.php?name=' . urlencode($teamName);
    ?>
    <a class="entity-logo-box team-logo-box" href="<?= htmlspecialchars($teamUrl) ?>" title="<?= htmlspecialchars($teamName) ?>">
        <?php if ($teamLogo): ?>
        <img src="<?= htmlspecialchars($teamLogo) ?>" alt="<?= htmlspecialchars($teamName) ?>">
        <?php else: ?>
        <span class="entity-fallback">T</span>
        <?php endif; ?>
    </a>
    <?php
}

function renderPlayerBadge($playerName) {
    $thumb = getPlayerThumbnail($playerName);
    $playerUrl = 'view_player.php?name=' . urlencode($playerName);
    ?>
    <a class="entity-logo-box player-logo-box" href="<?= htmlspecialchars($playerUrl) ?>" title="<?= htmlspecialchars($playerName) ?>">
        <?php if ($thumb): ?>
        <img src="<?= htmlspecialchars($thumb) ?>" alt="<?= htmlspecialchars($playerName) ?>">
        <?php else: ?>
        <span class="entity-fallback">P</span>
        <?php endif; ?>
    </a>
    <?php
}

/** Pair champs from fsl_season.php (standalone 2v2 bracket; ended before team-league–only seasons). */
function render2v2ChampLine($label, $playerA, $playerB) {
    $urlA = 'view_player.php?name=' . urlencode($playerA);
    $urlB = 'view_player.php?name=' . urlencode($playerB);
    ?>
    <p class="two-v-two-line">
        <span class="badge-2v2"><?= htmlspecialchars($label) ?></span>
        <a href="<?= htmlspecialchars($urlA) ?>"><?= htmlspecialchars($playerA) ?></a><span class="pair-sep"> / </span><a href="<?= htmlspecialchars($urlB) ?>"><?= htmlspecialchars($playerB) ?></a>
        <span class="two-v-two-badges"><?php renderPlayerBadge($playerA); ?><?php renderPlayerBadge($playerB); ?></span>
    </p>
    <?php
}

$pageTitle = 'FSL Hall of Champions';
include 'includes/header.php';
?>

<div class="hall-page">
<div class="container py-4 py-lg-5">
    <main class="hall-poster mx-auto">
        <div class="poster-glow"></div>
        <div class="poster-nebula poster-nebula-a"></div>
        <div class="poster-nebula poster-nebula-b"></div>

        <header class="title-area text-center">
            <div class="tier-legend-art">
                <img src="images/hall_of_champions_logo.png" alt="Hall of Champions logo">
            </div>
        </header>

        <section class="timeline-wrap">
            <div class="timeline-center-line"></div>

            <article class="season-node right season-10">
                <div class="node-star">
                    <span></span>
                </div>
                <div class="node-content">
                    <h2><a href="fsl_season.php#10" class="season-history-link">Season 10</a></h2>
                    <p class="season-tagline">Current Era <span class="season-tagline-date">(Jan – Apr 2026)</span></p>
                    <p class="team-line">
                        <span class="trophy">🏆</span> TEAM: <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a>
                        <?php renderTeamBadge('PulledTheBoys'); ?>
                    </p>
                    <ul>
                        <?php renderPlayerEntry('S', 'tier-s', 'stars-s', '★★', 'TBD'); ?>
                        <?php renderPlayerEntry('A', 'tier-a', 'stars-a', '★', 'Jmpz'); ?>
                        <?php renderPlayerEntry('B', 'tier-b', 'stars-b', '•', 'WindShadow'); ?>
                    </ul>
                </div>
            </article>

            <article class="season-node left season-9">
                <div class="node-star">
                    <span></span>
                </div>
                <div class="node-content">
                    <h2><a href="fsl_season.php#9" class="season-history-link">Season 9</a></h2>
                    <p class="season-tagline">Champions Continue <span class="season-tagline-date">(Jun – Dec 2025)</span></p>
                    <p class="team-line">
                        <span class="trophy">🏆</span> TEAM: <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a>
                        <?php renderTeamBadge('PulledTheBoys'); ?>
                    </p>
                    <ul>
                        <?php renderPlayerEntry('S', 'tier-s', 'stars-s', '★★', 'DarkMenace'); ?>
                        <?php renderPlayerEntry('A', 'tier-a', 'stars-a', '★', 'Sopuli'); ?>
                        <?php renderPlayerEntry('B', 'tier-b', 'stars-b', '•', 'ChienPwn'); ?>
                    </ul>
                </div>
            </article>

            <article class="season-node right season-8">
                <div class="node-star">
                    <span></span>
                </div>
                <div class="node-content">
                    <h2><a href="fsl_season.php#8" class="season-history-link">Season 8</a></h2>
                    <p class="season-tagline">Team League Expansion <span class="season-tagline-date">(Oct 2024)</span></p>
                    <p class="team-line">
                        <span class="trophy">🏆</span> TEAM: <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a>
                        <?php renderTeamBadge('PulledTheBoys'); ?>
                    </p>
                    <?php render2v2ChampLine('2v2', 'Vales', 'Instability'); ?>
                    <ul>
                        <?php renderPlayerEntry('S', 'tier-s', 'stars-s', '★★', 'DarkMenace'); ?>
                        <?php renderPlayerEntry('A', 'tier-a', 'stars-a', '★', 'LittleReaper'); ?>
                        <?php renderPlayerEntry('B', 'tier-b', 'stars-b', '•', 'ChienPwn'); ?>
                    </ul>
                </div>
            </article>

            <article class="season-node left season-7 rare">
                <div class="node-star">
                    <span></span>
                </div>
                <div class="node-content">
                    <h2><a href="fsl_season.php#7" class="season-history-link">Season 7</a></h2>
                    <p class="season-tagline">Code S+ Debut <span class="season-tagline-date">(2023)</span></p>
                    <?php render2v2ChampLine('2v2+', 'Vales', 'HurtnTime'); ?>
                    <?php render2v2ChampLine('2v2', 'Warbunnies', 'Greeempire'); ?>
                    <ul>
                        <?php renderPlayerEntry('S+', 'tier-sp', 'stars-sp', '★★★', 'DarkMenace'); ?>
                        <?php renderPlayerEntry('S', 'tier-s', 'stars-s', '★★', 'LightHood'); ?>
                        <?php renderPlayerEntry('A', 'tier-a', 'stars-a', '★', 'HyperTurtle'); ?>
                        <?php renderPlayerEntry('B', 'tier-b', 'stars-b', '•', 'RevenantRage'); ?>
                    </ul>
                </div>
            </article>

            <article class="season-node right season-6">
                <div class="node-star">
                    <span></span>
                </div>
                <div class="node-content">
                    <h2><a href="fsl_season.php#6" class="season-history-link">Season 6</a></h2>
                    <p class="season-tagline">Multi-Tier Mastery <span class="season-tagline-date">(2023)</span></p>
                    <?php render2v2ChampLine('2v2', 'Vales', 'HurtnTime'); ?>
                    <ul>
                        <?php renderPlayerEntry('S', 'tier-s', 'stars-s', '★★', 'Neutrophil'); ?>
                        <?php renderPlayerEntry('A', 'tier-a', 'stars-a', '★', 'Grey'); ?>
                        <?php renderPlayerEntry('B', 'tier-b', 'stars-b', '•', 'Fenrir'); ?>
                    </ul>
                </div>
            </article>

            <article class="season-node left season-5">
                <div class="node-star">
                    <span></span>
                </div>
                <div class="node-content">
                    <h2><a href="fsl_season.php#5" class="season-history-link">Season 5</a></h2>
                    <p class="season-tagline">2v2 Comes of Age <span class="season-tagline-date">(May 2022)</span></p>
                    <?php render2v2ChampLine('2v2', 'Neutrophil', 'DarkMenace'); ?>
                    <ul>
                        <?php renderPlayerEntry('S', 'tier-s', 'stars-s', '★★', 'Neutrophil'); ?>
                        <?php renderPlayerEntry('A', 'tier-a', 'stars-a', '★', 'Kriminal'); ?>
                        <?php renderPlayerEntry('B', 'tier-b', 'stars-b', '•', 'ChienPwn'); ?>
                    </ul>
                </div>
            </article>

            <article class="season-node right season-4">
                <div class="node-star">
                    <span></span>
                </div>
                <div class="node-content">
                    <h2><a href="fsl_season.php#4" class="season-history-link">Season 4</a></h2>
                    <p class="season-tagline">2v2 Begins <span class="season-tagline-date">(~2021)</span></p>
                    <?php render2v2ChampLine('2v2', 'Regret', 'TheArchaic'); ?>
                    <ul>
                        <?php renderPlayerEntry('S', 'tier-s', 'stars-s', '★★', 'Neutrophil'); ?>
                        <?php renderPlayerEntry('A', 'tier-a', 'stars-a', '★', 'Dpoo'); ?>
                        <?php renderPlayerEntry('B', 'tier-b', 'stars-b', '•', 'stublu88'); ?>
                    </ul>
                </div>
            </article>

            <article class="season-node left season-3">
                <div class="node-star">
                    <span></span>
                </div>
                <div class="node-content">
                    <h2><a href="fsl_season.php#3" class="season-history-link">Season 3</a></h2>
                    <p class="season-tagline">Code B Arrives <span class="season-tagline-date">(~2021)</span></p>
                    <ul>
                        <?php renderPlayerEntry('S', 'tier-s', 'stars-s', '★★', 'VeryCool'); ?>
                        <?php renderPlayerEntry('A', 'tier-a', 'stars-a', '★', 'Dpoo'); ?>
                        <?php renderPlayerEntry('B', 'tier-b', 'stars-b', '•', 'PanicSwitched'); ?>
                    </ul>
                </div>
            </article>

            <article class="season-node right season-2">
                <div class="node-star">
                    <span></span>
                </div>
                <div class="node-content">
                    <h2><a href="fsl_season.php#2" class="season-history-link">Season 2</a></h2>
                    <p class="season-tagline">Code A Joins <span class="season-tagline-date">(Aug 2020)</span></p>
                    <ul>
                        <?php renderPlayerEntry('S', 'tier-s', 'stars-s', '★★', 'Sef'); ?>
                        <?php renderPlayerEntry('A', 'tier-a', 'stars-a', '★', 'RegreT'); ?>
                    </ul>
                </div>
            </article>

            <article class="season-node left season-1 foundation">
                <div class="node-star">
                    <span></span>
                </div>
                <div class="node-content">
                    <h2><a href="fsl_season.php#1" class="season-history-link">Season 1</a></h2>
                    <p class="season-tagline">The Beginning <span class="season-tagline-date">(Pre-Aug 2020)</span></p>
                    <ul>
                        <?php renderPlayerEntry('S', 'tier-s', 'stars-s', '★★', 'Neutrophil'); ?>
                    </ul>
                </div>
            </article>
        </section>

        <footer class="poster-footer text-center">
            <p>From first matches to modern champions</p>
            <a class="league-logo-placeholder" href="index.php" title="FSL Home">
                <img src="images/fsl_sc2_logo.png" alt="FSL Logo">
            </a>
        </footer>
    </main>
</div>
</div>

<style>
    .hall-page {
        font-family: 'Inter', sans-serif;
        line-height: 1.4;
        -webkit-font-smoothing: antialiased;
        text-rendering: optimizeLegibility;
        background:
            radial-gradient(circle at 18% 16%, rgba(0, 212, 255, 0.15), transparent 38%),
            radial-gradient(circle at 80% 22%, rgba(48, 43, 99, 0.24), transparent 40%),
            linear-gradient(135deg, #0f0c29 0%, #302b63 52%, #24243e 100%);
        color: #e0e0e0;
        min-height: calc(100vh - 60px);
    }

    .hall-poster {
        position: relative;
        width: min(100%, 1080px);
        min-height: 2550px;
        border: 1px solid rgba(0, 212, 255, 0.26);
        border-radius: 22px;
        overflow: hidden;
        background: linear-gradient(180deg, rgba(12, 15, 39, 0.95) 0%, rgba(18, 23, 48, 0.95) 100%);
        box-shadow: 0 18px 48px rgba(0, 0, 0, 0.55), inset 0 0 72px rgba(0, 212, 255, 0.08);
        padding: clamp(1.6rem, 3.5vw, 2.9rem) clamp(1.25rem, 3vw, 2.2rem) 2.4rem;
    }

    .poster-glow,
    .poster-nebula {
        position: absolute;
        inset: 0;
        pointer-events: none;
    }

    .poster-glow {
        background: radial-gradient(ellipse at 50% -12%, rgba(0, 212, 255, 0.17), transparent 58%);
    }

    .poster-nebula-a {
        background: radial-gradient(ellipse at 17% 64%, rgba(0, 212, 255, 0.12), transparent 52%);
    }

    .poster-nebula-b {
        background: radial-gradient(ellipse at 81% 40%, rgba(106, 68, 146, 0.14), transparent 49%);
    }

    .title-area,
    .timeline-wrap,
    .poster-footer {
        position: relative;
        z-index: 2;
    }

    .title-area h1 {
        margin: 0.35rem 0 0.35rem;
        letter-spacing: 0.1em;
        font-size: clamp(1.8rem, 4vw, 3.1rem);
        color: #00d4ff;
        text-shadow: 0 0 20px rgba(0, 212, 255, 0.5);
    }

    .subtitle {
        margin: 0;
        color: #cdd4e8;
        font-size: clamp(0.88rem, 1.6vw, 1.06rem);
        letter-spacing: 0.09em;
        text-transform: uppercase;
    }

    .tier-legend-art {
        width: min(860px, 100%);
        margin: 0.9rem auto 0.9rem;
        border: 1px solid rgba(0, 212, 255, 0.24);
        border-radius: 12px;
        background: rgba(9, 16, 34, 0.62);
        overflow: hidden;
        box-shadow: inset 0 0 14px rgba(0, 212, 255, 0.08);
    }

    .tier-legend-art img {
        width: min(100%, 820px);
        height: auto;
        display: block;
        margin: 0 auto;
    }

    .timeline-wrap {
        position: relative;
        padding: 0.2rem 0 1.2rem;
        min-height: 2050px;
    }

    .timeline-center-line {
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        top: 0;
        bottom: 0;
        width: 4px;
        border-radius: 999px;
        background: linear-gradient(180deg, rgba(0, 212, 255, 0.25) 0%, rgba(0, 212, 255, 0.62) 42%, rgba(0, 212, 255, 0.25) 100%);
        box-shadow: 0 0 20px rgba(0, 212, 255, 0.36);
    }

    .season-node {
        position: absolute;
        width: min(44%, 430px);
        top: 0;
        display: flex;
        align-items: flex-start;
        gap: 0.8rem;
    }

    .season-node.left { right: 50%; margin-right: 2.2rem; flex-direction: row-reverse; text-align: right; }
    .season-node.right { left: 50%; margin-left: 2.2rem; text-align: left; }

    .season-node.season-10 { top: 1%; }
    .season-node.season-9 { top: 12%; }
    .season-node.season-8 { top: 23%; }
    .season-node.season-7 { top: 34%; }
    .season-node.season-6 { top: 45%; }
    .season-node.season-5 { top: 56%; }
    .season-node.season-4 { top: 67%; }
    .season-node.season-3 { top: 78%; }
    .season-node.season-2 { top: 87%; }
    .season-node.season-1 { top: 94%; }

    .node-star {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: #00d4ff;
        box-shadow: 0 0 14px rgba(0, 212, 255, 0.65);
        margin-top: 0.55rem;
        position: relative;
        flex-shrink: 0;
    }

    .node-star::before,
    .node-star::after {
        content: "";
        position: absolute;
        background: rgba(0, 212, 255, 0.36);
    }

    .node-star::before {
        left: 50%;
        top: -6px;
        width: 1px;
        height: 24px;
        transform: translateX(-50%);
    }

    .node-star::after {
        top: 50%;
        left: -6px;
        width: 24px;
        height: 1px;
        transform: translateY(-50%);
    }

    .node-star > span {
        display: none;
    }

    .node-content {
        background: linear-gradient(160deg, rgba(8, 16, 38, 0.88) 0%, rgba(11, 21, 46, 0.88) 100%);
        border: 1px solid rgba(0, 212, 255, 0.25);
        border-radius: 14px;
        padding: 0.75rem 0.95rem 0.8rem;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35), inset 0 0 28px rgba(0, 212, 255, 0.08);
        width: 100%;
    }

    .node-content h2 {
        margin: 0;
        font-size: clamp(1.03rem, 1.9vw, 1.22rem);
        letter-spacing: 0.04em;
        color: #00d4ff;
    }

    .node-content h2 .season-history-link {
        color: inherit;
        text-decoration: none;
        text-shadow: inherit;
    }

    .node-content h2 .season-history-link:hover {
        color: #7fe8ff;
        text-shadow: 0 0 12px rgba(0, 212, 255, 0.45);
    }

    .season-tagline {
        margin: 0.14rem 0 0.44rem;
        color: #b5c9f0;
        font-size: 0.83rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }

    .season-tagline-date {
        text-transform: none;
        letter-spacing: 0.03em;
        font-weight: 500;
        color: rgba(140, 155, 186, 0.78);
    }

    .team-line {
        margin: 0 0 0.44rem;
        color: #00d4ff;
        font-size: 0.88rem;
        letter-spacing: 0.03em;
    }

    .trophy {
        margin-right: 0.3rem;
    }

    .team-line a {
        color: #e0e0e0;
        text-decoration: none;
    }

    .team-line a:hover {
        color: #00d4ff;
        text-shadow: 0 0 6px rgba(0, 212, 255, 0.45);
    }

    .team-line .team-logo-box {
        width: 51px;
        height: 51px;
        display: inline-flex;
        vertical-align: middle;
        margin-left: 0.45rem;
    }

    .two-v-two-line {
        margin: 0 0 0.38rem;
        color: #b8cfe8;
        font-size: 0.82rem;
        letter-spacing: 0.02em;
        line-height: 1.45;
    }

    .two-v-two-line .badge-2v2 {
        display: inline-block;
        margin-right: 0.35rem;
        padding: 0.1rem 0.38rem;
        border-radius: 6px;
        border: 1px solid rgba(0, 212, 255, 0.35);
        color: #00d4ff;
        font-weight: 700;
        font-size: 0.72rem;
        letter-spacing: 0.06em;
        vertical-align: middle;
    }

    .two-v-two-line a {
        color: #e8eefc;
        text-decoration: none;
    }

    .two-v-two-line a:hover {
        color: #00d4ff;
        text-shadow: 0 0 6px rgba(0, 212, 255, 0.45);
    }

    .two-v-two-line .pair-sep {
        color: rgba(255, 255, 255, 0.35);
    }

    .two-v-two-badges {
        display: inline-flex;
        align-items: center;
        gap: 0.28rem;
        margin-left: 0.35rem;
        vertical-align: middle;
    }

    .two-v-two-line .player-logo-box {
        width: 30px;
        height: 30px;
        border-radius: 8px;
    }

    .node-content ul {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 0.22rem;
    }

    .node-content li {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.9rem;
    }

    .season-node.left .node-content li {
        justify-content: flex-end;
    }

    .tier {
        width: 26px;
        font-weight: 700;
        letter-spacing: 0.05em;
    }

    .tier-sp {
        color: #ffd46b;
        text-shadow: 0 0 8px rgba(255, 212, 107, 0.74);
    }

    .tier-s {
        color: #d9b772;
        text-shadow: 0 0 7px rgba(228, 188, 110, 0.45);
    }

    .tier-a {
        color: #d8deea;
        text-shadow: 0 0 6px rgba(217, 225, 246, 0.35);
    }

    .tier-b {
        color: #9b8f7c;
    }

    .name {
        color: #f0f3fc;
        letter-spacing: 0.02em;
        text-decoration: none;
    }

    .name:hover {
        color: #00d4ff;
    }

    .season-node.rare .node-content {
        border-color: rgba(255, 212, 107, 0.5);
        box-shadow: 0 0 32px rgba(255, 212, 107, 0.28), inset 0 0 22px rgba(255, 212, 107, 0.08);
    }

    .season-node.rare .node-star {
        box-shadow: 0 0 24px rgba(255, 212, 107, 0.95);
    }

    .season-node.foundation {
        width: min(47%, 460px);
    }

    .season-node.foundation .node-content {
        transform: scale(1.05);
        transform-origin: center;
        border-color: rgba(223, 195, 133, 0.4);
    }

    .entity-logo-box {
        width: 48px;
        height: 48px;
        border: 1px solid rgba(0, 212, 255, 0.42);
        border-radius: 10px;
        background: rgba(9, 17, 35, 0.75);
        color: #93e6ff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.62rem;
        text-transform: uppercase;
        text-align: center;
        line-height: 1.1;
        letter-spacing: 0.04em;
        flex-shrink: 0;
        overflow: hidden;
        text-decoration: none;
        box-shadow: inset 0 0 14px rgba(0, 212, 255, 0.09);
    }

    .entity-logo-box img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .entity-logo-box:hover {
        border-color: #00d4ff;
        box-shadow: 0 0 14px rgba(0, 212, 255, 0.4);
        transform: translateY(-1px);
    }

    .entity-fallback {
        font-weight: 700;
        color: #00d4ff;
    }

    .player-logo-box {
        border-radius: 50%;
    }

    .team-logo-box {
        border-radius: 8px;
    }

    .mini-logo {
        clip-path: polygon(50% 0%, 94% 18%, 88% 78%, 50% 100%, 12% 78%, 6% 18%);
    }

    .player-name-link {
        color: #f0f3fc;
    }

    .node-content li .name:hover {
        color: #00d4ff;
    }

    .poster-footer {
        margin-top: 0.8rem;
    }

    .poster-footer p {
        margin: 0 0 0.85rem;
        color: #c8d7ee;
        font-size: 0.82rem;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .league-logo-placeholder {
        margin: 0 auto;
        width: 112px;
        height: 112px;
        border-radius: 50%;
        border: 1px solid rgba(0, 212, 255, 0.5);
        background: rgba(14, 24, 46, 0.56);
        box-shadow: inset 0 0 20px rgba(0, 212, 255, 0.16), 0 0 20px rgba(0, 212, 255, 0.18);
        color: #9be9ff;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        overflow: hidden;
    }

    .league-logo-placeholder img {
        width: 78%;
        height: 78%;
        object-fit: contain;
    }

    .league-logo-placeholder:hover {
        box-shadow: inset 0 0 22px rgba(0, 212, 255, 0.25), 0 0 22px rgba(0, 212, 255, 0.3);
    }

    @media (max-width: 1199.98px) {
        .hall-poster {
            min-height: 2680px;
        }
    }

    @media (max-width: 991.98px) {
        .hall-poster {
            min-height: auto;
            padding-bottom: 2rem;
        }

        .timeline-wrap {
            min-height: auto;
            padding-bottom: 0.6rem;
        }

        .timeline-center-line {
            left: 16px;
            transform: none;
        }

        .season-node {
            position: relative;
            width: calc(100% - 32px);
            left: 32px;
            right: auto;
            margin: 0 0 1rem;
            top: auto;
            flex-direction: row;
            text-align: left;
        }

        .season-node.left {
            right: auto;
            margin-right: 0;
            margin-left: 0;
            flex-direction: row;
            text-align: left;
        }

        .season-node.left .node-content li {
            justify-content: flex-start;
        }

        .season-node.left .node-content li .name {
            text-align: left;
        }

        .season-node.foundation .node-content {
            transform: none;
        }
    }

    @media (max-width: 575.98px) {
        /* Single-column rail: cards use full width; slim left guide (desktop L/R lanes removed at 991px). */
        .hall-poster {
            padding: 1rem 0.55rem 1.35rem;
            border-radius: 14px;
        }

        .timeline-wrap {
            padding-left: 0;
            padding-right: 0;
        }

        .timeline-center-line {
            left: 14px;
            width: 2px;
            opacity: 0.5;
        }

        .season-node {
            width: 100%;
            max-width: 100%;
            left: 0 !important;
            right: auto !important;
            margin-left: 0 !important;
            margin-right: 0 !important;
            padding-left: 0;
            padding-right: 0;
            gap: 0.5rem;
            box-sizing: border-box;
        }

        .season-node.foundation {
            width: 100%;
            max-width: 100%;
        }

        .season-node.left {
            flex-direction: row;
        }

        /* Align dot center (~15px) with line at 14px */
        .node-star {
            width: 10px;
            height: 10px;
            margin-top: 0.42rem;
            flex-shrink: 0;
            margin-left: 10px;
            margin-right: 0;
        }

        .node-star::before,
        .node-star::after {
            display: none;
        }

        .node-content {
            flex: 1;
            min-width: 0;
        }

        .team-line .team-logo-box {
            width: 42px;
            height: 42px;
            margin-left: 0.28rem;
        }

        .node-content li {
            font-size: 0.83rem;
        }

        .tier {
            width: 23px;
        }

        .entity-logo-box {
            width: 40px;
            height: 40px;
        }
    }
</style>

<?php include 'includes/footer.php'; ?>
