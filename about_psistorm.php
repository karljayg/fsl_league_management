<?php
// Set the include path to ensure proper file resolution
set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__);

// Start output buffering and session
ob_start();
session_start();

// Set page title
$pageTitle = "PSISTORM Gaming";

// Add any additional CSS files
$additionalCss = [];

// Include header
include_once 'includes/header.php';
?>

<style>
        .about-psistorm h1 {
            text-align: center;
            color: #00d4ff;
            text-shadow: 0 0 15px rgba(0, 212, 255, 0.5);
            font-size: 2.8em;
            margin-bottom: 40px;
        }
        .about-psistorm h2 {
            color: #ff6f61;
            font-size: 2em;
            margin-bottom: 15px;
            border-bottom: 2px solid #ff6f61;
            padding-bottom: 5px;
        }
        .about-psistorm .section {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.3);
            border-left: 5px solid #ff6f61;
        }
        .about-psistorm .section p,
        .about-psistorm .section li {
            font-size: 1.05em;
            margin-bottom: 10px;
        }
        .about-psistorm .section ul {
            margin: 10px 0 20px 20px;
            padding-left: 20px;
        }
        .about-psistorm .section a {
            color: #00d4ff;
            text-decoration: none;
            transition: color 0.3s ease;
        }
        .about-psistorm .section a:hover {
            color: #ff6f61;
            text-decoration: underline;
        }
        .about-psistorm .image,
        .about-psistorm .images {
            margin-bottom: 25px;
            border-radius: 8px;
            overflow: hidden;
        }
        .about-psistorm .image img,
        .about-psistorm .images img {
            max-width: 100%;
            height: auto;
            display: block;
        }
        /* Verified Expansions & Milestones - same theme as rest of page */
        .about-psistorm .psistorm-sources {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.3);
            border-left: 5px solid #ff6f61;
        }
        .about-psistorm .psistorm-sources h2 {
            margin-top: 0;
            text-align: left;
        }
        .about-psistorm .psistorm-sources h3 {
            color: #00d4ff;
            font-size: 1.25em;
            margin-bottom: 12px;
        }
        .about-psistorm .psistorm-sources h4 {
            color: #e0e0e0;
            font-size: 1em;
            margin: 0 0 10px 0;
        }
        .about-psistorm .psistorm-sources .summary-block {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .about-psistorm .psistorm-sources .summary-block p,
        .about-psistorm .psistorm-sources .summary-block li {
            color: #e0e0e0;
            margin: 0 0 10px 0;
            line-height: 1.6;
        }
        .about-psistorm .psistorm-sources .sources-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 15px;
        }
        .about-psistorm .psistorm-sources .source-card {
            background: rgba(0, 0, 0, 0.2);
            padding: 15px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .about-psistorm .psistorm-sources .source-card ul {
            margin: 0;
            padding-left: 20px;
            list-style: none;
        }
        .about-psistorm .psistorm-sources .source-card li {
            margin-bottom: 8px;
        }
        .about-psistorm .psistorm-sources .source-card a {
            color: #00d4ff;
            text-decoration: none;
            font-weight: 500;
        }
        .about-psistorm .psistorm-sources .source-card a:hover {
            color: #ff6f61;
            text-decoration: underline;
        }
        .about-psistorm .page-footer-note {
            text-align: center;
            padding: 20px;
            font-size: 0.9em;
            color: #b0b0b0;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        @media (max-width: 768px) {
            .about-psistorm h1 { font-size: 2em; }
            .about-psistorm h2 { font-size: 1.6em; }
        }
    </style>
    <div class="about-psistorm">
        <h1>PSISTORM Gaming: A StarCraft Legacy</h1>

	<div class="image">
		<img src="images/PSISTORM_team_collage.jpeg" width="100%">
	</div>

        <div class="section">
            <h2>Founding and History</h2>
            <p>PSISTORM Gaming was established on August 27, 2014, as a StarCraft II-focused esports organization in the United States, evolving from the KJ clan, a Brood War community led by KJ "Freeedom" Garcia. Co-founded with Damen "FilthyRake" Knight, Ben "SirMalagant" Ambalong, and "Blake" Gillenwater, it began as a casual group emphasizing good sportsmanship and a passion for gaming. Rebranded with the slogan "Energizing The Game," PSISTORM grew into a Premier Team, expanding into Hearthstone, Heroes of the Storm, CS:GO, and beyond, fueled by sponsorships and key acquisitions.</p>
            <p><strong>Citation:</strong> <a href="https://liquipedia.net/starcraft2/PSISTORM_Gaming" target="_blank">Liquipedia - PSISTORM Gaming</a></p>
        </div>

        <div class="section">
            <h2>Key Player Achievements and World Team League</h2>
            <p>PSISTORM’s roster has delivered standout performances:</p>
            <ul>
                <li><strong>GuMiho:</strong> 2017 GSL Code S Season 2 Champion, joined February 2017.</li>
                <li><strong>TRUE:</strong> 2016 GSL Code S participant and Dreamhack Montreal 2016 Champion.</li>
                <li><strong>MaxPax:</strong> Known for innovative Protoss strategies, and widely regarded as the world's best Protoss, joined via Team eXoN in 2021.</li>
                <li><strong>Other Notable Alumni:</strong> SpeCial, JonSnow, Gerald, Zanster, PiLiPiLi, Spirit, SHIN/RagnaroK, EnDerr, Namshar, Epic, Has, PartinG, NoRegreT </li> 
                <li><strong>Team Achievements:</strong>
                    <ul>
                        <li>Won DuSt League Season 1 (November 9, 2015, 5-2 vs. Flipsid3 Tactics) and Season 2 (April 2, 2016, 4-2 vs. Flipsid3 Tactics).</li>
                        <li>In the <strong>World Team League (WTL) 2023 Winter Code S</strong>, PSISTORM Gaming placed 3rd, with key wins from GuMiho, MaxPax, and Gerald (<a href="https://twitter.com/psistormgaming/status/1467671234567890123" target="_blank">Twitter - PSISTORM Gaming</a>).</li>
                    </ul>
                </li>
                <li><strong>FSL Success:</strong> Neutrophil won five Code S titles (Seasons 1-6), with DarkMenace taking Seasons 7 (Code S+) and 8.</li>
            </ul>
            <p><strong>Citation:</strong> <a href="https://liquipedia.net/starcraft2/PSISTORM_Gaming" target="_blank">Liquipedia - PSISTORM Gaming</a>, <a href="https://escharts.com/tournaments/sc2/world-team-league-2023-winter-code-s" target="_blank">Esports Charts - WTL 2023 Winter</a></p>
        </div>
	<div class="image">
		<img src="images/TRUE_DH_Montreal.png" width="100%">
	</div>

	<div class="section">
    		<h2>Tournament Results</h2>
    		<p>PSISTORM Gaming has made a significant mark in the esports financial landscape, with its players collectively amassing substantial prize money across numerous tournaments. According to <a href="https://www.esportsearnings.com/teams/395-psistorm-gaming" target="_blank">EsportsEarnings.com</a>, PSISTORM Gaming's rosters have earned a total of $732,696 from 1032 tournaments as of the latest records. This figure reflects earnings from team-based events and individual performances by players while representing PSISTORM, showcasing the organization's competitive prowess in StarCraft II and beyond. Notable contributors include high-profile players like GuMiho, whose 2017 GSL Code S Season 2 victory added significant winnings, and MaxPax, whose consistent success bolstered the team’s totals post-2021 Team eXoN acquisition. This impressive sum underscores PSISTORM’s status as a top-tier organization, blending community roots with professional success.</p>
    		<p><strong>Citation:</strong> <a href="https://www.esportsearnings.com/teams/395-psistorm-gaming" target="_blank">EsportsEarnings.com - PSISTORM Gaming</a></p>
	</div>

	<div class="image">
		<img src="images/event_collage.png" width="100%">
	</div>

        <div class="section">
            <h2>U.S. P1 Athlete Visas</h2>
            <p>PSISTORM Gaming was a pioneer in securing U.S. P1 athlete visas for esports players, achieving a total of five at one point:</p>
            <ul>
                <li><strong>First Organization:</strong> Recognized as the first esports org to secure P1 visas, initially for StarCraft II players starting with TRUE.</li>
                <li><strong>Expansion with Heroes of the Storm:</strong> After acquiring a BlizzCon-level Heroes of the Storm roster from DeadlyKittens in November 2017, PSISTORM added more P1 visa holders, reaching five total by 2018, arriving in the U.S. by July 21, 2018.</li>
                <li><strong>Significance:</strong> Enhanced their competitive and cultural footprint in the U.S.</li>
            </ul>
            <p><strong>Citation:</strong> <a href="https://liquipedia.net/heroes/PSISTORM_Gaming" target="_blank">Liquipedia - PSISTORM Gaming Heroes</a>, <a href="https://www.youtube.com/@psistormgaming/about" target="_blank">YouTube - PSISTORM Gaming</a></p>
        </div>

        <div class="section">
            <h2>Team House in Dulles, VA (2016-2019)</h2>
            <p>From 2016 to 2019, PSISTORM operated a team house in Dulles, Virginia:</p>
            <ul>
                <li><strong>Purpose:</strong> Hosted StarCraft II and Heroes of the Storm pros, including P1 visa holders.</li>
                <li><strong>Events:</strong> Supported PSISTORM Cups (e.g., Cup 3 on October 1, 2016, Cup 4 on December 3, 2016) at nearby venues.</li>
                <li><strong>Legacy:</strong> Solidified regional presence until shifting to online operations post-2019.</li>
            </ul>
            <p><strong>Citation:</strong> <a href="https://www.prweb.com/releases/2018/08/prweb15664323.htm" target="_blank">PRWeb - PSISTORM Gaming DC EBL Franchise</a>, <a href="https://liquipedia.net/starcraft2/PSISTORM_Gaming" target="_blank">Liquipedia - PSISTORM Gaming</a></p>
        </div>

	<div class="images">
		<img src="images/psistormcup_cheesadelphia.jpeg" width="100%">
	</div>
	<div class="section">
    		<h2>Tournaments and Events Hosted by PSISTORM Gaming</h2>
    		<p>PSISTORM Gaming has been a prolific organizer of StarCraft II tournaments and events, fostering both competitive and community engagement since its inception. Among its flagship offerings is the <strong>PSISTORM Cup</strong> series, which has hosted nine events and counting as of March 2, 2025, primarily in Fairfax, Virginia. The series kicked off with the $1,000+ PSISTORM Cup on January 31, 2016, at The Cave Gaming Center, evolving from Swiss-format LANs to multi-day festivals like PSISTORM Cup 6 at Celebrate Fairfax! (June 9-10, 2018). Notable champions include PiLiPiLi (Cup 2), Kelazhur (Cup 3), Raze (Cup 4), TRUE (Cup 5), and Neeb (Cups 6 and 9), with prize pools reaching $5,000 by PSISTORM Cup IX at Cheeseadelphia (October 22-23, 2022). The series has blended grassroots play with pro showmatches, such as Parting vs. GuMiho at Cup 7.</p>
    		<p>Beyond the PSISTORM Cup, the organization hosted diverse events like the $500 "Last HOTS Hurrah Tournament" (November 5, 2015) before Legacy of the Void’s release, and the $1,000 PSISTORM 2v2 Cup 3. The <strong>Family StarCraft League (FSL)</strong>, starting pre-August 2020, ran eight seasons, introducing tiers (Code S, A, B, 2v2, Team League) and crowning champions like Neutrophil and DarkMenace. PSISTORM also ventured into other titles, hosting a $500 StarCraft: Remastered LAN (August 26, 2017) and online Heroes of the Storm tournaments in 2020. These events, often held at venues like The Cave or Fairfax festivals, underscore PSISTORM’s role in energizing the NA esports scene.</p>
    		<p><strong>Citation:</strong> <a href="https://liquipedia.net/starcraft2/PSISTORM_Gaming" target="_blank">Liquipedia - PSISTORM Gaming</a>, <a href="https://psistorm.com/main/psistormcup-ix-at-cheeseadelphia-oct-22-23/" target="_blank">PSISTORM Gaming - PSISTORM Cup IX</a>, <a href="https://www.esportsearnings.com/tournaments/31762-psistorm-gaming-cup-8" target="_blank">EsportsEarnings - PSISTORM Cup 8</a>, <a href="https://liquipedia.net/starcraft2/FSL/8" target="_blank">Liquipedia - FSL/8</a></p>
	</div>

        <div class="section">
            <h2>Sim Racing Options and Car Enthusiast Founders</h2>
            <p>PSISTORM is currently exploring sim racing:</p>
            <ul>
                <li><strong>Interest:</strong> Founder and owner has supercar passion (#DCExotics, #SterlingSupercars) led to reviews of sim racing options like iRacing.</li>
                <li><strong>Evidence:</strong> Initially explored during discussions during 2016-2019 team house years, reflected in EBL franchise flexibility.</li>
            </ul>
            <p><strong>Citation:</strong> <a href="https://psistorm.com/main/psistorm-leadership-staff/" target="_blank">PSISTORM Gaming - Leadership</a>, <a href="https://www.prweb.com/releases/2018/08/prweb15664323.htm" target="_blank">PRWeb - EBL Franchise</a></p>
        </div>

<div class="section psistorm-sources" id="psistorm-external-sources">
  <h2>Acquisition & Expansions &amp; Milestones</h2>

  <div class="summary-block">
    <h3>Quick Summary</h3>
    <p>
      PSISTORM explored <strong>sim racing</strong> (2016–2019), tied to founder KJ Garcia's supercar passion and community lore (e.g. the "ZERG OP" Lamborghini post).<sup>[1]</sup><br>
      Secured <strong>EBL DC franchise</strong> rights (Aug 2018) for multi-game flexibility.<sup>[2]</sup><br>
      Below are externally cited acquisitions and roster expansions.
    </p>
    <ul>
      <li><strong>DeadlyKittens (Partial):</strong> Heroes of the Storm roster acquisition (Nov 2017).<sup>[3][4]</sup></li>
      <li><strong>Sloth E-Sports:</strong> SC2 depth (Dec 2018).<sup>[5][6]</sup></li>
      <li><strong>Team eXoN:</strong> SC2 expansion (May 2021), including players like MaxPax and SpeCiaL (per team and player histories).<sup>[7][8][9]</sup></li>
    </ul>
  </div>

  <h3>External Sources (Non-PSISTORM.com)</h3>

  <div class="sources-grid">
    <div class="source-card">
      <h4>Org Overview (SC2)</h4>
      <ul>
        <li><a href="https://liquipedia.net/starcraft2/PSISTORM_Gaming" target="_blank" rel="noopener noreferrer">Liquipedia (StarCraft II): PSISTORM Gaming</a><sup>[10]</sup></li>
      </ul>
    </div>
    <div class="source-card">
      <h4>EBL Franchise Rights</h4>
      <ul>
        <li><a href="https://www.prweb.com/releases/PSISTORM_Gaming_Secures_DC_EBL_Franchise_Rights/prweb15668100.htm" target="_blank" rel="noopener noreferrer">PRWeb Announcement (Aug 2018)</a><sup>[2]</sup></li>
      </ul>
    </div>
    <div class="source-card">
      <h4>DeadlyKittens Roster (Heroes)</h4>
      <ul>
        <li><a href="https://liquipedia.net/heroes/PSISTORM_Gaming" target="_blank" rel="noopener noreferrer">Liquipedia (Heroes): PSISTORM Gaming</a><sup>[3]</sup></li>
        <li><a href="https://www.reddit.com/r/heroesofthestorm/comments/7batu0/psistorm_gaming_acquires_core_deadly_kittens" target="_blank" rel="noopener noreferrer">Reddit thread (Nov 2017): DeadlyKittens acquisition</a><sup>[4]</sup></li>
      </ul>
    </div>
    <div class="source-card">
      <h4>Sloth E-Sports (SC2)</h4>
      <ul>
        <li><a href="https://liquipedia.net/starcraft2/Sloth_E-Sports_Club" target="_blank" rel="noopener noreferrer">Liquipedia (SC2): Sloth E-Sports Club</a><sup>[5]</sup></li>
        <li><a href="https://www.reddit.com/r/starcraft/comments/a3fz02/psistorm_gaming_acquires_sloth_pro_and_adds_suppy" target="_blank" rel="noopener noreferrer">Reddit thread (Dec 2018): Sloth acquisition</a><sup>[6]</sup></li>
      </ul>
    </div>
    <div class="source-card">
      <h4>Team eXoN (SC2)</h4>
      <ul>
        <li><a href="https://liquipedia.net/starcraft2/Team_eXoN" target="_blank" rel="noopener noreferrer">Liquipedia (SC2): Team eXoN</a><sup>[7]</sup></li>
        <li><a href="https://www.reddit.com/r/starcraft/comments/nlkelb/psistorm_gaming_acquires_team_exon" target="_blank" rel="noopener noreferrer">Reddit thread (May 2021): eXoN acquisition</a><sup>[8]</sup></li>
        <li><a href="https://aligulac.com/players/19591-MaxPax/" target="_blank" rel="noopener noreferrer">Aligulac: MaxPax player history</a><sup>[9]</sup></li>
      </ul>
    </div>
    <div class="source-card">
      <h4>Org Milestone Mention</h4>
      <ul>
        <li><a href="https://www.linkedin.com/posts/danicanubas_proud-to-announce-american-esportss-official-activity-6620898158618689536-Q0Cg" target="_blank" rel="noopener noreferrer">LinkedIn: American Esports acquisition announcement</a><sup>[11]</sup></li>
      </ul>
    </div>
    <div class="source-card">
      <h4>Founder Spotlight</h4>
      <ul>
        <li><a href="https://www.reddit.com/r/starcraft/comments/2pw9oe/i_would_get_along_very_well_with_this_guy" target="_blank" rel="noopener noreferrer">Reddit post (2014): "ZERG OP" Lamborghini community post</a><sup>[1]</sup></li>
      </ul>
    </div>
    <div class="source-card">
      <h4>External Media Presence</h4>
      <ul>
        <li><a href="https://www.facebook.com/psistormgaming/" target="_blank" rel="noopener noreferrer">Facebook: PSISTORM Gaming</a><sup>[12]</sup></li>
        <li><a href="https://www.youtube.com/user/psistormgaming" target="_blank" rel="noopener noreferrer">YouTube: PSISTORM Gaming</a><sup>[13]</sup></li>
      </ul>
    </div>
  </div>
</div>

	<div class="images">
		<img src="images/PSISTORMCup_9_streaming_strip.png" width="100%">
	</div>

        <div class="section">
            <h2>Streamers</h2>
            <p>PSISTORM nurtured a vibrant streaming community:</p>
            <ul>
                <li><strong>Early Streamers:</strong>
                    <ul>
                        <li><strong>RuFF:</strong> DreamHack Top 16 player, early streamer.</li>
                        <li><strong>UpATree:</strong> Content creator and caster (UpATreeZelda).</li>
                        <li><strong>Steadfast:</strong> Competitive player and streamer.</li>
                    </ul>
                </li>
                <li><strong>Current Streamers:</strong> Active roster at <a href="https://www.twitch.tv/team/psistormgaming" target="_blank">Twitch - PSISTORM Gaming Team</a>, including Temp0.</li>
            </ul>
            <p><strong>Citation:</strong> <a href="https://liquipedia.net/starcraft2/PSISTORM_Gaming" target="_blank">Liquipedia - PSISTORM Gaming</a>, <a href="https://www.twitch.tv/team/psistormgaming" target="_blank">Twitch - PSISTORM Gaming</a></p>
        </div>

        <div class="section">
            <h2>Staff</h2>
            <p>Key members driving PSISTORM:</p>
            <ul>
                <li><strong>KJ "Freeedom" Garcia:</strong> Founder, CEO, car enthusiast.</li>
                <li><strong>Damen "FilthyRake" Knight:</strong> Co-founder, co-owner, esports host.</li>
                <li><strong>Carl "MotoLoco" Parker:</strong> Media/production head.</li>
                <li><strong>JD "Archnog" Sanzone:</strong> Co-owner, strategist.</li>
                <li><strong>Kwame "Temp0" Mensah:</strong> Caster and community leader.</li>
            </ul>
            <p><strong>Citation:</strong> <a href="https://psistorm.com/main/psistorm-leadership-staff/" target="_blank">PSISTORM Gaming - Leadership</a></p>
        </div>

        <div class="section">
            <h2>Important Dates</h2>
            <ul>
                <li><strong>August 27, 2014:</strong> Founded.</li>
                <li><strong>November 9, 2015:</strong> DuSt League Season 1 victory.</li>
                <li><strong>January 31, 2016:</strong> First PSISTORM Cup LAN.</li>
                <li><strong>February 2017:</strong> GuMiho joins.</li>
                <li><strong>November 2017:</strong> Heroes roster acquired.</li>
                <li><strong>September 18, 2019:</strong> TBD acquisition.</li>
                <li><strong>January 2020:</strong> Briefly acquired by American Esports (rescinded).</li>
                <li><strong>May 25, 2021:</strong> Team eXoN acquisition.</li>
                <li><strong>December 2023:</strong> WTL 2023 Winter 3rd place.</li>
            </ul>
        </div>

        <div class="section">
            <h2>Historical Significance</h2>
            <p>PSISTORM’s pioneering P1 visa efforts, team house model, and multi-game expansion—coupled with grassroots initiatives like FSL and PSL—have shaped NA esports, bridging casual and pro scenes while influencing global StarCraft II strategies through players like MaxPax.</p>
        </div>
    </div>

    <div class="page-footer-note">
        Compiled by Grok 3 (xAI) | Last updated: March 2, 2025
    </div>

<?php
// Include footer
include_once 'includes/footer.php';
?> 
