
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FSL History - Foreigner StarCraft League</title>
    <link rel="stylesheet" href="css/styles.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- Chart.js for data visualization -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <!-- Header Section -->
    <header>
        <div class="container">
            <div class="logo">
                <img src="images/fsl_logo.png" alt="FSL Logo" id="logo-placeholder">
                <h1>Foreigner StarCraft League</h1>
            </div>
            <nav>
                <ul>
                    <li><a href="#league-evolution">League Evolution</a></li>
                    <li><a href="#player-spotlights">Players</a></li>
                    <li><a href="#team-analysis">Teams</a></li>
                    <li><a href="#match-highlights">Matches</a></li>
                    <li><a href="#statistics">Statistics</a></li>
                    <li><a href="#media-gallery">Media</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Hero Section -->
    <section id="hero">
        <div class="container">
            <div class="hero-content">
                <h2>The History of FSL</h2>
                <p>Explore the evolution of the Foreigner StarCraft League, from its humble beginnings to becoming a cornerstone of the StarCraft II community.</p>
            </div>
        </div>
    </section>

    <!-- Season Timeline -->
    <section id="season-timeline">
        <div class="container">
            <h2>Season Timeline</h2>
            <div class="timeline">
                <div class="timeline-item" data-season="1">
                    <div class="timeline-marker">S1</div>
                    <div class="timeline-content">
                        <h3>Season 1</h3>
                        <p>The beginning of FSL with a single Code S division</p>
                    </div>
                </div>
                <div class="timeline-item" data-season="2">
                    <div class="timeline-marker">S2</div>
                    <div class="timeline-content">
                        <h3>Season 2</h3>
                        <p>Addition of Code A division</p>
                    </div>
                </div>
                <div class="timeline-item" data-season="3">
                    <div class="timeline-marker">S3</div>
                    <div class="timeline-content">
                        <h3>Season 3</h3>
                        <p>Introduction of Code B division</p>
                    </div>
                </div>
                <div class="timeline-item" data-season="4">
                    <div class="timeline-marker">S4</div>
                    <div class="timeline-content">
                        <h3>Season 4</h3>
                        <p>Addition of 2v2 competition</p>
                    </div>
                </div>
                <div class="timeline-item" data-season="5">
                    <div class="timeline-marker">S5</div>
                    <div class="timeline-content">
                        <h3>Season 5</h3>
                        <p>Expanded prize pool and production quality</p>
                    </div>
                </div>
                <div class="timeline-item" data-season="6">
                    <div class="timeline-marker">S6</div>
                    <div class="timeline-content">
                        <h3>Season 6</h3>
                        <p>Further refinement of tournament structure</p>
                    </div>
                </div>
                <div class="timeline-item" data-season="7">
                    <div class="timeline-marker">S7</div>
                    <div class="timeline-content">
                        <h3>Season 7</h3>
                        <p>Introduction of Code S+ and 2v2+ divisions</p>
                    </div>
                </div>
                <div class="timeline-item" data-season="8">
                    <div class="timeline-marker">S8</div>
                    <div class="timeline-content">
                        <h3>Season 8</h3>
                        <p>Introduction of Team League format</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- League Evolution -->
    <section id="league-evolution">
        <div class="container">
            <h2>League Evolution</h2>
            <div class="evolution-content">
                <div class="evolution-text">
                    <p>The Foreigner StarCraft League has evolved significantly since its inception, growing from a simple tournament format to a comprehensive competitive ecosystem with multiple divisions and team-based play.</p>
                    <p>Originally known as the "Family StarCraft League," FSL was designed to be inclusive, welcoming players of all ages and skill levels. This philosophy has remained at the core of the league even as it has expanded and professionalized.</p>
                </div>
                <div class="evolution-chart">
                    <canvas id="leagueGrowthChart"></canvas>
                </div>
            </div>
        </div>
    </section>

    <!-- Player Spotlights -->
    <section id="player-spotlights">
        <div class="container">
            <h2>Player Spotlights</h2>
            <div class="player-filters">
                <button class="filter-btn active" data-filter="all">All</button>
                <button class="filter-btn" data-filter="terran">Terran</button>
                <button class="filter-btn" data-filter="protoss">Protoss</button>
                <button class="filter-btn" data-filter="zerg">Zerg</button>
                <button class="filter-btn" data-filter="random">Random</button>
            </div>
            <div class="player-cards">
                <div class="player-card" data-race="zerg">
                    <div class="player-image">
                        <img src="images/player_placeholder.png" alt="DarkMenace" class="player-placeholder">
                    </div>
                    <div class="player-info">
                        <h3>DarkMenace</h3>
                        <p class="player-race zerg">Zerg</p>
                        <p>Win Rate: 66.1%</p>
                        <button class="view-profile-btn">View Profile</button>
                    </div>
                </div>
                <div class="player-card" data-race="random">
                    <div class="player-image">
                        <img src="images/player_placeholder.png" alt="Neutrophil" class="player-placeholder">
                    </div>
                    <div class="player-info">
                        <h3>Neutrophil</h3>
                        <p class="player-race random">Random/Protoss</p>
                        <p>Win Rate: 65.1%</p>
                        <button class="view-profile-btn">View Profile</button>
                    </div>
                </div>
                <div class="player-card" data-race="terran">
                    <div class="player-image">
                        <img src="images/player_placeholder.png" alt="Vales" class="player-placeholder">
                    </div>
                    <div class="player-info">
                        <h3>Vales</h3>
                        <p class="player-race terran">Terran</p>
                        <p>Win Rate: 73.6%</p>
                        <button class="view-profile-btn">View Profile</button>
                    </div>
                </div>
                <div class="player-card" data-race="zerg">
                    <div class="player-image">
                        <img src="images/player_placeholder.png" alt="RegreT" class="player-placeholder">
                    </div>
                    <div class="player-info">
                        <h3>RegreT</h3>
                        <p class="player-race zerg">Zerg</p>
                        <p>Win Rate: 78.8%</p>
                        <button class="view-profile-btn">View Profile</button>
                    </div>
                </div>
                <div class="player-card" data-race="protoss">
                    <div class="player-image">
                        <img src="images/player_placeholder.png" alt="LittleReaper" class="player-placeholder">
                    </div>
                    <div class="player-info">
                        <h3>LittleReaper</h3>
                        <p class="player-race protoss">Protoss</p>
                        <p>Win Rate: 62.5%</p>
                        <button class="view-profile-btn">View Profile</button>
                    </div>
                </div>
                <div class="player-card" data-race="protoss">
                    <div class="player-image">
                        <img src="images/player_placeholder.png" alt="TheArchaic" class="player-placeholder">
                    </div>
                    <div class="player-info">
                        <h3>TheArchaic</h3>
                        <p class="player-race protoss">Protoss</p>
                        <p>Win Rate: 64.7%</p>
                        <button class="view-profile-btn">View Profile</button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Team Analysis -->
    <section id="team-analysis">
        <div class="container">
            <h2>Team Analysis</h2>
            <div class="team-cards">
                <div class="team-card">
                    <div class="team-logo">
                        <img src="images/team_placeholder.png" alt="PulledTheBoys" class="team-placeholder">
                    </div>
                    <div class="team-info">
                        <h3>PulledTheBoys</h3>
                        <p>Home to top performers DarkMenace and Neutrophil</p>
                        <p>Known for: Aggressive playstyle and strategic depth</p>
                        <button class="view-team-btn">Team Details</button>
                    </div>
                </div>
                <div class="team-card">
                    <div class="team-logo">
                        <img src="images/team_placeholder.png" alt="Angry Space Hares" class="team-placeholder">
                    </div>
                    <div class="team-info">
                        <h3>Angry Space Hares</h3>
                        <p>Balanced roster including Vales and TheArchaic</p>
                        <p>Known for: Consistent performance across divisions</p>
                        <button class="view-team-btn">Team Details</button>
                    </div>
                </div>
                <div class="team-card">
                    <div class="team-logo">
                        <img src="images/team_placeholder.png" alt="Infinite Cyclists" class="team-placeholder">
                    </div>
                    <div class="team-info">
                        <h3>Infinite Cyclists</h3>
                        <p>Endurance specialists with late-game strategies</p>
                        <p>Known for: Methodical play and strategic patience</p>
                        <button class="view-team-btn">Team Details</button>
                    </div>
                </div>
            </div>
            <div class="team-performance">
                <h3>Team Performance Comparison</h3>
                <canvas id="teamPerformanceChart"></canvas>
            </div>
        </div>
    </section>

    <!-- Match Highlights -->
    <section id="match-highlights">
        <div class="container">
            <h2>Match Highlights</h2>
            <div class="match-filters">
                <button class="filter-btn active" data-filter="all">All Matches</button>
                <button class="filter-btn" data-filter="finals">Finals</button>
                <button class="filter-btn" data-filter="upsets">Upsets</button>
                <button class="filter-btn" data-filter="classics">Classics</button>
            </div>
            <div class="featured-match">
                <h3>Featured Match: DarkMenace vs Neutrophil (Season 8 Finals)</h3>
                <div class="match-video">
                    <div class="video-placeholder">
                        <i class="fas fa-play-circle"></i>
                        <p>Click to play video</p>
                    </div>
                </div>
                <div class="match-details">
                    <div class="player-comparison">
                        <div class="player-side">
                            <h4>DarkMenace</h4>
                            <p class="player-race zerg">Zerg</p>
                        </div>
                        <div class="match-score">
                            <span>3</span>
                            <span>-</span>
                            <span>2</span>
                        </div>
                        <div class="player-side">
                            <h4>Neutrophil</h4>
                            <p class="player-race protoss">Protoss</p>
                        </div>
                    </div>
                    <div class="match-stats">
                        <h4>Match Statistics</h4>
                        <div class="stats-grid">
                            <div class="stat-item">
                                <p>Average Game Length</p>
                                <p>14:32</p>
                            </div>
                            <div class="stat-item">
                                <p>Maps Played</p>
                                <p>Romanticide, Blackburn, Hardwire, Glittering Ashes, Moondance</p>
                            </div>
                            <div class="stat-item">
                                <p>Key Moment</p>
                                <p>Game 5 baneling bust at 8:45</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="match-list">
                <div class="match-item">
                    <div class="match-players">
                        <span class="player-name">Vales</span>
                        <span class="match-score">3-1</span>
                        <span class="player-name">TheArchaic</span>
                    </div>
                    <div class="match-meta">
                        <span class="match-season">Season 7</span>
                        <span class="match-type">Semi-Finals</span>
                    </div>
                    <button class="view-match-btn">Watch Match</button>
                </div>
                <div class="match-item">
                    <div class="match-players">
                        <span class="player-name">LittleReaper</span>
                        <span class="match-score">3-2</span>
                        <span class="player-name">RegreT</span>
                    </div>
                    <div class="match-meta">
                        <span class="match-season">Season 6</span>
                        <span class="match-type">Finals</span>
                    </div>
                    <button class="view-match-btn">Watch Match</button>
                </div>
                <div class="match-item">
                    <div class="match-players">
                        <span class="player-name">DarkMenace</span>
                        <span class="match-score">3-0</span>
                        <span class="player-name">Vales</span>
                    </div>
                    <div class="match-meta">
                        <span class="match-season">Season 5</span>
                        <span class="match-type">Finals</span>
                    </div>
                    <button class="view-match-btn">Watch Match</button>
                </div>
            </div>
        </div>
    </section>

    <!-- Statistics Dashboard -->
    <section id="statistics">
        <div class="container">
            <h2>Statistics Dashboard</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Win Rates by Race</h3>
                    <canvas id="raceWinRateChart"></canvas>
                </div>
                <div class="stat-card">
                    <h3>Player Win Rates (Top 5)</h3>
                    <canvas id="playerWinRateChart"></canvas>
                </div>
                <div class="stat-card">
                    <h3>Division Distribution</h3>
                    <canvas id="divisionDistributionChart"></canvas>
                </div>
                <div class="stat-card">
                    <h3>Map Win Rates</h3>
                    <canvas id="mapWinRateChart"></canvas>
                </div>
            </div>
            <div class="player-comparison-tool">
                <h3>Player Comparison Tool</h3>
                <div class="comparison-selectors">
                    <div class="player-select">
                        <label for="player1">Player 1</label>
                        <select id="player1">
                            <option value="darkmenace">DarkMenace</option>
                            <option value="neutrophil">Neutrophil</option>
                            <option value="vales">Vales</option>
                            <option value="regret">RegreT</option>
                            <option value="littlereaper">LittleReaper</option>
                        </select>
                    </div>
                    <div class="player-select">
                        <label for="player2">Player 2</label>
                        <select id="player2">
                            <option value="neutrophil">Neutrophil</option>
                            <option value="darkmenace">DarkMenace</option>
                            <option value="vales">Vales</option>
                            <option value="regret">RegreT</option>
                            <option value="littlereaper">LittleReaper</option>
                        </select>
                    </div>
                    <button id="compare-btn">Compare</button>
                </div>
                <div class="comparison-result">
                    <canvas id="playerComparisonChart"></canvas>
                </div>
            </div>
        </div>
    </section>

    <!-- Media Gallery -->
    <section id="media-gallery">
        <div class="container">
            <h2>Media Gallery</h2>
            <div class="gallery-filters">
                <button class="filter-btn active" data-filter="all">All</button>
                <button class="filter-btn" data-filter="players">Players</button>
                <button class="filter-btn" data-filter="matches">Matches</button>
                <button class="filter-btn" data-filter="events">Events</button>
            </div>
            <div class="gallery-grid">
                <div class="gallery-item" data-type="players">
                    <div class="gallery-image">
                        <img src="images/gallery_placeholder.png" alt="Player Intro" class="gallery-placeholder">
                    </div>
                    <div class="gallery-caption">
                        <p>DarkMenace player introduction</p>
                    </div>
                </div>
                <div class="gallery-item" data-type="matches">
                    <div class="gallery-image">
                        <img src="images/gallery_placeholder.png" alt="Match Highlight" class="gallery-placeholder">
                    </div>
                    <div class="gallery-caption">
                        <p>Season 8 Finals - Key moment</p>
                    </div>
                </div>
                <div class="gallery-item" data-type="events">
                    <div class="gallery-image">
                        <img src="images/gallery_placeholder.png" alt="Event" class="gallery-placeholder">
                    </div>
                    <div class="gallery-caption">
                        <p>Season 7 player gathering</p>
                    </div>
                </div>
                <div class="gallery-item" data-type="players">
                    <div class="gallery-image">
                        <img src="images/gallery_placeholder.png" alt="Player Intro" class="gallery-placeholder">
                    </div>
                    <div class="gallery-caption">
                        <p>LittleReaper player introduction</p>
                    </div>
                </div>
                <div class="gallery-item" data-type="matches">
                    <div class="gallery-image">
                        <img src="images/gallery_placeholder.png" alt="Match Highlight" class="gallery-placeholder">
                    </div>
                    <div class="gallery-caption">
                        <p>Vales vs TheArchaic - Season 7 Semi-Finals</p>
                    </div>
                </div>
                <div class="gallery-item" data-type="events">
                    <div class="gallery-image">
                        <img src="images/gallery_placeholder.png" alt="Event" class="gallery-placeholder">
                    </div>
                    <div class="gallery-caption">
                        <p>Season 6 trophy ceremony</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="footer-content">
                <div class="footer-logo">
                    <img src="images/fsl_logo.png" alt="FSL Logo" id="footer-logo-placeholder">
                    <p>Foreigner StarCraft League</p>
                </div>
                <div class="footer-links">
                    <h3>Official Links</h3>
                    <ul>
                        <li><a href="https://psistorm.com/fsl" target="_blank">Official Website</a></li>
                        <li><a href="https://www.youtube.com/@psistormgaming/playlists" target="_blank">YouTube</a></li>
                        <li><a href="https://www.facebook.com/groups/fstarcraftleague" target="_blank">Facebook</a></li>
                        <li><a href="https://x.com/psistormgaming" target="_blank">Twitter/X</a></li>
                    </ul>
                </div>
                <div class="footer-credits">
                    <h3>Credits</h3>
                    <p>Data sourced from PSIStorm Gaming and Liquipedia</p>
                    <p>VODs courtesy of PSIStorm Gaming YouTube channel</p>
                    <p>&copy; 2025 FSL History Project</p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Modal for Player Profiles -->
    <div id="player-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <div id="player-profile-content">
                <!-- Content will be dynamically loaded here -->
            </div>
        </div>
    </div>

    <!-- Modal for Team Details -->
    <div id="team-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <div id="team-details-content">
                <!-- Content will be dynamically loaded here -->
            </div>
        </div>
    </div>

    <!-- Modal for Match Viewer -->
    <div id="match-modal" class="modal">
        <div class="modal-content match-modal-content">
            <span class="close-modal">&times;</span>
            <div id="match-viewer-content">
                <!-- Content will be dynamically loaded here -->
            </div>
        </div>
    </div>

    <!-- JavaScript Files -->
    <script src="js/data.js"></script>
    <script src="js/charts.js"></script>
    <script src="js/main.js"></script>
</body>
</html>

