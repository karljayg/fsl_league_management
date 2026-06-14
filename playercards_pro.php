<?php
/**
 * FSL Collectible Player Cards — pro talent track (pros, casters, teams, personalities; not FSL league roster).
 */
set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__);

ob_start();
session_start();

$pageTitle = 'Collectible Cards — Pro talent';
$additionalCss = [];

include_once 'includes/header.php';
?>

<style>
    .pcards-sell-header {
        position: relative;
        z-index: 1;
        margin-left: -15px;
        margin-right: -15px;
        margin-bottom: 1.5rem;
        padding: 1.5rem 15px 1.45rem;
        min-height: 13.5rem;
        overflow: hidden;
        border-bottom: 1px solid rgba(0, 212, 255, 0.45);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
    }
    .pcards-sell-header::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        right: 0;
        bottom: 0;
        z-index: 0;
        background-image:
            linear-gradient(90deg, rgba(10, 8, 20, 0.94) 0%, rgba(10, 8, 20, 0.82) 34%, rgba(8, 12, 28, 0.5) 62%, rgba(4, 8, 22, 0.28) 100%),
            url('images/playercards/hero-arena.png');
        background-size: cover, cover;
        background-position: center, center 42%;
    }
    .pcards-sell-header-inner {
        position: relative;
        z-index: 1;
    }
    .pcards-sell-header .pcards-sell-cardpeek {
        display: inline-block;
        margin: 0 auto;
    }
    .pcards-pro-hero-card {
        display: block;
        max-height: 148px;
        width: auto;
        max-width: min(100%, 340px);
        margin-left: auto;
        margin-right: auto;
        height: auto;
        border-radius: 10px;
        border: 1px solid rgba(255, 255, 255, 0.18);
        box-shadow:
            0 18px 44px rgba(0, 0, 0, 0.72),
            0 0 0 1px rgba(0, 212, 255, 0.18);
        transform: rotate(-4deg);
        vertical-align: middle;
        object-fit: contain;
    }
    .pcards-pro-hero-caption {
        font-size: 0.72rem;
        color: rgba(255, 255, 255, 0.55);
        margin-top: 0.5rem;
        letter-spacing: 0.02em;
        max-width: 340px;
        margin-left: auto;
        margin-right: auto;
    }
    .pcards-pro-showcase img {
        width: 100%;
        height: auto;
        border-radius: 10px;
        border: 1px solid rgba(255, 255, 255, 0.12);
        box-shadow: 0 16px 40px rgba(0, 0, 0, 0.55);
    }
    .pcards-pro-showcase figcaption {
        font-size: 0.82rem;
        color: #aaa;
        margin-top: 0.5rem;
        line-height: 1.4;
    }
    .pcards-sell-header .pcards-sell-kicker {
        font-size: 0.7rem;
        font-weight: 600;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #8aefff;
        margin-bottom: 0.35rem;
        text-shadow: 0 1px 8px rgba(0, 0, 0, 0.85);
    }
    .pcards-sell-header .pcards-sell-title {
        color: #00d4ff;
        font-size: clamp(1.35rem, 3.5vw, 1.85rem);
        font-weight: 700;
        line-height: 1.2;
        margin: 0 0 0.5rem;
        text-shadow: 0 2px 14px rgba(0, 0, 0, 0.9);
    }
    .pcards-sell-header .pcards-sell-lead {
        color: #ececf4;
        font-size: 0.95rem;
        line-height: 1.5;
        margin-bottom: 0.65rem;
        max-width: 40rem;
        text-shadow: 0 1px 10px rgba(0, 0, 0, 0.88);
    }
    .pcards-sell-header .pcards-sell-tags {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.4rem 0.55rem;
        margin: 0;
        padding: 0;
        list-style: none;
    }
    .pcards-sell-header .pcards-sell-tags .badge {
        font-size: 0.75rem;
        font-weight: 600;
        padding: 0.35em 0.75em;
        border-radius: 2rem;
    }
    .pcards-page {
        max-width: 960px;
        margin: 0 auto;
    }
    .pcards-page h2 {
        color: #ff6f61;
        font-size: 1.65rem;
        margin: 2rem 0 1rem;
        padding-bottom: 0.35rem;
        border-bottom: 2px solid rgba(255, 111, 97, 0.5);
    }
    .pcards-page h3 {
        color: #00d4ff;
        font-size: 1.2rem;
        margin: 1.5rem 0 0.75rem;
    }
    .pcards-page .section {
        background: rgba(255, 255, 255, 0.06);
        border-radius: 10px;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.25rem;
        border-left: 4px solid #ff6f61;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
    }
    .pcards-page .section p,
    .pcards-page .section li {
        color: #e0e0e0;
        line-height: 1.6;
        margin-bottom: 0.65rem;
    }
    .pcards-page .section ul {
        padding-left: 1.25rem;
    }
    .pcards-page .gallery-row img {
        border-radius: 8px;
        border: 1px solid rgba(255, 255, 255, 0.12);
        box-shadow: 0 6px 24px rgba(0, 0, 0, 0.35);
        width: 100%;
        height: auto;
    }
    .pcards-page .gallery-caption {
        font-size: 0.85rem;
        color: #b0b0b0;
        margin-top: 0.5rem;
    }
    .pcards-page .diagram-wrap {
        background: rgba(0, 0, 0, 0.25);
        border-radius: 10px;
        padding: 1rem;
        margin: 1rem 0;
        overflow-x: auto;
    }
    .pcards-page .diagram-wrap svg {
        display: block;
        margin: 0 auto;
        max-width: 100%;
        height: auto;
    }
    .pcards-page .table-rarity th {
        color: #00d4ff;
        border-top: none;
    }
    .pcards-page .table-rarity td {
        color: #e0e0e0;
        vertical-align: middle;
    }
    .pcards-page .table-launch-roster th {
        color: #00d4ff;
        border-top: none;
        white-space: nowrap;
    }
    .pcards-page .table-launch-roster td {
        color: #e8e8e8;
        vertical-align: middle;
    }
    .pcards-page .table-launch-roster .col-check {
        width: 1%;
        text-align: center;
        white-space: nowrap;
    }
    .pcards-page .pcards-launch-outreach {
        background: rgba(0, 212, 255, 0.08);
        border: 1px solid rgba(0, 212, 255, 0.3);
        border-radius: 10px;
    }
    .pcards-page .cta-box {
        text-align: center;
        padding: 2rem 1.5rem;
        border-radius: 12px;
        background: rgba(255, 111, 97, 0.12);
        border: 1px solid rgba(255, 111, 97, 0.35);
        margin: 2rem 0;
    }
    .pcards-page .cta-box .btn {
        margin: 0.35rem;
    }
    .pcards-page .footnote {
        font-size: 0.88rem;
        color: #999;
        text-align: center;
        padding: 1.5rem 0 0.5rem;
        border-top: 1px solid rgba(255, 255, 255, 0.08);
        margin-top: 2rem;
    }
    .pcards-page .pcards-sample-disclaimer {
        font-size: 0.875rem;
        line-height: 1.55;
        color: #c8c8d0;
        padding-left: 1rem;
        margin-bottom: 1rem;
        border-left: 3px solid #17a2b8;
    }
    @media (min-width: 992px) {
        .pcards-sell-header { min-height: 15rem; padding-top: 1.75rem; padding-bottom: 1.65rem; }
        .pcards-pro-hero-card { max-height: 168px; max-width: 380px; }
    }
    @media (max-width: 767px) {
        .pcards-sell-header { min-height: 12rem; }
        .pcards-sell-header .pcards-sell-lead { font-size: 0.9rem; }
        .pcards-pro-hero-card { transform: rotate(-2deg); max-height: 120px; max-width: 100%; }
    }
