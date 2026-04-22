<?php
// Include header
include_once 'includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FSL Season History - Fun StarCraft League</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
            color: #e0e0e0;
            margin: 0;
            padding: 0;
            line-height: 1.6;
        }
        .container {
            max-width: 900px;
            margin: 20px auto;
            padding: 20px;
        }
        h1 {
            text-align: center;
            color: #00d4ff;
            text-shadow: 0 0 15px #00d4ff;
            font-size: 2.4em;
            margin-bottom: 8px;
        }
        .page-subtitle {
            text-align: center;
            color: #888;
            font-size: 0.95em;
            margin-bottom: 28px;
            letter-spacing: 1px;
        }

        /* ── Season quick-nav ── */
        .season-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            justify-content: center;
            margin-bottom: 30px;
            padding: 14px 18px;
            background: rgba(0, 0, 0, 0.25);
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.07);
            position: sticky;
            top: 4px;
            z-index: 90;
            backdrop-filter: blur(6px);
        }
        .season-nav a {
            padding: 5px 13px;
            border-radius: 20px;
            font-size: 0.82em;
            font-weight: 700;
            border: 1px solid rgba(255, 111, 97, 0.35);
            background: rgba(255, 111, 97, 0.08);
            color: #ff9e94;
            text-decoration: none;
            transition: all 0.2s ease;
            letter-spacing: 0.5px;
        }
        .season-nav a:hover {
            background: rgba(255, 111, 97, 0.22);
            border-color: #ff6f61;
            color: #fff;
            text-decoration: none;
        }
        .season-nav a.tl {
            border-color: rgba(255, 215, 0, 0.4);
            background: rgba(255, 215, 0, 0.07);
            color: #e8c840;
        }
        .season-nav a.tl:hover {
            background: rgba(255, 215, 0, 0.2);
            border-color: #ffd700;
            color: #fff;
        }
        .snav-divider {
            color: rgba(255, 255, 255, 0.2);
            align-self: center;
            font-size: 0.8em;
        }

        /* ── Hero video ── */
        .hero-video {
            margin-bottom: 6px;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 6px 30px rgba(0, 0, 0, 0.5);
            position: relative;
            padding-bottom: 45%;
            height: 0;
            max-width: 720px;
            margin-left: auto;
            margin-right: auto;
        }
        .hero-video iframe {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            border: 0;
        }
        .hero-caption {
            text-align: center;
            font-size: 0.8em;
            color: #666;
            margin-top: 6px;
            margin-bottom: 32px;
        }

        /* ── Era dividers ── */
        .era-divider {
            display: flex;
            align-items: center;
            gap: 14px;
            margin: 38px 0 22px;
            color: #666;
            font-size: 0.78em;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2.5px;
        }
        .era-divider::before,
        .era-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(255, 255, 255, 0.08);
        }

        /* ── Season cards ── */
        .season {
            background: rgba(255, 255, 255, 0.07);
            border-radius: 10px;
            padding: 22px 24px;
            margin-bottom: 22px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.35);
            border-left: 4px solid #ff6f61;
            transition: box-shadow 0.25s ease, border-left-color 0.25s ease;
            scroll-margin-top: 60px;
        }
        .season:hover {
            box-shadow: 0 6px 28px rgba(255, 111, 97, 0.18), 0 0 0 1px rgba(255, 111, 97, 0.2);
        }
        .season.tl {
            border-left-color: #c8a800;
        }
        .season.tl:hover {
            box-shadow: 0 6px 28px rgba(255, 215, 0, 0.14), 0 0 0 1px rgba(255, 215, 0, 0.2);
        }

        /* ── Card header ── */
        .season-header {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 0;
        }
        .season-num {
            font-size: 2.6em;
            font-weight: 900;
            line-height: 1;
            flex-shrink: 0;
            width: 52px;
            text-align: center;
            color: rgba(255, 111, 97, 0.22);
            padding-top: 2px;
        }
        .season.tl .season-num {
            color: rgba(255, 215, 0, 0.2);
        }
        .season-title-group {
            flex: 1;
        }
        .season-title-group h2 {
            color: #ff6f61;
            font-size: 1.5em;
            margin: 0 0 2px;
            line-height: 1.2;
        }
        .season.tl .season-title-group h2 {
            color: #e8c840;
        }
        .season-meta-line {
            font-size: 0.82em;
            color: #777;
            margin: 0;
        }

        /* ── Champion callout ── */
        .champion-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            padding: 8px 14px;
            background: rgba(255, 215, 0, 0.07);
            border-left: 3px solid rgba(255, 215, 0, 0.5);
            border-radius: 0 6px 6px 0;
            margin: 12px 0;
            font-size: 0.92em;
            color: #c8a800;
        }
        .champion-row strong {
            color: #ffd700;
        }
        .champion-row a {
            color: #ffd700;
            font-weight: bold;
        }
        .champion-row a:hover {
            color: #fff;
        }
        .champion-note {
            color: #888;
            font-size: 0.9em;
        }

        /* ── Description ── */
        .season-desc {
            font-size: 1em;
            color: #ccc;
            margin: 0 0 12px;
            line-height: 1.6;
        }
        .season-link {
            font-size: 0.92em;
            margin-bottom: 12px;
        }

        /* ── Results block ── */
        .results {
            background: rgba(0, 0, 0, 0.3);
            padding: 14px 16px;
            border-radius: 6px;
            margin: 12px 0;
            font-family: 'Courier New', monospace;
            color: #00ff9d;
            font-size: 0.88em;
            line-height: 1.7;
            overflow-x: auto;
        }
        .results em {
            font-style: normal;
        }
        .results a {
            color: #7fffcf;
            text-decoration: none;
            border-bottom: 1px dotted rgba(127, 255, 207, 0.4);
        }
        .results a:hover {
            color: #fff;
            border-bottom-color: #fff;
            text-decoration: none;
        }

        /* ── Collapsible toggles ── */
        .toggle-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-top: 10px;
            padding: 7px 16px;
            background: rgba(0, 212, 255, 0.07);
            border: 1px solid rgba(0, 212, 255, 0.3);
            border-radius: 6px;
            color: #00d4ff;
            font-size: 0.88em;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease, border-color 0.2s ease;
            font-family: 'Arial', sans-serif;
            margin-right: 8px;
        }
        .toggle-btn:hover {
            background: rgba(0, 212, 255, 0.15);
            border-color: #00d4ff;
        }
        .toggle-btn.roster-btn {
            background: rgba(255, 111, 97, 0.07);
            border-color: rgba(255, 111, 97, 0.3);
            color: #ff9e94;
        }
        .toggle-btn.roster-btn:hover {
            background: rgba(255, 111, 97, 0.15);
            border-color: #ff6f61;
        }
        .collapsible-panel {
            margin-top: 10px;
        }
        .collapsible-panel ul {
            margin: 6px 0 4px 0;
            padding-left: 20px;
        }
        .collapsible-panel li {
            margin-bottom: 6px;
            font-size: 0.97em;
        }
        .collapsible-panel p {
            margin: 10px 0 4px;
            font-size: 0.95em;
            font-weight: 600;
            color: #ccc;
        }

        /* ── Links ── */
        a {
            color: #00d4ff;
            text-decoration: none;
            transition: color 0.2s ease;
        }
        a:hover {
            color: #ff6f61;
            text-decoration: underline;
        }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            h1 { font-size: 1.9em; }
            .season { padding: 16px; }
            .season-num { font-size: 2em; width: 42px; }
            .season-title-group h2 { font-size: 1.3em; }
            .results { font-size: 0.8em; }
            .season-nav { top: 0; padding: 10px 12px; gap: 5px; }
            .season-nav a { padding: 4px 10px; font-size: 0.78em; }
            .hero-video { padding-bottom: 56%; }
        }
        @media (max-width: 480px) {
            .container { padding: 10px; }
            .season-header { gap: 10px; }
            .season-num { display: none; }
            .season { border-left-width: 3px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>FSL Season History</h1>
        <p class="page-subtitle">10 Seasons &nbsp;·&nbsp; 2020 – 2026 &nbsp;·&nbsp; PSISTORM Gaming</p>

        <!-- Season quick-nav -->
        <nav class="season-nav">
            <a href="#10" class="tl">S10</a>
            <a href="#9" class="tl">S9</a>
            <a href="#8" class="tl">S8</a>
            <span class="snav-divider">|</span>
            <a href="#7">S7</a>
            <a href="#6">S6</a>
            <a href="#5">S5</a>
            <a href="#4">S4</a>
            <a href="#3">S3</a>
            <a href="#2">S2</a>
            <a href="#1">S1</a>
        </nav>

        <!-- Hero video -->
        <div class="hero-video">
            <iframe src="https://www.youtube.com/embed/vt04Xbq57Dk?mute=1&vq=medium&autoplay=1&origin=http://psistorm.com" allow="autoplay; encrypted-media" allowfullscreen></iframe>
        </div>
        <p class="hero-caption">9 Seasons of FSL — a look back</p>

        <!-- ═══════════════════════ TEAM LEAGUE ERA ═══════════════════════ -->
        <div class="era-divider">⚔ Team League Era</div>

        <!-- ── Season 10 ── -->
        <div class="season tl" id="10">
            <div class="season-header">
                <div class="season-num">10</div>
                <div class="season-title-group">
                    <h2>The Three-Peat &nbsp;🏆🏆🏆</h2>
                    <p class="season-meta-line">Jan – Apr 2026 &nbsp;·&nbsp; GSL Group Stage + Ladder Playoff</p>
                </div>
            </div>

            <div class="champion-row">
                🏆 <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> (Team League)
                &nbsp;·&nbsp; Code S: TBD
                &nbsp;·&nbsp; <a href="view_player.php?name=Jmpz">Jmpz</a> (Code A)
                &nbsp;·&nbsp; <a href="view_player.php?name=WindShadow">WindShadow</a> (Code B)
                <span class="champion-note">&nbsp;— 3rd consecutive FSL Team League title</span>
            </div>

            <p class="season-desc">History was made. <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> completed a <strong>three-peat</strong> across Seasons 8, 9, and 10, edging out <a href="view_team.php?name=PSIOP+Gaming">PSIOP Gaming</a> <strong>10–8</strong> in the Grand Finals on March 28th. Season 10 introduced a new GSL-style group stage to determine seeding before the three-week playoff ladder. <strong>Individual championships:</strong> Code A and Code B have crowned champions; Code S is still <strong>TBD</strong>.</p>

            <p class="season-link"><a href="fsl_standings.php?season=10">View Full Season 10 Standings &amp; Schedule →</a></p>

            <div class="results">
                <strong>Final Standings:</strong><br>
                🥇 <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> (3–2) &nbsp; 🥈 <a href="view_team.php?name=PSIOP+Gaming">PSIOP Gaming</a> (2–1) &nbsp; 🥉 <a href="view_team.php?name=Angry+Space+Hares">Angry Space Hares</a> (2–2) &nbsp; 4th <a href="view_team.php?name=Special+Tactics">Special Tactics</a> (1–3)<br><br>
                <strong>Group Stage:</strong><br>
                Wk1 <a href="view_team.php?name=Special+Tactics">Special Tactics</a> def. <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> 7–3 &nbsp;·&nbsp; Wk2 <a href="view_team.php?name=PSIOP+Gaming">PSIOP</a> def. <a href="view_team.php?name=Angry+Space+Hares">Angry Space Hares</a> 7–6<br>
                Wk3 <a href="view_team.php?name=PSIOP+Gaming">PSIOP</a> def. <a href="view_team.php?name=Special+Tactics">Special Tactics</a> 8–2 (→ Seed 1) &nbsp;·&nbsp; Wk4 <a href="view_team.php?name=Angry+Space+Hares">ASH</a> def. <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> 8–4 (→ Seed 4)<br>
                Wk5 <a href="view_team.php?name=Angry+Space+Hares">ASH</a> def. <a href="view_team.php?name=Special+Tactics">Special Tactics</a> 7–5 (→ Seeds 2 &amp; 3)<br><br>
                <strong>Playoffs:</strong><br>
                Wk6 <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> def. <a href="view_team.php?name=Special+Tactics">Special Tactics</a> 10–2 &nbsp;·&nbsp; Wk7 <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> def. <a href="view_team.php?name=Angry+Space+Hares">ASH</a> 8–4<br>
                Wk8 <strong>GRAND FINALS:</strong> <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> def. <a href="view_team.php?name=PSIOP+Gaming">PSIOP Gaming</a> 10–8 🏆<br><br>
                <strong>Individual championships:</strong><br>
                <strong>Code S:</strong> TBD<br>
                <strong>Code A:</strong> <a href="view_player.php?name=Jmpz">Jmpz</a><br>
                <strong>Code B:</strong> <a href="view_player.php?name=WindShadow">WindShadow</a>
            </div>

            <button class="toggle-btn" onclick="togglePanel(this, 'vods-s10')">&#9658; Show Match VODs (8 weeks)</button>
            <div id="vods-s10" class="collapsible-panel" style="display:none;">
                <ul>
                    <li>Wk 1 — <a href="view_team.php?name=Special+Tactics">Special Tactics</a> vs <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a>: <a href="https://www.youtube.com/watch?v=6lFY1XhUOCc" target="_blank">VOD</a></li>
                    <li>Wk 2 — <a href="view_team.php?name=PSIOP+Gaming">PSIOP Gaming</a> vs <a href="view_team.php?name=Angry+Space+Hares">Angry Space Hares</a>: <a href="https://www.youtube.com/watch?v=YWPc4xHUQ8E" target="_blank">VOD</a></li>
                    <li>Wk 3 — <a href="view_team.php?name=PSIOP+Gaming">PSIOP Gaming</a> vs <a href="view_team.php?name=Special+Tactics">Special Tactics</a> (Winners Match): <a href="https://www.youtube.com/watch?v=dyNCfg0IoOs" target="_blank">VOD</a></li>
                    <li>Wk 4 — <a href="view_team.php?name=Angry+Space+Hares">Angry Space Hares</a> vs <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> (Losers Match): <a href="https://www.youtube.com/watch?v=mWHw_HW52ZQ" target="_blank">VOD</a></li>
                    <li>Wk 5 — <a href="view_team.php?name=Angry+Space+Hares">Angry Space Hares</a> vs <a href="view_team.php?name=Special+Tactics">Special Tactics</a> (Elimination): <a href="https://www.youtube.com/watch?v=kUEYXANSvII" target="_blank">VOD</a></li>
                    <li>Wk 6 — <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> vs <a href="view_team.php?name=Special+Tactics">Special Tactics</a> (Playoff Wk 1): <a href="https://www.youtube.com/watch?v=91L1SwNrE80" target="_blank">VOD</a></li>
                    <li>Wk 7 — <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> vs <a href="view_team.php?name=Angry+Space+Hares">Angry Space Hares</a> (Playoff Wk 2): <a href="https://www.youtube.com/watch?v=jLybo0P63Ks" target="_blank">VOD</a></li>
                    <li><strong>Wk 8 — Grand Finals: <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> vs <a href="view_team.php?name=PSIOP+Gaming">PSIOP Gaming</a>: <a href="https://www.youtube.com/watch?v=u24DOq-EUHI" target="_blank">VOD</a></strong></li>
                </ul>
            </div>
        </div>

        <!-- ── Season 9 ── -->
        <div class="season tl" id="9">
            <div class="season-header">
                <div class="season-num">9</div>
                <div class="season-title-group">
                    <h2>Team League Draft &nbsp;🏆🏆</h2>
                    <p class="season-meta-line">Jun – Dec 2025 &nbsp;·&nbsp; 5-Team Round-Robin + Playoffs</p>
                </div>
            </div>

            <div class="champion-row">
                🏆 <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a>
                <span class="champion-note">— back-to-back FSL Team League titles</span>
            </div>

            <p class="season-desc">Five teams competed in a 20-week double round-robin, with the top three advancing to a playoff bracket. <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> finished 2nd in the regular season but dominated the playoffs, defeating <a href="view_team.php?name=Rages+Raiders">Rages Raiders</a> in the semi-final and upsetting top-seeded <a href="view_team.php?name=Angry+Space+Hares">Angry Space Hares</a> in the final.</p>

            <div class="results">
                <strong>Regular Season Standings:</strong><br>
                🥇 <a href="view_team.php?name=Angry+Space+Hares">Angry Space Hares</a> 7–1 &nbsp; 🥈 <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> 6–2 &nbsp; <a href="view_team.php?name=Rages+Raiders">Rages Raiders</a> 3–5 &nbsp; <a href="view_team.php?name=Cheesy+Nachos">Cheesy Nachos</a> 2–6 &nbsp; <a href="view_team.php?name=Infinite+Cyclists">Infinite Cyclists</a> 2–6<br><br>
                <strong>Playoffs:</strong><br>
                Semi-final — <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> def. <a href="view_team.php?name=Rages+Raiders">Rages Raiders</a> 6–2<br>
                <strong>Final — <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> def. <a href="view_team.php?name=Angry+Space+Hares">Angry Space Hares</a> 5–3 🏆</strong>
            </div>

            <button class="toggle-btn roster-btn" onclick="togglePanel(this, 'rosters-s9')">&#9658; Show Team Rosters</button>
            <div id="rosters-s9" class="collapsible-panel" style="display:none;">
                <div class="results" style="margin-top:8px;">
                    <strong><a href="view_team.php?name=Cheesy+Nachos">Cheesy Nachos</a></strong> (Capt: <a href="view_player.php?name=Nachoz">Nachoz</a>): <a href="view_player.php?name=SgtABC">SgtABC</a>, <a href="view_player.php?name=AntoineQ">AntoineQ</a>, <a href="view_player.php?name=Fenrir">Fenrir</a>, <a href="view_player.php?name=Note">Note</a>, <a href="view_player.php?name=Instability">Instability</a><br>
                    <strong><a href="view_team.php?name=Rages+Raiders">Rages Raiders</a></strong> (Capt: <a href="view_player.php?name=RevenantRage">RevenantRage</a>): <a href="view_player.php?name=NukLeo">NukLeo</a>, <a href="view_player.php?name=LanixMagi">LanixMagi</a>, <a href="view_player.php?name=Adastra">Adastra</a>, <a href="view_player.php?name=WindShadow">WindShadow</a>, <a href="view_player.php?name=ShadeHealer">ShadeHealer</a><br>
                    <strong><a href="view_team.php?name=Infinite+Cyclists">Infinite Cyclists</a></strong> (Capt: <a href="view_player.php?name=HyperTurtle">HyperTurtle</a>): <a href="view_player.php?name=GreatArchon">GreatArchon</a>, <a href="view_player.php?name=ArduousGem">ArduousGem</a>, <a href="view_player.php?name=SCVSir">SCVSir</a>, <a href="view_player.php?name=Staged">Staged</a><br>
                    <strong><a href="view_team.php?name=Angry+Space+Hares">Angry Space Hares</a></strong> (Capt: <a href="view_player.php?name=Warbunnies">Warbunnies</a>): <a href="view_player.php?name=Chat-Omic">Chat-omic</a>, <a href="view_player.php?name=Pebble">Pebble</a>, <a href="view_player.php?name=Sopuli">Sopuli</a>, <a href="view_player.php?name=HurtnTime">HurtnTime</a><br>
                    <strong><a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a></strong> (Capt: <a href="view_player.php?name=Neutrophil">Neutrophil</a>): <a href="view_player.php?name=Dpoo">Dpoo</a>, <a href="view_player.php?name=ChienPwn">ChienPwn</a>, <a href="view_player.php?name=MedicJr">MedicJr</a>, <a href="view_player.php?name=MvonLipwig">MvonLipwig</a>, <a href="view_player.php?name=MonkeyShaman">MonkeyShaman</a><br>
                    <em style="color:#888;">Protected: <a href="view_player.php?name=Freeedom">Freeedom</a>, <a href="view_player.php?name=Sequovia">Sequovia</a>, <a href="view_player.php?name=HarOuz">HarOuz</a>, <a href="view_player.php?name=LightHood">LightHood</a>, <a href="view_player.php?name=DarkMenace">DarkMenace</a>, <a href="view_player.php?name=SirMalagant">SirMalagant</a>, <a href="view_player.php?name=NukLeo">Nuke</a>, <a href="view_player.php?name=Greeempire">Greeempire</a>, <a href="view_player.php?name=Vales">Vales</a>, <a href="view_player.php?name=LittleReaper">LittleReaper</a></em>
                </div>
            </div>

            <button class="toggle-btn" onclick="togglePanel(this, 'vods-s9')">&#9658; Show Match VODs (21 weeks + highlights)</button>
            <div id="vods-s9" class="collapsible-panel" style="display:none;">
                <p>Weekly Matches:</p>
                <ul>
                    <li>Wk 1 — <a href="view_team.php?name=Angry+Space+Hares">ASH</a> def. <a href="view_team.php?name=Infinite+Cyclists">Infinite Cyclists</a> 5–4: <a href="https://www.youtube.com/watch?v=s7JRKY5zmnk" target="_blank">VOD</a></li>
                    <li>Wk 2 — <a href="view_team.php?name=Cheesy+Nachos">Cheesy Nachos</a> def. <a href="view_team.php?name=Rages+Raiders">Rages Raiders</a> 5–4: <a href="https://www.youtube.com/watch?v=jfrq-30Nv8Y" target="_blank">VOD</a></li>
                    <li>Wk 3 — <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> def. <a href="view_team.php?name=Cheesy+Nachos">Cheesy Nachos</a> 5–3: <a href="https://www.youtube.com/watch?v=m2oaEMhgJ9E" target="_blank">VOD</a></li>
                    <li>Wk 4 — <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> def. <a href="view_team.php?name=Angry+Space+Hares">ASH</a> 5–4: <a href="https://www.youtube.com/watch?v=9FBAg879sLY" target="_blank">VOD 1</a>, <a href="https://www.youtube.com/watch?v=ItRinwxGK_4" target="_blank">VOD 2</a></li>
                    <li>Wk 5 — <a href="view_team.php?name=Cheesy+Nachos">Cheesy Nachos</a> def. <a href="view_team.php?name=Infinite+Cyclists">Infinite Cyclists</a> 5–4: <a href="https://www.youtube.com/watch?v=sLz0Itq46KA" target="_blank">VOD</a></li>
                    <li>Wk 6 — <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> def. <a href="view_team.php?name=Rages+Raiders">Rages Raiders</a> 6–2: <a href="https://www.youtube.com/watch?v=dGdZyzWvk3I" target="_blank">VOD</a></li>
                    <li>Wk 7 — <a href="view_team.php?name=Angry+Space+Hares">ASH</a> def. <a href="view_team.php?name=Cheesy+Nachos">Cheesy Nachos</a> 6–2: <a href="https://www.youtube.com/watch?v=hxAA47qAnig" target="_blank">VOD</a></li>
                    <li>Wk 8 — <a href="view_team.php?name=Infinite+Cyclists">Infinite Cyclists</a> def. <a href="view_team.php?name=Rages+Raiders">Rages Raiders</a> 8–0: <a href="https://www.youtube.com/watch?v=9cV-6NJThN4" target="_blank">VOD</a></li>
                    <li>Wk 9 — <a href="view_team.php?name=Angry+Space+Hares">ASH</a> def. <a href="view_team.php?name=Rages+Raiders">Rages Raiders</a> 6–2: <a href="https://www.youtube.com/live/2uczg2xipQY" target="_blank">VOD</a></li>
                    <li>Wk 10 — <a href="view_team.php?name=Infinite+Cyclists">Infinite Cyclists</a> def. <a href="view_team.php?name=Cheesy+Nachos">Cheesy Nachos</a> 5–1: <a href="https://www.youtube.com/watch?v=xE1hUMzquf0" target="_blank">VOD</a></li>
                    <li>Wk 11 — <a href="view_team.php?name=Rages+Raiders">Rages Raiders</a> def. <a href="view_team.php?name=Cheesy+Nachos">Cheesy Nachos</a> 5–4: <a href="https://www.youtube.com/watch?v=pbGkmOoglT8" target="_blank">VOD</a></li>
                    <li>Wk 12 — <a href="view_team.php?name=Angry+Space+Hares">ASH</a> def. <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> 5–3: <a href="https://www.youtube.com/live/2WYD_z0HKzs" target="_blank">VOD</a></li>
                    <li>Wk 13 — <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> def. <a href="view_team.php?name=Infinite+Cyclists">Infinite Cyclists</a> 5–3: <a href="https://www.youtube.com/watch?v=pbGkmOoglT8" target="_blank">VOD</a></li>
                    <li>Wk 14 — <a href="view_team.php?name=Angry+Space+Hares">ASH</a> def. <a href="view_team.php?name=Cheesy+Nachos">Cheesy Nachos</a> 6–2: <a href="https://www.youtube.com/watch?v=0YZUn5bfmpw" target="_blank">VOD</a></li>
                    <li>Wk 15 — <a href="view_team.php?name=Rages+Raiders">Rages Raiders</a> def. <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> 6–2: <a href="https://www.youtube.com/watch?v=zMCMNTze63Q" target="_blank">VOD</a></li>
                    <li>Wk 16 — <a href="view_team.php?name=Angry+Space+Hares">ASH</a> def. <a href="view_team.php?name=Infinite+Cyclists">Infinite Cyclists</a> 4–3: <a href="https://www.youtube.com/watch?v=Ol5gHoqA_7I" target="_blank">VOD</a></li>
                    <li>Wk 17 — <a href="view_team.php?name=Rages+Raiders">Rages Raiders</a> def. <a href="view_team.php?name=Infinite+Cyclists">Infinite Cyclists</a> 5–4: <a href="https://www.youtube.com/watch?v=gZLc2F5J60U" target="_blank">VOD</a></li>
                    <li>Wk 19 — <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> def. <a href="view_team.php?name=Infinite+Cyclists">Infinite Cyclists</a> 5–4: <a href="https://www.youtube.com/watch?v=ms_NeXR6sbk" target="_blank">VOD</a></li>
                    <li>Wk 20 — <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> def. <a href="view_team.php?name=Cheesy+Nachos">Cheesy Nachos</a> 6–2: <a href="https://youtu.be/IFXBBj8weYQ" target="_blank">VOD</a></li>
                    <li>Playoff Semi — <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> def. <a href="view_team.php?name=Rages+Raiders">Rages Raiders</a> 6–2: <a href="https://www.youtube.com/live/vbbXT2UQXx4" target="_blank">VOD</a></li>
                    <li><strong>Playoff Final</strong> — <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> def. <a href="view_team.php?name=Angry+Space+Hares">ASH</a> 5–3: <a href="https://www.youtube.com/live/uVBqTfqo2ow?t=772s" target="_blank">VOD 1</a>, <a href="https://www.youtube.com/live/xuG9HleTUCM" target="_blank">VOD 2</a></li>
                </ul>
                <p>Highlights &amp; Special Events:</p>
                <ul>
                    <li><a href="https://www.youtube.com/watch?v=vt04Xbq57Dk&list=PLuxOPc104MmmySmrbE813nXWuamZpecw5&index=34&pp=gAQBiAQBsAgC" target="_blank">9 Seasons of FSL Video</a></li>
                    <li><a href="https://www.youtube.com/watch?v=kqnD8K0Tfnw&list=PLuxOPc104MmmySmrbE813nXWuamZpecw5&index=35&pp=gAQBiAQBsAgC" target="_blank">Team League Highlights</a></li>
                    <li><a href="https://www.youtube.com/live/pOdCKQ67Qjg?si=vUqPiToCg_cAlkrD&t=480" target="_blank">Individual Championships — Code B</a></li>
                    <li><a href="https://www.youtube.com/watch?v=7pRh7ohhXrk&list=PLuxOPc104MmmySmrbE813nXWuamZpecw5&index=37&pp=gAQBiAQBsAgC" target="_blank">Individual Championships — Code A &amp; S</a></li>
                </ul>
            </div>
        </div>

        <!-- ── Season 8 ── -->
        <div class="season tl" id="8">
            <div class="season-header">
                <div class="season-num">8</div>
                <div class="season-title-group">
                    <h2>Team League Expansion &nbsp;🏆</h2>
                    <p class="season-meta-line">Oct 2024 &nbsp;·&nbsp; 5-Team Round-Robin + Playoffs</p>
                </div>
            </div>

            <div class="champion-row">
                🏆 <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> (Team League) &nbsp;·&nbsp; <a href="view_player.php?name=DarkMenace">DarkMenace</a> (Code S) &nbsp;·&nbsp; <a href="view_player.php?name=LittleReaper">LittleReaper</a> (Code A) &nbsp;·&nbsp; <a href="view_player.php?name=ChienPwn">ChienPwn</a> (Code B)
            </div>

            <p class="season-desc">Season 8 launched FSL's Team League format alongside individual Code S/A/B divisions — the biggest structural change in the league's history. Five teams competed in a Round-Robin with Bo9 matches, with the top 4 advancing to playoffs.</p>

            <div class="results">
                <strong>Team League:</strong> <a href="view_team.php?name=PulledTheBoys">PulledTheBoys</a> def. <a href="view_team.php?name=Angry+Space+Hares">Angry Space Hares</a> 5–3<br>
                <strong>Code S:</strong> <a href="view_player.php?name=DarkMenace">DarkMenace</a> def. <a href="view_player.php?name=Neutrophil">Neutrophil</a> 4–0 &nbsp;·&nbsp; <strong>Code A:</strong> <a href="view_player.php?name=LittleReaper">LittleReaper</a> def. <a href="view_player.php?name=Regret">Regret</a> 3–2<br>
                <strong>Code B:</strong> <a href="view_player.php?name=ChienPwn">ChienPwn</a> def. <a href="view_player.php?name=SgtABC">SgtABC</a> 2–0 &nbsp;·&nbsp; <strong>2v2:</strong> <a href="view_player.php?name=Vales">Vales</a>/<a href="view_player.php?name=Instability">Instability</a> def. <a href="view_player.php?name=Neutrophil">Neutrophil</a>/<a href="view_player.php?name=Dpoo">Dpoo</a> 2–0
            </div>

            <button class="toggle-btn" onclick="togglePanel(this, 'vods-s8')">&#9658; Show VODs</button>
            <div id="vods-s8" class="collapsible-panel" style="display:none;">
                <ul>
                    <li><a href="https://www.youtube.com/watch?v=2MTRr7qjlyU&list=PLuxOPc104MmkHE2lBVyd_FE0AU_euJEfe" target="_blank">Full Season 8 VOD Playlist</a></li>
                </ul>
            </div>
        </div>

        <!-- ═══════════════════════ CLASSIC INDIVIDUAL ERA ═══════════════════════ -->
        <div class="era-divider">🎮 Classic Individual Era</div>

        <!-- ── Season 7 ── -->
        <div class="season" id="7">
            <div class="season-header">
                <div class="season-num">7</div>
                <div class="season-title-group">
                    <h2>Code S+ Debut</h2>
                    <p class="season-meta-line">2023 &nbsp;·&nbsp; Code S+, S, A, B &amp; 2v2+/2v2</p>
                </div>
            </div>

            <div class="champion-row">
                🏆 <a href="view_player.php?name=DarkMenace">DarkMenace</a> (Code S+) &nbsp;·&nbsp; <a href="view_player.php?name=LightHood">LightHood</a> (Code S) &nbsp;·&nbsp; <a href="view_player.php?name=HyperTurtle">HyperTurtle</a> (Code A) &nbsp;·&nbsp; <a href="view_player.php?name=RevenantRage">RevenantRage</a> (Code B)
            </div>

            <p class="season-desc">Season 7 introduced <strong>Code S+</strong> as a new elite tier above Code S. <a href="view_player.php?name=DarkMenace">DarkMenace</a> dominated, knocking out Code S champion <a href="view_player.php?name=LightHood">LightHood</a> in the S+ semis before winning the Grand Final 4–1 over <a href="view_player.php?name=HarOuz">HarOuz</a>. 2v2 split into two tiers this season.</p>

            <div class="results">
                <strong>Code S+:</strong> <a href="view_player.php?name=DarkMenace">DarkMenace</a> def. <a href="view_player.php?name=HarOuz">HarOuz</a> 4–1<br>
                <em style="color:#888;">&nbsp;SF: <a href="view_player.php?name=DarkMenace">DarkMenace</a> def. <a href="view_player.php?name=LightHood">LightHood</a> 3–0 &nbsp;|&nbsp; <a href="view_player.php?name=HarOuz">HarOuz</a> def. <a href="view_player.php?name=Neutrophil">Neutrophil</a> 3–2</em><br>
                <strong>Code S:</strong> <a href="view_player.php?name=LightHood">LightHood</a> def. <a href="view_player.php?name=HurtnTime">HurtnTime</a> 3–1<br>
                <em style="color:#888;">&nbsp;SF: <a href="view_player.php?name=LightHood">LightHood</a> def. <a href="view_player.php?name=HaKi">HaKi</a> &nbsp;|&nbsp; <a href="view_player.php?name=HurtnTime">HurtnTime</a> def. <a href="view_player.php?name=Grey">Grey</a></em><br>
                <strong>Code A:</strong> <a href="view_player.php?name=HyperTurtle">HyperTurtle</a> def. <a href="view_player.php?name=Dpoo">Dpoo</a> 3–1 &nbsp;·&nbsp; <strong>Code B:</strong> <a href="view_player.php?name=RevenantRage">RevenantRage</a> def. <a href="view_player.php?name=Nachoz">Nachoz</a> 3–1<br>
                <strong>2v2+:</strong> <a href="view_player.php?name=Vales">Vales</a>/<a href="view_player.php?name=HurtnTime">HurtnTime</a> def. <a href="view_player.php?name=Dpoo">Dpoo</a>/<a href="view_player.php?name=Neutrophil">Neutrophil</a> 3–2 &nbsp;·&nbsp; <strong>2v2:</strong> <a href="view_player.php?name=Warbunnies">Warbunnies</a>/<a href="view_player.php?name=Greeempire">Greeempire</a> def. <a href="view_player.php?name=HarOuz">HarOuz</a>/<a href="view_player.php?name=LightHood">LightHood</a> 2–0
            </div>

            <button class="toggle-btn" onclick="togglePanel(this, 'vods-s7')">&#9658; Show VODs</button>
            <div id="vods-s7" class="collapsible-panel" style="display:none;">
                <ul>
                    <li>Code B — Group Stage: <a href="https://youtu.be/W6Ps9HIB9NE" target="_blank">VOD</a></li>
                    <li><a href="https://www.youtube.com/watch?v=Hs9IkzL6tvs&list=PLuxOPc104Mml72KGVnRPuFmgyudPaw7FT" target="_blank">Full Season 7 Playlist</a></li>
                </ul>
            </div>
        </div>

        <!-- ── Season 6 ── -->
        <div class="season" id="6">
            <div class="season-header">
                <div class="season-num">6</div>
                <div class="season-title-group">
                    <h2>Multi-Tier Mastery</h2>
                    <p class="season-meta-line">2023 &nbsp;·&nbsp; Code S, A, B &amp; 2v2</p>
                </div>
            </div>

            <div class="champion-row">
                🏆 <a href="view_player.php?name=Neutrophil">Neutrophil</a> (Code S) &nbsp;·&nbsp; <a href="view_player.php?name=Grey">Grey</a> (Code A) &nbsp;·&nbsp; <a href="view_player.php?name=Fenrir">Fenrir</a> (Code B)
            </div>

            <p class="season-desc">Season 6 refined the tiered system with Code S, A, B, and 2v2, delivering a full competitive ladder with Round-Robin group play and Bo5 playoffs.</p>

            <div class="results">
                <strong>Code S:</strong> <a href="view_player.php?name=Neutrophil">Neutrophil</a> def. <a href="view_player.php?name=Vales">Vales</a> 4–3 &nbsp;·&nbsp; <strong>Code A:</strong> <a href="view_player.php?name=Grey">Grey</a> def. <a href="view_player.php?name=cyan">Cyan</a> 4–1<br>
                <strong>Code B:</strong> <a href="view_player.php?name=Fenrir">Fenrir</a> def. <a href="view_player.php?name=ChienPwn">ChienPwn</a> 3–2 &nbsp;·&nbsp; <strong>2v2:</strong> <a href="view_player.php?name=Vales">Vales</a>/<a href="view_player.php?name=HurtnTime">HurtnTime</a> def. <a href="view_player.php?name=Instability">Instability</a>/<a href="view_player.php?name=CaliberC">CaliberC</a> 4–0
            </div>

            <button class="toggle-btn" onclick="togglePanel(this, 'vods-s6')">&#9658; Show VODs</button>
            <div id="vods-s6" class="collapsible-panel" style="display:none;">
                <ul>
                    <li><a href="https://www.youtube.com/watch?v=ELJABHO41tM&list=PLuxOPc104MmmlzYYRxK49HNMSKfNKB9ee" target="_blank">Full Season 6 Playlist</a></li>
                </ul>
            </div>
        </div>

        <!-- ── Season 5 ── -->
        <div class="season" id="5">
            <div class="season-header">
                <div class="season-num">5</div>
                <div class="season-title-group">
                    <h2>2v2 Comes of Age</h2>
                    <p class="season-meta-line">May 2022 &nbsp;·&nbsp; Code S, A, B &amp; 2v2</p>
                </div>
            </div>

            <div class="champion-row">
                🏆 <a href="view_player.php?name=Neutrophil">Neutrophil</a> (Code S) &nbsp;·&nbsp; <a href="view_player.php?name=KriMiNal">Kriminal</a> (Code A) &nbsp;·&nbsp; <a href="view_player.php?name=ChienPwn">ChienPwn</a> (Code B)
            </div>

            <p class="season-desc">Season 5 solidified 2v2 as a core pillar of FSL, with Bo3 Round-Robin, Bo5 playoffs, and Bo7 finals. Matches ran Wednesdays and Fridays at 7 PM Eastern.</p>

            <div class="results">
                <strong>Code S:</strong> <a href="view_player.php?name=Neutrophil">Neutrophil</a> def. <a href="view_player.php?name=Instability">Instability</a> 4–1 &nbsp;·&nbsp; <strong>Code A:</strong> <a href="view_player.php?name=KriMiNal">Kriminal</a> def. <a href="view_player.php?name=TheArchaic">TheArchaic</a> 3–2<br>
                <strong>Code B:</strong> <a href="view_player.php?name=ChienPwn">ChienPwn</a> def. <a href="view_player.php?name=Fenrir">Fenrir</a> 3–2 &nbsp;·&nbsp; <strong>2v2:</strong> <a href="view_player.php?name=Neutrophil">Neutrophil</a>/<a href="view_player.php?name=DarkMenace">DarkMenace</a> def. <a href="view_player.php?name=Regret">Regret</a>/<a href="view_player.php?name=TheArchaic">TheArchaic</a> 3–0
            </div>

            <button class="toggle-btn" onclick="togglePanel(this, 'vods-s5')">&#9658; Show VODs</button>
            <div id="vods-s5" class="collapsible-panel" style="display:none;">
                <ul>
                    <li><a href="https://www.youtube.com/watch?v=gH5R-jtbaAw&list=PLuxOPc104Mmma658bOODkIBNvFDF3Dwyy&pp=gAQB" target="_blank">Full Season 5 Playlist</a></li>
                </ul>
            </div>
        </div>

        <!-- ── Season 4 ── -->
        <div class="season" id="4">
            <div class="season-header">
                <div class="season-num">4</div>
                <div class="season-title-group">
                    <h2>2v2 Begins</h2>
                    <p class="season-meta-line">~2021 &nbsp;·&nbsp; Code S, A, B &amp; 2v2</p>
                </div>
            </div>

            <div class="champion-row">
                🏆 <a href="view_player.php?name=Neutrophil">Neutrophil</a> (Code S) &nbsp;·&nbsp; <a href="view_player.php?name=Domistrength">Domistrength</a> (Code A) &nbsp;·&nbsp; <a href="view_player.php?name=StuBlue">StuBlue</a> (Code B)
            </div>

            <p class="season-desc">Season 4 introduced 2v2 for the first time, expanding FSL's vision. Matches at 7 PM Eastern featured Bo3 Round-Robin, Bo5 playoffs, and Bo7 finals.</p>

            <div class="results">
                <strong>Code S:</strong> <a href="view_player.php?name=Neutrophil">Neutrophil</a> def. <a href="view_player.php?name=DarkMenace">DarkMenace</a> 4–3 &nbsp;·&nbsp; <strong>Code A:</strong> <a href="view_player.php?name=Domistrength">Domistrength</a> def. <a href="view_player.php?name=TheArchaic">TheArchaic</a> 3–1<br>
                <strong>Code B:</strong> <a href="view_player.php?name=StuBlue">StuBlue</a> def. <a href="view_player.php?name=Charizma">Charizma</a> 3–1 &nbsp;·&nbsp; <strong>2v2:</strong> <a href="view_player.php?name=Regret">Regret</a>/<a href="view_player.php?name=TheArchaic">TheArchaic</a> def. <a href="view_player.php?name=DarkMenace">DarkMenace</a>/<a href="view_player.php?name=LittleReaper">LittleReaper</a> 3–1
            </div>

            <button class="toggle-btn" onclick="togglePanel(this, 'vods-s4')">&#9658; Show VODs</button>
            <div id="vods-s4" class="collapsible-panel" style="display:none;">
                <ul>
                    <li><a href="https://www.youtube.com/watch?v=GD_uSZkMCNI&list=PLuxOPc104Mmk0i8hYV8ghEZjiK7Uxq85u" target="_blank">Full Season 4 Playlist</a></li>
                </ul>
            </div>
        </div>

        <!-- ── Season 3 ── -->
        <div class="season" id="3">
            <div class="season-header">
                <div class="season-num">3</div>
                <div class="season-title-group">
                    <h2>Code B Arrives</h2>
                    <p class="season-meta-line">~2021 &nbsp;·&nbsp; Code S, A &amp; B</p>
                </div>
            </div>

            <div class="champion-row">
                🏆 <a href="view_player.php?name=VeryCool">VeryCool</a> (Code S) &nbsp;·&nbsp; <a href="view_player.php?name=Spaghettio">Spaghettio</a> (Code A) &nbsp;·&nbsp; <a href="view_player.php?name=PanicSwitched">PanicSwitched</a> (Code B)
            </div>

            <p class="season-desc">Season 3 added Code B, expanding the Friends StarCraft League to three tiers for the first time. Wednesday/Friday matches at 8–9 PM Eastern with Bo3 groups and Bo7 finals.</p>

            <div class="results">
                <strong>Code S:</strong> <a href="view_player.php?name=VeryCool">VeryCool</a> def. <a href="view_player.php?name=Neutrophil">Neutrophil</a> 4–1 &nbsp;·&nbsp; <strong>Code A:</strong> <a href="view_player.php?name=Spaghettio">Spaghettio</a> def. <a href="view_player.php?name=KriMiNal">Kriminal</a> 3–1<br>
                <strong>Code B:</strong> <a href="view_player.php?name=PanicSwitched">PanicSwitched</a> def. <a href="view_player.php?name=LittleReaper">LittleReaper</a> 3–1
            </div>

            <button class="toggle-btn" onclick="togglePanel(this, 'vods-s3')">&#9658; Show VODs</button>
            <div id="vods-s3" class="collapsible-panel" style="display:none;">
                <ul>
                    <li><a href="https://www.youtube.com/watch?v=ncjmL9UchE0&list=PLuxOPc104MmnndlUvwP5g6Ql_9rP_c1tO&pp=gAQB" target="_blank">Full Season 3 Playlist</a></li>
                </ul>
            </div>
        </div>

        <!-- ── Season 2 ── -->
        <div class="season" id="2">
            <div class="season-header">
                <div class="season-num">2</div>
                <div class="season-title-group">
                    <h2>Code A Joins</h2>
                    <p class="season-meta-line">Aug 2020 &nbsp;·&nbsp; Code S &amp; A</p>
                </div>
            </div>

            <div class="champion-row">
                🏆 <a href="view_player.php?name=Sef">Sef</a> (Code S) &nbsp;·&nbsp; <a href="view_player.php?name=RegreT">RegreT</a> (Code A)
            </div>

            <p class="season-desc">Season 2 rebranded to "Friends StarCraft League" under PSISTORM Gaming and added Code A alongside Code S. A $150 prize pool drew increased competition, with matches at 8–9 PM Eastern.</p>

            <div class="results">
                <strong>Code S:</strong> <a href="view_player.php?name=Sef">Sef</a> def. <a href="view_player.php?name=Neutrophil">Neutrophil</a> 4–3 &nbsp;·&nbsp; <strong>Code A:</strong> <a href="view_player.php?name=RegreT">RegreT</a> def. <a href="view_player.php?name=Fluffy">Fluffy</a> 4–0
            </div>

            <button class="toggle-btn" onclick="togglePanel(this, 'vods-s2')">&#9658; Show VODs</button>
            <div id="vods-s2" class="collapsible-panel" style="display:none;">
                <ul>
                    <li><a href="https://www.youtube.com/playlist?list=PLvm8uqzqDzXVHZ_Q3cs5MwuRYREqJZEZX" target="_blank">Full Season 2 Playlist</a></li>
                </ul>
            </div>
        </div>

        <!-- ── Season 1 ── -->
        <div class="season" id="1">
            <div class="season-header">
                <div class="season-num">1</div>
                <div class="season-title-group">
                    <h2>The Beginning</h2>
                    <p class="season-meta-line">Pre-Aug 2020 &nbsp;·&nbsp; Code S</p>
                </div>
            </div>

            <div class="champion-row">
                🏆 <a href="view_player.php?name=Neutrophil">Neutrophil</a> (Code S)
            </div>

            <p class="season-desc">The inaugural FSL — then called "Family StarCraft League" — launched with Code S as the sole division. A tight-knit community of players from age 6 to nearly 50 competed in Bo3 Round-Robin matches.</p>

            <div class="results">
                <strong>Code S:</strong> <a href="view_player.php?name=Neutrophil">Neutrophil</a> def. <a href="view_player.php?name=SirMalagant">SirMalagant</a> 4–0
            </div>

            <button class="toggle-btn" onclick="togglePanel(this, 'vods-s1')">&#9658; Show VODs</button>
            <div id="vods-s1" class="collapsible-panel" style="display:none;">
                <ul>
                    <li><a href="https://www.youtube.com/watch?v=fBjMjaulVQM&list=PLvm8uqzqDzXV-3s3FK46icZv6668QyGxR" target="_blank">Full Season 1 Playlist</a></li>
                </ul>
            </div>
        </div>

    </div><!-- /container -->

    <script>
        function togglePanel(btn, id) {
            var el = document.getElementById(id);
            var hidden = el.style.display === 'none' || el.style.display === '';
            if (hidden) {
                el.style.display = 'block';
                btn.innerHTML = btn.innerHTML.replace('&#9658;', '&#9660;').replace('Show', 'Hide');
            } else {
                el.style.display = 'none';
                btn.innerHTML = btn.innerHTML.replace('&#9660;', '&#9658;').replace('Hide', 'Show');
            }
        }
    </script>

</body>
</html>

<?php
// Include footer
include_once 'includes/footer.php';
?>
