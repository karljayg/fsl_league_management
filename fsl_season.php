<?php
// Include header
include_once 'includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FSL Seasons 1-10: Champions of the Cosmos</title>
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
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
        }
        h1 {
            text-align: center;
            color: #00d4ff;
            text-shadow: 0 0 15px #00d4ff;
            font-size: 2.8em;
            margin-bottom: 40px;
        }
        .season {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.4);
            transition: transform 0.3s ease;
            border-left: 5px solid #ff6f61;
        }
        .season:hover {
            transform: scale(1.02);
        }
        h2 {
            color: #ff6f61;
            font-size: 2em;
            margin-bottom: 15px;
        }
        p {
            font-size: 1.1em;
            margin: 10px 0;
        }
        .results {
            background: rgba(0, 0, 0, 0.3);
            padding: 15px;
            border-radius: 5px;
            margin-top: 15px;
            font-family: 'Courier New', monospace;
            color: #00ff9d;
            font-size: 0.95em;
        }
        a {
            color: #00d4ff;
            text-decoration: none;
            transition: color 0.3s ease;
        }
        a:hover {
            color: #ff6f61;
            text-decoration: underline;
        }
        ul {
            margin: 10px 0 20px 20px;
            padding-left: 20px;
        }
        li {
            margin-bottom: 8px;
            font-size: 1.1em;
        }
        footer {
            text-align: center;
            padding: 20px;
            font-size: 0.9em;
            color: #b0b0b0;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        @media (max-width: 768px) {
            h1 { font-size: 2em; margin-bottom: 30px; }
            h2 { font-size: 1.6em; }
            p { font-size: 1em; }
            .container { padding: 15px; }
            .season { padding: 15px; margin-bottom: 20px; }
            .results { font-size: 0.85em; padding: 12px; overflow-x: auto; }
        }
        
        @media (max-width: 480px) {
            h1 { font-size: 1.8em; text-shadow: 0 0 10px #00d4ff; }
            h2 { font-size: 1.4em; }
            .season { 
                padding: 12px; 
                margin-bottom: 18px;
                border-left: 3px solid #ff6f61;
            }
            .season:hover { transform: none; } /* Disable hover effect on mobile */
            .results { 
                padding: 10px;
                font-size: 0.8em;
                white-space: nowrap;
                overflow-x: scroll;
            }
            .container { padding: 10px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>FSL Seasons 1-10</h1>

        <div style="text-align: center; margin-bottom: 40px;">
            <iframe width="854" height="480" src="https://www.youtube.com/embed/vt04Xbq57Dk?mute=1&vq=medium&autoplay=1&origin=http://psistorm.com" frameborder="0" allow="autoplay; encrypted-media" allowfullscreen></iframe>
        </div>

        <div class="season">
            <h2>Season 10 - New Beginnings (2026)</h2>
            <p><strong>Draft Date: January 31st, 2026</strong></p>
            <p>Season 10 brings exciting changes to FSL! We're introducing shorter seasons with fresh captains and a complete redraft. The new season features updated rules including fair MMR-based requirements for matches, ensuring balanced and competitive gameplay for all participants.</p>
            <p>If you're new to FSL and want to join, <a href="apply.php">apply here</a> to be considered for the Season 10 draft!</p>
            <p><strong>What's New:</strong></p>
            <ul>
                <li>Shorter new seasons for more frequent competition</li>
                <li>New captains leading fresh teams</li>
                <li>Complete redraft with fresh lineups</li>
                <li>Updated rules with fair MMR-based match requirements</li>
            </ul>
        </div>

        <div class="season">
            <h2>Season 9 - Team League Draft (2025)</h2>
            <p>Season 9 launched with an exciting team draft format featuring five competitive teams. The draft system allowed team captains to strategically select their rosters across multiple rounds, creating balanced and competitive lineups. Each team consisted of a captain (protected) and drafted players, with additional protected slots for key players.</p>
            
            <div class="results">
                <strong>Team Rosters:</strong><br>
                <strong>CheesyNachos (Captain: Nachoz):</strong> SgtABC, AntoineQ, Fenrir, Note, Instability<br>
                <strong>Rage's Raiders (Captain: RevenantRage):</strong> NukLeo, LanixMagi, Adastra, WindShadow, ShadeHealer<br>
                <strong>Infinite Cyclists (Captain: HyperTurtle):</strong> GreatArchon, ArduousGem, SCVSir, Staged<br>
                <strong>Angry Space Hares (Captain: Warbunnies):</strong> Chat-omic, Pebble, Sopuli, HurtnTime<br>
                <strong>Pulled The Boys (Captain: Neutrophil):</strong> Dpoo, ChienPwn, MedicJr, MvonLipwig, MonkeyShaman<br><br>
                <strong>Protected Players:</strong> Freeedom, Sequovia, Harouz, Lighthood, DarkMenace<br>
                <strong>Additional Protected:</strong> SirMalagant, Nuke, Greeempire, Vales, LittleReaper
            </div>
            
            <p>The season featured a comprehensive team league format with strategic drafting, delivering intense competition as teams battled for supremacy.</p>
            <p><strong>VODs:</strong></p>
            <ul>
                <li><a href="https://www.youtube.com/watch?v=vt04Xbq57Dk&list=PLuxOPc104MmmySmrbE813nXWuamZpecw5&index=34&pp=gAQBiAQBsAgC" target="_blank">9 Seasons of FSL Video</a></li>
                <li><a href="https://www.youtube.com/watch?v=kqnD8K0Tfnw&list=PLuxOPc104MmmySmrbE813nXWuamZpecw5&index=35&pp=gAQBiAQBsAgC" target="_blank">Team League Highlights</a></li>
                <li><a href="https://www.youtube.com/watch?v=7pRh7ohhXrk&list=PLuxOPc104MmmySmrbE813nXWuamZpecw5&index=37&pp=gAQBiAQBsAgC" target="_blank">Individual Championships - Code B</a></li>
                <li><a href="https://www.youtube.com/watch?v=7pRh7ohhXrk&list=PLuxOPc104MmmySmrbE813nXWuamZpecw5&index=37&pp=gAQBiAQBsAgC" target="_blank">Individual Championships - Code A & S</a></li>
            </ul>
        </div>

        <div class="season">
            <h2>Season 8 - Team League Expansion (October 14, 2024)</h2>
            <p>Launched on October 14, 2024, FSL Season 8, organized by PSISTORM Gaming, introduced the Team League alongside Code S, A, B, and 2v2 divisions. Five teams competed in a Round-Robin with Bo9 matches, with the top 4 advancing to playoffs (Bo5, Bo9 final). @PSISTORMGaming tweeted the kickoff excitement, spotlighting team rivalries.</p>
            <div class="results">
                <strong>Team League:</strong> PulledTheBoys def. AngrySpaceHares 5-3<br>
                <strong>Code S:</strong> DarkMenace def. Neutrophil 4-0<br>
                <strong>Code A:</strong> LittleReaper def. Regret 3-2<br>
                <strong>Code B:</strong> ChienPwn def. SgtABC 2-0<br>
                <strong>2v2:</strong> Vales/Instability def. Neutrophil/Dpoo 2-0
            </div>
            <p><a href="https://www.youtube.com/watch?v=2MTRr7qjlyU&list=PLuxOPc104MmkHE2lBVyd_FE0AU_euJEfe" target="_new">VOD</a></p>
        </div>

        <div class="season">
            <h2>Season 7 - Code S+ Debut (August 20, 2024)</h2>
            <p>Season 7, with Code S+ starting August 20, 2024, and Code S on July 26, 2023, saw PSISTORM Gaming elevate the stakes with a new elite tier. Matches on maps like Babylon LE featured Round-Robin Bo3 and Bo5 playoffs. 2v2 split into 2v2+ and 2v2 tiers. @karljayg praised the intense competition on July 25, 2023.</p>
            <div class="results">
                <strong>Code S+:</strong> DarkMenace def. Harouz 4-1<br>
                <strong>Code S:</strong> LightHood def. HurtnTime 3-1<br>
                <strong>Code A:</strong> HyperTurtle def. Dpoo 3-1<br>
                <strong>Code B:</strong> RevenantRage def. Nachoz 3-1<br>
                <strong>2v2+:</strong> Vales/HurtnTime def. Dpoo/Neutrophil 3-2<br>
                <strong>2v2:</strong> Warbunnies/Greeempire def. Harouz/Lighthood 2-0
            </div>
            <p><a href="https://www.youtube.com/watch?v=Hs9IkzL6tvs&list=PLuxOPc104Mml72KGVnRPuFmgyudPaw7FT" target="_new">VOD</a></p>
        </div>

        <div class="season">
            <h2>Season 6 - Multi-Tier Mastery (August 20, 2024)</h2>
            <p>Season 6, with Code S on August 20, 2024, and Code B starting June 22, 2023, refined PSISTORM Gaming's tiered system (Code S, A, B, and 2v2). Round-Robin Bo3 matches led to Bo5 playoffs, showcasing a growing roster. @PSISTORMGaming tweeted about Code B's rise on June 20, 2023.</p>
            <div class="results">
                <strong>Code S:</strong> Neutrophil def. Vales 4-3<br>
                <strong>Code A:</strong> Grey def. Cyan 4-1<br>
                <strong>Code B:</strong> Fenrir def. ChienPwn 3-2<br>
                <strong>2v2:</strong> Vales/HurtnTime def. Instability/CaliberC 4-0
            </div>
            <p><a href="https://www.youtube.com/watch?v=ELJABHO41tM&list=PLuxOPc104MmmlzYYRxK49HNMSKfNKB9ee" target="_new">VOD</a></p>
        </div>

        <div class="season">
            <h2>Season 5 - 2v2 Dominance (May 27, 2022)</h2>
            <p>Starting May 27, 2022, Season 5 solidified 2v2 as a core component alongside Code S, A, and B. Hosted by PSISTORM Gaming, matches ran Wednesdays and Fridays at 7 PM US Eastern, with Bo3 Round-Robin, Bo5 playoffs, and Bo7 finals. @PSISTORMGaming hyped the duos on May 25, 2022.</p>
            <div class="results">
                <strong>Code S:</strong> Neutrophil def. Instability 4-1<br>
                <strong>Code A:</strong> Kriminal def. TheArchaic 3-2<br>
                <strong>Code B:</strong> ChienPwn def. Fenrir 3-2<br>
                <strong>2v2:</strong> Neutrophil/DarkMenace def. Regret/TheArchaic 3-0
            </div>
            <p><a href="https://www.youtube.com/watch?v=gH5R-jtbaAw&list=PLuxOPc104Mmma658bOODkIBNvFDF3Dwyy&pp=gAQB" target="_new">VOD</a></p>
        </div>

        <div class="season">
            <h2>Season 4 - 2v2 Begins (~2021)</h2>
            <p>Season 4 (likely 2021, correcting Liquipedia's August 20, 2024 typo) introduced 2v2, expanding PSISTORM Gaming's vision. Matches at 7 PM US Eastern featured Bo3 Round-Robin, Bo5 playoffs, and Bo7 finals, uniting players aged 6 to nearly 50.</p>
            <div class="results">
                <strong>Code S:</strong> Neutrophil def. DarkMenace 4-3<br>
                <strong>Code A:</strong> Domistrength def. TheArchaic 3-1<br>
                <strong>Code B:</strong> StuBlue def. Charizma 3-1<br>
                <strong>2v2:</strong> Regret/TheArchaic def. DarkMenace/LittleReaper 3-1
            </div>
            <p><a href="https://www.youtube.com/watch?v=GD_uSZkMCNI&list=PLuxOPc104Mmk0i8hYV8ghEZjiK7Uxq85u" target="_new">VOD</a></p>
        </div>

        <div class="season">
            <h2>Season 3 - Code B Arrives (~2021)</h2>
            <p>Season 3, circa 2021, added Code B to PSISTORM Gaming's Code S and A tiers, broadening the "Friends StarCraft League" scope. Wednesday/Friday matches at 8 or 9 PM US Eastern followed Bo3 Round-Robin, Bo5 playoffs, and Bo7 finals.</p>
            <div class="results">
                <strong>Code S:</strong> VeryCool def. Neutrophil 4-1<br>
                <strong>Code A:</strong> Spaghettio def. Kriminal 3-1<br>
                <strong>Code B:</strong> PanicSwitched def. LittleReaper 3-1
            </div>
            <p><a href="https://www.youtube.com/watch?v=ncjmL9UchE0&list=PLuxOPc104MmnndlUvwP5g6Ql_9rP_c1tO&pp=gAQB" target="_new">VOD</a></p>
        </div>

        <div class="season">
            <h2>Season 2 - Code A Joins (August 21, 2020)</h2>
            <p>Starting August 21, 2020, Season 2 rebranded to "Friends StarCraft League" under PSISTORM Gaming, adding Code A to Code S. With a $150 prize pool (plus donations), matches aired at 8 or 9 PM US Eastern, using Bo3 Round-Robin, Bo5 playoffs, and Bo7 finals.</p>
            <div class="results">
                <strong>Code S:</strong> Sef def. Neutrophil 4-3<br>
                <strong>Code A:</strong> RegreT def. Fluffy 4-0
            </div>
            <p><a href="https://www.youtube.com/playlist?list=PLvm8uqzqDzXVHZ_Q3cs5MwuRYREqJZEZX" target="_new">VOD</a></p>
        </div>

        <div class="season">
            <h2>Season 1 - The Beginning (Pre-August 2020)</h2>
            <p>The inaugural FSL, launched by PSISTORM Gaming before August 2020, introduced Code S as the sole division. Dubbed "Family StarCraft League," it featured Bo3 Round-Robin matches for a tight-knit community of players aged 6 to nearly 50.</p>
            <div class="results">
                <strong>Code S:</strong> Neutrophil def. SirMalagant 4-0
            </div>
            <p><a href="https://www.youtube.com/watch?v=fBjMjaulVQM&list=PLvm8uqzqDzXV-3s3FK46icZv6668QyGxR" target="_new">VOD</a></p>
        </div>
    </div>

    <!-- Add responsive scroll indicator for results sections -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const resultsDivs = document.querySelectorAll('.results');
            
            resultsDivs.forEach(div => {
                if (div.scrollWidth > div.clientWidth) {
                    const scrollIndicator = document.createElement('div');
                    scrollIndicator.className = 'scroll-indicator';
                    scrollIndicator.style.textAlign = 'center';
                    scrollIndicator.style.fontSize = '0.8em';
                    scrollIndicator.style.color = '#b0b0b0';
                    scrollIndicator.style.marginTop = '5px';
                    scrollIndicator.innerHTML = '← Scroll for more →';
                    
                    div.parentNode.insertBefore(scrollIndicator, div.nextSibling);
                }
            });
        });
    </script>

</body>
</html>

<?php
// Include footer
include_once 'includes/footer.php';
?>