</style>

<header class="pcards-sell-header" role="banner" aria-label="Collectible cards, pro talent track">
    <div class="row align-items-center pcards-sell-header-inner">
        <div class="col-12 col-lg-7 text-center text-lg-left order-2 order-lg-1 pr-lg-4">
            <p class="pcards-sell-kicker mb-0">StarCraft II · extended roster</p>
            <h1 class="pcards-sell-title">Collectible cards — pro talent</h1>
            <p class="pcards-sell-lead mb-2">The same physical line as the league drop, built for <strong>professional players</strong>, <strong>casters</strong>, <strong>streamers &amp; personalities</strong>, and <strong>teams / orgs</strong>—people who are <strong>not</strong> on the FSL league roster. Invited talent, signed likeness, premium card treatment.</p>
            <div class="d-flex flex-wrap align-items-center justify-content-center justify-content-lg-start">
                <ul class="pcards-sell-tags mb-0" aria-label="Program tags">
                    <li><span class="badge badge-info">Pro track</span></li>
                    <li><span class="badge badge-secondary">Casters &amp; celebs</span></li>
                    <li><span class="badge badge-dark">Team cards</span></li>
                </ul>
            </div>
        </div>
        <div class="col-12 col-lg-5 text-center text-lg-right order-1 order-lg-2 mb-3 mb-lg-0">
            <figure class="pcards-sell-cardpeek mb-0">
                <img class="pcards-pro-hero-card"
                     src="images/playercards/serral-card-front.png"
                     width="380"
                     height="212"
                     alt="Example pro player card front: portrait, stats, branding (illustrative sample)">
                <figcaption class="pcards-pro-hero-caption text-lg-right text-center">Sample art only — illustrative; not a PSISTORM print</figcaption>
            </figure>
        </div>
    </div>
