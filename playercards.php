<?php
/**
 * FSL Collectible Player Cards: launch concept, eligibility, packs, roadmap.
 */
set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__);

ob_start();
session_start();

$pageTitle = 'Collectible Player Cards';
$additionalCss = [];

include_once 'includes/header.php';
?>

<style>
    /* Arena photo + gradient overlay; copy left, smaller product card right */
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
    .pcards-sell-header .pcards-sell-cardpeek img {
        max-height: 140px;
        width: auto;
        max-width: min(100%, 360px);
        border-radius: 10px;
        border: 1px solid rgba(255, 255, 255, 0.22);
        box-shadow:
            0 16px 40px rgba(0, 0, 0, 0.65),
            0 0 0 1px rgba(0, 212, 255, 0.2);
        transform: rotate(-4deg);
        vertical-align: middle;
        object-fit: contain;
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
    .pcards-page .section a {
        color: #00d4ff;
    }
    .pcards-page .section a:hover {
        color: #ff6f61;
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
    .pcards-page .pcards-eligibility-flow-svg {
        display: block;
        max-width: 640px;
        width: 100%;
        margin-left: auto;
        margin-right: auto;
    }
    .pcards-page .pcards-eligibility-roster {
        margin-top: 1rem;
        padding: 1rem 1.15rem;
        border-radius: 10px;
        border: 1px dashed rgba(0, 212, 255, 0.55);
        background: rgba(26, 26, 36, 0.92);
        max-width: 640px;
        margin-left: auto;
        margin-right: auto;
        text-align: left;
    }
    .pcards-page .table-rarity th {
        color: #00d4ff;
        border-top: none;
    }
    .pcards-page .table-rarity td {
        color: #e0e0e0;
        vertical-align: middle;
    }
    .pcards-page .pcards-launch-outreach {
        background: rgba(0, 212, 255, 0.08);
        border: 1px solid rgba(0, 212, 255, 0.3);
        border-radius: 10px;
    }
    .pcards-page .timeline-step {
        border-left: 3px solid rgba(0, 212, 255, 0.5);
        padding-left: 1rem;
        margin-bottom: 1.25rem;
    }
    .pcards-page .timeline-step strong {
        color: #ff6f61;
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
    @media (min-width: 992px) {
        .pcards-sell-header { min-height: 15rem; padding-top: 1.75rem; padding-bottom: 1.65rem; }
        .pcards-sell-header .pcards-sell-cardpeek img { max-height: 168px; max-width: 380px; }
    }
    @media (max-width: 767px) {
        .pcards-sell-header { min-height: 12rem; }
        .pcards-sell-header .pcards-sell-lead { font-size: 0.9rem; }
        .pcards-sell-header .pcards-sell-cardpeek img { max-height: 120px; max-width: 100%; transform: rotate(-2deg); }
    }
</style>

<header class="pcards-sell-header" role="banner" aria-label="Collectible player cards, FSL">
    <div class="row align-items-center pcards-sell-header-inner">
        <div class="col-12 col-lg-7 text-center text-lg-left order-2 order-lg-1 pr-lg-4">
            <p class="pcards-sell-kicker mb-0">FSL physical collectibles</p>
            <h1 class="pcards-sell-title">Collectible player cards</h1>
            <p class="pcards-sell-lead mb-2">Real, premium StarCraft-themed trading cards for the Fun StarCraft League and our extended circle, starting with a <strong>Limited Launch Edition</strong>, then packs, rarities, and the wider scene.</p>
            <div class="d-flex flex-wrap align-items-center justify-content-center justify-content-lg-start">
                <ul class="pcards-sell-tags mb-0" aria-label="Series tags">
                    <li><span class="badge badge-info">Launch Edition</span></li>
                    <li><span class="badge badge-secondary">Physical cards</span></li>
                    <li><span class="badge badge-dark">StarCraft II</span></li>
                </ul>
            </div>
        </div>
        <div class="col-12 col-lg-5 text-center text-lg-right order-1 order-lg-2 mb-3 mb-lg-0">
            <figure class="pcards-sell-cardpeek mb-0">
                <img class="img-fluid" src="images/playercards/mystery-pack-premium.png" width="360" height="201" alt="Sample premium mystery trading card pack (limited edition concept)" loading="eager">
            </figure>
        </div>
    </div>
</header>

<div class="pcards-page">
    <div class="section">
        <h2 class="mt-0">The idea</h2>
        <p>We are building a collectible card line that celebrates <strong>FSL players</strong>, hand-picked <strong>pros</strong>, and eventually <strong>casters</strong>, <strong>celebrities</strong>, and <strong>team cards</strong>, all in a cohesive sci-fi sports aesthetic. Cards are meant to feel like something you want on a shelf or in a binder: holographic edges, sharp typography, stats, and optional deep cuts like strengths and championship history.</p>
        <p>This page explains how the <strong>first launch</strong> works, who can take part, how packs and rarities are planned, and where we are headed next. Pricing and exact print numbers will be finalized once roster and vendor quotes are locked. Here you get the full picture of the vision.</p>
    </div>

    <h2>Showcase</h2>
    <p class="text-muted mb-3">Concept art and samples from our card series: front designs, a strengths or bio face, and additional player examples.</p>

    <h3 class="mt-4">Mystery pack &amp; talent cards (samples)</h3>
    <p class="text-muted small mb-3">What a foil booster-style pack and invited caster / personality cards can look like in the same line—transparent PNGs for layout previews. Branding on mockups is for direction only.</p>
    <div class="row gallery-row mb-4">
        <div class="col-lg-4 mb-4">
            <img src="images/playercards/mystery-pack-premium.png" alt="Premium mystery trading card pack with foil edges and limited edition numbering (concept)">
            <p class="gallery-caption">Mystery pack: foil wrap, serial sketch, sci-fi frame.</p>
        </div>
        <div class="col-lg-4 mb-4">
            <img src="images/playercards/tempo-card-front.png" alt="Sample caster card front: portrait, role, team branding (concept)">
            <p class="gallery-caption">Caster front: portrait, handle, role line.</p>
        </div>
        <div class="col-lg-4 mb-4">
            <img src="images/playercards/tempo-card-back.png" alt="Sample card back: bio and cred layout with branding sidebar (concept)">
            <p class="gallery-caption">Bio &amp; cred back: fields, story block, brand rail.</p>
        </div>
    </div>

    <div class="row gallery-row">
        <div class="col-md-6 mb-4">
            <img src="images/playercards/darkmenace-front.png" alt="Sample FSL player card front with portrait and stats">
            <p class="gallery-caption">Player front: portrait, division, maps and sets, team branding.</p>
        </div>
        <div class="col-md-6 mb-4">
            <img src="images/playercards/darkmenace-strengths.png" alt="Sample card back with radar strengths and championship medals">
            <p class="gallery-caption">Alternate face: strengths radar, medals, bio, expandable per player.</p>
        </div>
    </div>
    <div class="row gallery-row mb-4">
        <div class="col-12">
            <img src="images/playercards/sample-cards-collage.png" alt="Collage of multiple FSL-style player cards and generic card back">
            <p class="gallery-caption">Multiple roster styles and a branded series back. One template system, many stories.</p>
        </div>
    </div>

    <div class="section">
        <h2 class="mt-0">Why Launch Edition first</h2>
        <p>We are <strong>not</strong> framing this as an open-ended crowdfunding campaign with stretch goals. Launch Edition is a <strong>deliberate first print</strong>: smaller audience, clearer logistics, and a chance to prove printing, shipping, and artist workflows before we scale.</p>
        <ul>
            <li><strong>Limited and real</strong>: A finite run (or window) makes first-edition cards genuinely scarce later.</li>
            <li><strong>Lower risk</strong>: We start with people who already care about FSL and friends they invite.</li>
            <li><strong>Learn true costs</strong>: Foils, pack randomization, and international shipping get priced from experience, not guesses.</li>
            <li><strong>Then “the real one”</strong>: Broader retail, more SKUs, and refined pricing after Launch succeeds.</li>
        </ul>
    </div>

    <h2>Who can buy and who is on the cards</h2>
    <div class="section">
        <h3 class="mt-0">Eligibility (planned)</h3>
        <ul class="mb-0">
            <li><strong>Anyone who has ever played in the FSL</strong> may purchase through the site (FSL login; we can verify manually if needed).</li>
            <li>Each eligible player may invite a <strong>small number of friends</strong> (on the order of <strong>2 to 3 invitees</strong>) so the drop stays exclusive and invites stay valuable.</li>
            <li><strong>Invited friends</strong> may purchase while Launch rules allow.</li>
        </ul>
    </div>
    <div class="diagram-wrap" aria-label="Eligibility flow diagram">
        <svg class="pcards-eligibility-flow-svg" viewBox="0 0 640 100" role="img" preserveAspectRatio="xMidYMid meet">
            <title>Launch buyer eligibility</title>
            <defs>
                <linearGradient id="pcg1" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" style="stop-color:#1a3a52"/>
                    <stop offset="100%" style="stop-color:#2d1a40"/>
                </linearGradient>
            </defs>
            <rect x="8" y="8" width="200" height="84" rx="10" fill="url(#pcg1)" stroke="#00d4ff" stroke-width="1.5"/>
            <text x="108" y="38" text-anchor="middle" fill="#00d4ff" font-size="13" font-weight="bold">FSL alumni</text>
            <text x="108" y="58" text-anchor="middle" fill="#ccc" font-size="11">Ever played in FSL</text>
            <text x="108" y="78" text-anchor="middle" fill="#aaa" font-size="10">Login and optional invites</text>
            <path d="M 218 50 L 246 50" stroke="#ff6f61" stroke-width="2"/>
            <polygon points="252,50 242,46 242,54" fill="#ff6f61"/>
            <rect x="260" y="8" width="180" height="84" rx="10" fill="url(#pcg1)" stroke="#ff6f61" stroke-width="1.5"/>
            <text x="350" y="38" text-anchor="middle" fill="#ff6f61" font-size="13" font-weight="bold">Invitees</text>
            <text x="350" y="58" text-anchor="middle" fill="#ccc" font-size="11">2 to 3 per player (planned)</text>
            <text x="350" y="78" text-anchor="middle" fill="#aaa" font-size="10">Controlled growth</text>
            <path d="M 442 50 L 452 50" stroke="#ff6f61" stroke-width="2"/>
            <polygon points="458,50 448,46 448,54" fill="#ff6f61"/>
            <rect x="466" y="8" width="166" height="84" rx="10" fill="url(#pcg1)" stroke="#6c757d" stroke-width="1.5"/>
            <text x="549" y="38" text-anchor="middle" fill="#adb5bd" font-size="13" font-weight="bold">Launch store</text>
            <text x="549" y="58" text-anchor="middle" fill="#ccc" font-size="11">Customization and packs</text>
            <text x="549" y="78" text-anchor="middle" fill="#aaa" font-size="10">When sales open</text>
        </svg>
        <div class="pcards-eligibility-roster">
            <p class="mb-2 text-light">Roster: FSL players who <strong>opt in</strong> (including paid customization tier) and select non-league pros with signed agreements.</p>
            <p class="mb-0 small text-muted">FSL likeness: see <strong>Launch Edition — FSL player cards (opt-in)</strong> below for how to email consent. Pros receive their own card; revenue share is per individual agreement.</p>
        </div>
    </div>

    <h2>What you can get in Launch</h2>
    <div class="row">
        <div class="col-md-6">
            <div class="section h-100">
                <h3 class="mt-0"><i class="fas fa-paint-brush text-info mr-1"></i>Customize your card</h3>
                <p>FSL players who opt into the paid tier work with our artists on a <strong>limited number of revision rounds</strong> so portrait, framing, and details match how you want to be remembered on cardboard.</p>
                <p class="mb-0 text-muted small">Customization pricing ties to designer time, quoted separately from print.</p>
            </div>
        </div>
        <div class="col-md-6">
            <div class="section h-100">
                <h3 class="mt-0"><i class="fas fa-box-open text-warning mr-1"></i>Blind packs</h3>
                <p>Packs are <strong>randomized</strong>: you do not pick individual cards off a menu. That keeps pulls exciting and supports trading.</p>
                <p class="mb-0"><strong>Planned rule:</strong> when you open packs while logged in, <strong>you will not pull your own player card</strong> from random packs. Your personal card is handled through the customization or league path. (Manual exceptions possible for edge cases.)</p>
            </div>
        </div>
    </div>
    <div class="section">
        <h3 class="mt-0">Pros in Launch</h3>
        <p>A small set of <strong>professional players</strong> we contact directly will have <strong>written approval</strong> (likeness and terms). They receive <strong>their own card</strong> as part of the deal. Revenue share on Launch may be modest; the hook is a great card, being part of the first wave, and upside as future sets scale.</p>
    </div>

    <h2>Launch Edition — FSL player cards (opt-in)</h2>
    <p class="text-muted small mb-2">Cards featuring <strong>FSL league players</strong> are <strong>opt in only</strong>. We are <strong>not</strong> posting a roster of names here—nobody should assume they are included until they have agreed in writing.</p>
    <div class="mb-3 p-3 pcards-launch-outreach small text-light" role="note">
        <strong class="text-info">How to opt in</strong> — The player, or their <strong>parent or legal guardian</strong> when that applies, should email <a href="mailto:kj@psistorm.com" class="text-info">kj@psistorm.com</a> with <strong>consent and approval</strong> to join the Launch Edition card list. Include the player name and FSL handle so we can match records. We will reply with next steps.
    </div>

    <h2>Pack layout (planned)</h2>
    <div class="section">
        <p class="mb-2">Each pack is planned as <strong>10 cards</strong>: <strong>8 commons</strong> from the common pool and <strong>2 specials</strong> (foils, parallels, serials, or similar. Final treatments depend on print quotes).</p>
        <p class="mb-3"><strong>Super rare</strong> chases will be a small fraction of the overall print or appear at a published rate. Exact counts are set once the roster and total print run are fixed, then published <em>before</em> orders close.</p>
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
    <div class="diagram-wrap">
        <svg viewBox="0 0 400 140" width="400" height="140" role="img">
            <title>Rarity pyramid</title>
            <polygon points="200,15 340,125 60,125" fill="none" stroke="#00d4ff" stroke-width="1.5"/>
            <text x="200" y="105" text-anchor="middle" fill="#adb5bd" font-size="11">Common (wide base)</text>
            <text x="200" y="80" text-anchor="middle" fill="#5bc0de" font-size="11">Special</text>
            <text x="200" y="48" text-anchor="middle" fill="#ffc107" font-size="11">Super rare (apex)</text>
        </svg>
    </div>

    <h2>Roadmap after Launch</h2>
    <div class="section">
        <p>Future waves are <strong>planned</strong>, not all priced on day one. As we learn real costs from Launch, we will publish pricing and SKUs for the next steps.</p>
        <div class="timeline-step">
            <strong>Broader availability</strong>: Sales beyond the FSL and invite circle when we are ready operationally.
        </div>
        <div class="timeline-step">
            <strong>More product types</strong>: Randomized retail packs, additional foil and parallel treatments, boxed sets.
        </div>
        <div class="timeline-step">
            <strong>Expanded roster</strong>: More pros, casters, personalities, and <strong>team cards</strong>.
        </div>
        <div class="timeline-step">
            <strong>Clear economics</strong>: Revenue share and partner terms refined using Launch data; no bait-and-switch on what Launch includes.
        </div>
    </div>

    <h2>Costs and pricing (transparent)</h2>
    <div class="section">
        <ul class="mb-0">
            <li><strong>Print and materials</strong>: Stock, finishes, packaging, and overrun depend on vendor quotes and quantity.</li>
            <li><strong>Shipping</strong>: Weight and regions drive postage; we will state supported regions before we charge cards.</li>
            <li><strong>Payment processing and ops</strong>: Built into final prices with a contingency buffer.</li>
            <li><strong>Customization</strong>: Separately quoted from blind packs so players know what funds art vs print.</li>
        </ul>
    </div>

    <div class="cta-box">
        <h2 class="mt-0 mb-3" style="border: none; color: #ff6f61;">Get involved</h2>
        <p class="text-light mb-3">Create an FSL account to be ready when Launch opens. New to the league? Apply to play. We are building something worth collecting.</p>
        <a href="login.php" class="btn btn-info"><i class="fas fa-sign-in-alt mr-1"></i>Login</a>
        <a href="register.php" class="btn btn-outline-light"><i class="fas fa-user-plus mr-1"></i>Register</a>
        <a href="apply.php" class="btn btn-outline-info"><i class="fas fa-clipboard-list mr-1"></i>Apply to FSL</a>
        <a href="faq.php" class="btn btn-outline-secondary"><i class="fas fa-question-circle mr-1"></i>FAQ</a>
    </div>

    <p class="footnote mb-0">Launch Edition details, dates, and pricing will be announced when the roster and print specifications are finalized. This page describes the program direction for the FSL community.</p>
</div>

<?php
include_once 'includes/footer.php';
?>
