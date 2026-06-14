<?php
/**
 * FSL Collectible Cards: pro & talent revenue share overview (illustrative, not a contract).
 */
set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__);

ob_start();
session_start();

$pageTitle = 'Collectible Cards — Pro revenue share';
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
        padding: 1.15rem 1.35rem;
        margin-bottom: 1.1rem;
        border-left: 4px solid #ff6f61;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
    }
    .pcards-page .section p,
    .pcards-page .section li {
        color: #e0e0e0;
        line-height: 1.55;
        font-size: 0.95rem;
        margin-bottom: 0.5rem;
    }
    .pcards-page .table-rev th {
        color: #00d4ff;
        border-top: none;
        font-size: 0.9rem;
    }
    .pcards-page .table-rev td {
        color: #e8e8e8;
        font-size: 0.9rem;
        vertical-align: middle;
    }
    .pcards-page .table-rev .num {
        text-align: right;
        font-variant-numeric: tabular-nums;
    }
    .pcards-page .table-rev .note {
        font-size: 0.82rem;
        color: #aaa;
    }
    .pcards-page .callout {
        background: rgba(0, 212, 255, 0.08);
        border: 1px solid rgba(0, 212, 255, 0.3);
        border-radius: 10px;
        padding: 1rem 1.2rem;
        margin: 1rem 0;
        color: #d0d0d8;
        font-size: 0.92rem;
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

<header class="pcards-sell-header" role="banner" aria-label="Pro revenue share, FSL collectible cards">
    <div class="row align-items-center pcards-sell-header-inner">
        <div class="col-12 col-lg-7 text-center text-lg-left order-2 order-lg-1 pr-lg-4">
            <p class="pcards-sell-kicker mb-0">Invited pros, casters &amp; talent</p>
            <h1 class="pcards-sell-title">Your card. Your share.</h1>
            <p class="pcards-sell-lead mb-2">Sign on for the Launch wave and you get a <strong>real, premium player card</strong> with your likeness—and when the line reaches <strong>profitability</strong>, you participate in a dedicated <strong>talent pool</strong> split fairly across everyone in it.</p>
            <div class="d-flex flex-wrap align-items-center justify-content-center justify-content-lg-start">
                <ul class="pcards-sell-tags mb-0" aria-label="Program tags">
                    <li><span class="badge badge-info">Physical card</span></li>
                    <li><span class="badge badge-secondary">Talent pool</span></li>
                    <li><span class="badge badge-dark">StarCraft II</span></li>
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
                <figcaption class="pcards-pro-hero-caption text-lg-right text-center">Sample art only — private page; not a PSISTORM print</figcaption>
            </figure>
        </div>
    </div>
</header>

<div class="pcards-page">
    <div class="section">
        <h2 class="mt-0">Why sign up</h2>
        <p>You are a crucial part of the success of this StarCraft II player cards program. Launch Edition is built to feel collectible: foil, stats, art direction worthy of the scene. As part of the deal you receive <strong>your card</strong>, produced and shipped like the rest of the line.</p>
        <p class="mb-0">On top of that, we reserve a <strong>talent revenue share</strong>: when the program is in the black, a slice of profit flows into a pool for the people who agreed to be on the cards: <strong>pros, casters, and personalities</strong> in that cohort. Everyone in that pool shares it <strong>the same way</strong> so it stays simple and fair.</p>
    </div>

    <h2>Pack &amp; talent samples (illustrative)</h2>
    <p class="text-muted small mb-2">The same product story fans see at retail: a <strong>mystery pack</strong> plus the kind of <strong>caster / personality card</strong> treatment we layer into the line. Transparent PNGs; mockup branding is for look-and-feel only on this private preview.</p>
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
                     alt="Sample caster card front: Temp0-style layout (concept artwork)">
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

    <h2>Example card depth (illustrative)</h2>
    <p class="text-muted small mb-2">Shown at the detail level we target for invited talent—front face and a rich stat / bio back. Transparent PNGs so the art sits cleanly on the page.</p>
    <p class="pcards-sample-disclaimer mb-3">
        We use <strong>Serral</strong> here as the sample—the GOAT—to show what production depth looks like. This is a <strong>private</strong>, <strong>illustrative</strong> preview only, not a licensed PSISTORM product or public campaign.
        Team and sponsor marks on the mockup (including <strong>Basilisk</strong>) are <strong>not licensed</strong> for this page and do not imply endorsement or partnership—concept art for internal discussion.
    </p>
    <div class="row pcards-pro-showcase mb-4">
        <div class="col-md-6 mb-4 mb-md-0">
            <figure class="mb-0">
                <img src="images/playercards/serral-card-front.png"
                     alt="Example pro card front: player portrait, rank, winnings (sample artwork)">
                <figcaption>Front: hero presentation, team branding, headline stats.</figcaption>
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

    <div class="section">
        <h2 class="mt-0">How the pool works (simple version)</h2>
        <p>If and when the collectible card program is <strong>profitable</strong>, a <strong>percentage of profit</strong> (exact figure in your agreement) goes into the <strong>talent pool</strong>. That pool is then divided <strong>evenly</strong> among every participant in the pool for that window—so if <strong>20</strong> invited pros, casters, and celebs are in, each receives <strong>one twentieth</strong> of that pool for that distribution.</p>
        <p class="mb-0">Early waves may be modest; the reason to be in early is the card, the story, and <strong>growth</strong> as packs and future sets scale. Bigger runs mean a bigger pie for the same split logic. Each new card series will need you to opt in and get a new agreement or a blanket one, which we will work with you in writing.</p>
    </div>

    <h2>Illustrative split (sample math only)</h2>
    <p class="text-muted small mb-2">Not a forecast or offer. Rounded fiction to show how an even split feels when the talent pool and headcount change.</p>
    <div class="section p-0 overflow-hidden">
        <table class="table table-dark table-rev mb-0">
            <thead>
                <tr>
                    <th scope="col">Story</th>
                    <th scope="col" class="text-right">Sample talent pool</th>
                    <th scope="col" class="text-right">People in pool</th>
                    <th scope="col" class="text-right">Each receives (even split)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Early profitability</strong><br><span class="note">Smaller pool, same fairness.</span></td>
                    <td class="num">$4,000</td>
                    <td class="num">20</td>
                    <td class="num">~$200</td>
                </tr>
                <tr>
                    <td><strong>Solid season</strong><br><span class="note">More packs sold; pool grows.</span></td>
                    <td class="num">$18,000</td>
                    <td class="num">20</td>
                    <td class="num">~$900</td>
                </tr>
                <tr>
                    <td><strong>Strong wave</strong><br><span class="note">Retail scale or repeat buyers.</span></td>
                    <td class="num">$50,000</td>
                    <td class="num">20</td>
                    <td class="num">~$2,500</td>
                </tr>
                <tr>
                    <td><strong>Same pool, larger cohort</strong><br><span class="note">40 talent slots → thinner slice each.</span></td>
                    <td class="num">$50,000</td>
                    <td class="num">40</td>
                    <td class="num">~$1,250</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="callout mb-0">
        <strong>Heads up:</strong> the percentage of profit that funds the pool, payment timing, definitions, and any caps are spelled out only in a <strong>signed agreement</strong>. This page is the invitation to the idea—not the contract.
    </div>

    <p class="footnote mb-0">This page is not an offer or commitment. Revenue share terms are set solely in a written agreement between you and the program operators.</p>
</div>

<?php
include_once 'includes/footer.php';
?>