</header>

<div class="pcards-page">
    <div class="section">
        <h2 class="mt-0">What this track covers</h2>
        <p>FSL league players have their own path (customization tiers, alumni buyers, league story). <strong>This page is the parallel track</strong> for everyone else we want on cardboard: WCS-level pros, household casters, crossover personalities, and org or team cards that anchor a set.</p>
        <p class="mb-0">Same print quality, same pack SKUs where it makes sense, and the same sci-fi sports look—just a <strong>different contracting and roster pipeline</strong> because rights and schedules are not the same as a league member.</p>
    </div>

    <div class="section">
        <h2 class="mt-0">Who can appear (categories)</h2>
        <ul class="mb-0">
            <li><strong>Professional competitors</strong> — invited with likeness and card copy approved in writing.</li>
            <li><strong>Casters &amp; broadcast talent</strong> — desk voices and event hosts who define how fans experience the game.</li>
            <li><strong>Streamers &amp; celebrities</strong> — crossover reach; card treatments can lean personality-first.</li>
            <li><strong>Teams &amp; organizations</strong> — crest / lineup / “org moment” cards when we have clear art rights.</li>
        </ul>
    </div>

    <h2>Launch Edition — invited talent</h2>
    <p class="text-muted small mb-2">Working list for Launch Edition card invites (pros, casters, and related talent). <strong>Invited</strong> means we have extended an invite; <strong>Confirmed</strong> means likeness and terms are far enough along to treat as locked for print planning.</p>
    <div class="mb-3 p-3 pcards-launch-outreach small text-light" role="note">
        <strong class="text-info">Not on the list yet?</strong> You are not being ignored. <strong>KJ</strong> is contacting people <strong>one at a time</strong> on purpose; personal outreach takes longer than a mass email blast. If you want to <strong>pre-approve</strong> so the process can move faster when it is your turn, message him on Discord: <strong>@karljayg</strong>.
    </div>
    <div class="section p-0 overflow-hidden">
        <h3 class="px-3 pt-3 mt-0 h5 text-info">Confirmed <span class="text-muted font-weight-normal">(alphabetical)</span></h3>
        <div class="table-responsive">
            <table class="table table-dark table-launch-roster mb-0">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col" class="col-check">Invited</th>
                        <th scope="col" class="col-check">Confirmed</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><th scope="row">ByuN</th><td class="col-check"><i class="fas fa-check text-success" aria-hidden="true"></i><span class="sr-only">Yes</span></td><td class="col-check"><i class="fas fa-check text-success" aria-hidden="true"></i><span class="sr-only">Yes</span></td></tr>
                    <tr><th scope="row">Gerald</th><td class="col-check"><i class="fas fa-check text-success" aria-hidden="true"></i><span class="sr-only">Yes</span></td><td class="col-check"><i class="fas fa-check text-success" aria-hidden="true"></i><span class="sr-only">Yes</span></td></tr>
                    <tr><th scope="row">Harstem</th><td class="col-check"><i class="fas fa-check text-success" aria-hidden="true"></i><span class="sr-only">Yes</span></td><td class="col-check"><i class="fas fa-check text-success" aria-hidden="true"></i><span class="sr-only">Yes</span></td></tr>
                    <tr><th scope="row">Lambo</th><td class="col-check"><i class="fas fa-check text-success" aria-hidden="true"></i><span class="sr-only">Yes</span></td><td class="col-check"><i class="fas fa-check text-success" aria-hidden="true"></i><span class="sr-only">Yes</span></td></tr>
                    <tr><th scope="row">MaxPax</th><td class="col-check"><i class="fas fa-check text-success" aria-hidden="true"></i><span class="sr-only">Yes</span></td><td class="col-check"><i class="fas fa-check text-success" aria-hidden="true"></i><span class="sr-only">Yes</span></td></tr>
                    <tr><th scope="row">NoRegreT</th><td class="col-check"><i class="fas fa-check text-success" aria-hidden="true"></i><span class="sr-only">Yes</span></td><td class="col-check"><i class="fas fa-check text-success" aria-hidden="true"></i><span class="sr-only">Yes</span></td></tr>
                    <tr><th scope="row">Pig</th><td class="col-check"><i class="fas fa-check text-success" aria-hidden="true"></i><span class="sr-only">Yes</span></td><td class="col-check"><i class="fas fa-check text-success" aria-hidden="true"></i><span class="sr-only">Yes</span></td></tr>
                    <tr><th scope="row">Scarlett</th><td class="col-check"><i class="fas fa-check text-success" aria-hidden="true"></i><span class="sr-only">Yes</span></td><td class="col-check"><i class="fas fa-check text-success" aria-hidden="true"></i><span class="sr-only">Yes</span></td></tr>
                    <tr><th scope="row">TLO</th><td class="col-check"><i class="fas fa-check text-success" aria-hidden="true"></i><span class="sr-only">Yes</span></td><td class="col-check"><i class="fas fa-check text-success" aria-hidden="true"></i><span class="sr-only">Yes</span></td></tr>
                </tbody>
            </table>
        </div>
        <h3 class="px-3 pt-3 h5 text-warning">Invited — pending confirmation</h3>
        <div class="table-responsive border-top border-secondary">
            <table class="table table-dark table-launch-roster mb-0">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col" class="col-check">Invited</th>
                        <th scope="col" class="col-check">Confirmed</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><th scope="row">Clem</th><td class="col-check"><i class="fas fa-check text-success" aria-hidden="true"></i><span class="sr-only">Yes</span></td><td class="col-check"><span class="text-muted" aria-hidden="true">—</span><span class="sr-only">Not yet</span></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="section">
        <h2 class="mt-0">How it fits the product</h2>
        <p>Fans still open the same style of <strong>mystery packs</strong>. Pro-talent cards sit in the <strong>common pool, special slots, and chases</strong> depending on edition planning—not a separate SKU unless we deliberately design one later.</p>
        <p class="mb-0">Talent receives <strong>their own card</strong> as part of the deal. Economics for invited pros and partners (including talent pool ideas) are handled case by case in agreements—not summarized as promises on this overview.</p>
    </div>

    <h2>Pack &amp; talent samples (illustrative)</h2>
    <p class="text-muted small mb-2">Retail-style <strong>mystery pack</strong> plus <strong>caster / personality</strong> card layouts we use to align art direction. Transparent PNGs; mockup branding is for look-and-feel only on this preview.</p>
    <div class="row pcards-pro-showcase mb-4">
        <div class="col-lg-4 mb-4 mb-lg-0">
            <figure class="mb-0">
                <img src="images/playercards/mystery-pack-premium.png"
                     alt="Sample premium mystery trading card pack (concept artwork)">
                <figcaption>Limited-run foil pack sketch — what pulls feel like in hand.</figcaption>
            </figure>
        </div>
        <div class="col-lg-4 mb-4 mb-lg-0">
            <figure class="mb-0">
                <img src="images/playercards/tempo-card-front.png"
                     alt="Sample caster card front: portrait, role, branding (concept artwork)">
                <figcaption>Caster front: handle, portrait, role line.</figcaption>
            </figure>
        </div>
        <div class="col-lg-4">
            <figure class="mb-0">
                <img src="images/playercards/tempo-card-back.png"
                     alt="Sample card back: bio and cred modules (concept artwork)">
                <figcaption>Bio &amp; cred back: structured fields and brand rail.</figcaption>
            </figure>
        </div>
    </div>

    <h2>Example pro card depth (illustrative)</h2>
    <p class="text-muted small mb-2">High-end stat and bio treatment for invited competitors—same bar we hold for headline talent in the line.</p>
    <p class="pcards-sample-disclaimer mb-3">
        We use <strong>Serral</strong> here as the sample—the GOAT—to show production depth. This is an <strong>illustrative</strong> preview only, not a licensed PSISTORM product or public campaign.
        Team and sponsor marks on the mockup (including <strong>Basilisk</strong>) are <strong>not licensed</strong> for this page and do not imply endorsement or partnership—concept art for internal discussion.
    </p>
    <div class="row pcards-pro-showcase mb-4">
        <div class="col-md-6 mb-4 mb-md-0">
            <figure class="mb-0">
                <img src="images/playercards/serral-card-front.png"
                     alt="Example pro card front: player portrait, rank, winnings (sample artwork)">
                <figcaption>Front: hero presentation, headline stats.</figcaption>
            </figure>
        </div>
        <div class="col-md-6">
            <figure class="mb-0">
                <img src="images/playercards/serral-card-back.png"
                     alt="Example pro card back: bio, team history, faction flair (sample artwork)">
                <figcaption>Back: bio, history timeline, faction identity.</figcaption>
            </figure>
        </div>
    </div>

    <h2>Pack layout (shared rules)</h2>
    <div class="section">
        <p class="mb-2">Same planned structure as the main program: <strong>10 cards per pack</strong> — <strong>8 commons</strong> from the pool and <strong>2 specials</strong> (foils, parallels, serials, or similar, final treatments depend on print quotes).</p>
        <p class="mb-3"><strong>Super rare</strong> chases are a small fraction of the print or use a published insert rate, announced with the final roster before orders close.</p>
        <div class="diagram-wrap">
            <svg viewBox="0 0 520 120" width="520" height="120" role="img">
                <title>One pack: 8 common slots and 2 special slots</title>
                <text x="260" y="22" text-anchor="middle" fill="#00d4ff" font-size="14" font-weight="bold">One pack (10 cards)</text>
                <g transform="translate(20,40)">
                    <rect x="0" y="0" width="38" height="52" rx="4" fill="#2a3d4d" stroke="#6c8a9e" stroke-width="1"/>
                    <rect x="42" y="0" width="38" height="52" rx="4" fill="#2a3d4d" stroke="#6c8a9e" stroke-width="1"/>
                    <rect x="84" y="0" width="38" height="52" rx="4" fill="#2a3d4d" stroke="#6c8a9e" stroke-width="1"/>
                    <rect x="126" y="0" width="38" height="52" rx="4" fill="#2a3d4d" stroke="#6c8a9e" stroke-width="1"/>
                    <rect x="168" y="0" width="38" height="52" rx="4" fill="#2a3d4d" stroke="#6c8a9e" stroke-width="1"/>
                    <rect x="210" y="0" width="38" height="52" rx="4" fill="#2a3d4d" stroke="#6c8a9e" stroke-width="1"/>
                    <rect x="252" y="0" width="38" height="52" rx="4" fill="#2a3d4d" stroke="#6c8a9e" stroke-width="1"/>
                    <rect x="294" y="0" width="38" height="52" rx="4" fill="#2a3d4d" stroke="#6c8a9e" stroke-width="1"/>
                    <text x="166" y="72" text-anchor="middle" fill="#adb5bd" font-size="11">8 commons from pool</text>
                </g>
                <g transform="translate(360,40)">
                    <rect x="0" y="0" width="38" height="52" rx="4" fill="#3d2a52" stroke="#ff6f61" stroke-width="2"/>
                    <rect x="46" y="0" width="38" height="52" rx="4" fill="#3d2a52" stroke="#ff6f61" stroke-width="2"/>
                    <text x="42" y="72" text-anchor="middle" fill="#ff6f61" font-size="11">2 special pulls</text>
                </g>
            </svg>
        </div>
    </div>

    <h2>Rarity tiers (planning)</h2>
    <div class="section p-0 overflow-hidden">
        <table class="table table-dark table-rarity mb-0">
            <thead>
                <tr>
                    <th scope="col">Tier</th>
                    <th scope="col">Role</th>
                    <th scope="col">How we think about it</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><span class="badge badge-secondary">Common</span></td>
                    <td>8 per pack</td>
                    <td>Base roster print; most pulls.</td>
                </tr>
                <tr>
                    <td><span class="badge badge-info">Special</span></td>
                    <td>2 per pack</td>
                    <td>Shorter-run finishes or parallels, defined per set.</td>
                </tr>
                <tr>
                    <td><span class="badge badge-warning text-dark">Super rare</span></td>
                    <td>Chase</td>
                    <td>Hard cap or published insert rate, announced with final roster.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="cta-box">
        <h2 class="mt-0 mb-3" style="border: none; color: #ff6f61;">Talent &amp; partners</h2>
        <p class="text-light mb-3">If you are approached for this program, your card, likeness rules, and any commercial terms are handled directly in writing. Fans use the same site flows as the main collectible launch when sales open.</p>
        <a href="faq.php" class="btn btn-outline-secondary"><i class="fas fa-question-circle mr-1"></i>FAQ</a>
    </div>

    <p class="footnote mb-0">Roster, dates, and print specs for Launch will be announced when locked. This page describes the pro-talent track alongside the wider FSL collectible program.</p>
</div>

<?php
include_once 'includes/footer.php';
?>
