<?php
/**
 * Match Detail View Page
 * Displays detailed information about a specific match
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Helper function to extract domain name from URL
function getDomainFromUrl($url) {
    if (empty($url)) return '';
    
    // Parse the URL to get the host
    $parsedUrl = parse_url($url);
    
    if (isset($parsedUrl['host'])) {
        // Remove 'www.' if present
        $domain = preg_replace('/^www\./', '', $parsedUrl['host']);
        // Capitalize the first letter
        return ucfirst($domain);
    }
    
    return 'Link'; // Fallback
}

// Helper function to extract YouTube video ID
function getYoutubeVideoId($url) {
    if (empty($url)) return null;
    
    // Array of patterns to match various YouTube URL formats
    $patterns = [
        // Standard YouTube URLs
        '/(?:youtube\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/',
        // YouTube live URLs
        '/youtube\.com\/live\/([a-zA-Z0-9_-]{11})/',
        // YouTube shorts
        '/youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }
    }
    
    return null;
}

// Start session
session_start();

// Include database connection
require_once 'includes/db.php';

// Connect to database
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get match ID from URL parameter
$matchId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (empty($matchId)) {
    die("Match ID is required");
}

// Get match information
$matchQuery = "SELECT 
    fm.*,
    p_w.Real_Name AS winner_name,
    pa_w.Alias_Name AS winner_alias,
    t_w.Team_Name AS winner_team,
    fm.winner_race AS winner_race,
    p_l.Real_Name AS loser_name,
    pa_l.Alias_Name AS loser_alias,
    t_l.Team_Name AS loser_team,
    fm.loser_race AS loser_race
FROM fsl_matches fm
JOIN Players p_w ON fm.winner_player_id = p_w.Player_ID
JOIN Players p_l ON fm.loser_player_id = p_l.Player_ID
LEFT JOIN Player_Aliases pa_w ON p_w.Player_ID = pa_w.Player_ID
LEFT JOIN Player_Aliases pa_l ON p_l.Player_ID = pa_l.Player_ID
LEFT JOIN Teams t_w ON p_w.Team_ID = t_w.Team_ID
LEFT JOIN Teams t_l ON p_l.Team_ID = t_l.Team_ID
WHERE fm.fsl_match_id = :matchId";

try {
    $stmt = $db->prepare($matchQuery);
    $stmt->execute(['matchId' => $matchId]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$match) {
        die("Match not found");
    }
} catch (PDOException $e) {
    die("Query failed: " . $e->getMessage());
}

// Set page title
$pageTitle = "Match Details #" . htmlspecialchars($matchId);

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <h1 class="text-center">Match #<?= htmlspecialchars($matchId) ?></h1>
    
    <div class="match-detail-container">
        <div class="match-card">
            <div class="match-header">
                <span class="season">Season <?= htmlspecialchars($match['season']) ?></span>
                <?php if (!empty($match['season_extra_info'])): ?>
                    <span class="season-info"><?= htmlspecialchars($match['season_extra_info']) ?></span>
                <?php endif; ?>
            </div>
            
            <div class="match-content">
                <div class="player winner">
                    <div class="player-intro-wrapper">
                        <div class="player-intro-container" id="winner-intro">
                            <?php 
                            $introPlayerName = $match['winner_name'];
                            $uniqueId = 'winner_' . $matchId;
                            include 'view_player_intro.php';
                            ?>
                        </div>
                        <div class="player-info">
                            <a href="view_player.php?name=<?= urlencode($match['winner_name']) ?>" class="player-link">
                                <span class="name"><?= htmlspecialchars($match['winner_name']) ?></span>
                            </a>
                            <?php if (!empty($match['winner_team'])): ?>
                                <a href="view_team.php?name=<?= urlencode($match['winner_team']) ?>" class="team-link">
                                    <span class="team"><?= htmlspecialchars($match['winner_team']) ?></span>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($match['winner_race'])): ?>
                                <span class="race-badge"><?= htmlspecialchars($match['winner_race']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="score">
                    <?= $match['map_win'] ?>-<?= $match['map_loss'] ?>
                </div>
                
                <div class="player loser">
                    <div class="player-intro-wrapper">
                        <div class="player-intro-container" id="loser-intro">
                            <?php 
                            $introPlayerName = $match['loser_name'];
                            $uniqueId = 'loser_' . $matchId;
                            include 'view_player_intro.php';
                            ?>
                        </div>
                        <div class="player-info">
                            <a href="view_player.php?name=<?= urlencode($match['loser_name']) ?>" class="player-link">
                                <span class="name"><?= htmlspecialchars($match['loser_name']) ?></span>
                            </a>
                            <?php if (!empty($match['loser_team'])): ?>
                                <a href="view_team.php?name=<?= urlencode($match['loser_team']) ?>" class="team-link">
                                    <span class="team"><?= htmlspecialchars($match['loser_team']) ?></span>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($match['loser_race'])): ?>
                                <span class="race-badge"><?= htmlspecialchars($match['loser_race']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="match-details">
                <?php if (!empty($match['best_of'])): ?>
                <div class="detail-item">
                    <label>Format:</label>
                    <span>Best of <?= htmlspecialchars($match['best_of']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($match['t_code'])): ?>
                <div class="detail-item">
                    <label>Tournament Code:</label>
                    <span><?= htmlspecialchars($match['t_code']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($match['notes'])): ?>
                <div class="detail-item">
                    <label>Notes:</label>
                    <span><?= htmlspecialchars($match['notes']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($match['source'])): ?>
                    <div class="detail-item">
                        <label>Source:</label>
                        <a href="<?= htmlspecialchars($match['source']) ?>" target="_blank" class="match-link">View Source</a>
                    </div>
                <?php endif; ?>
                <?php if (!empty($match['vod'])): ?>
                    <?php 
                    $youtubeId = getYoutubeVideoId($match['vod']);
                    if ($youtubeId): 
                    ?>
                        <div class="detail-item">
                            <label>VOD:</label>
                            <span><?= htmlspecialchars(getDomainFromUrl($match['vod'])) ?></span>
                        </div>
                        <div class="video-container">
                            <iframe 
                                width="100%" 
                                height="480" 
                                src="https://www.youtube.com/embed/<?= htmlspecialchars($youtubeId) ?>" 
                                frameborder="0" 
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                                allowfullscreen>
                            </iframe>
                        </div>
                    <?php else: ?>
                        <div class="detail-item">
                            <label>VOD:</label>
                            <a href="<?= htmlspecialchars($match['vod']) ?>" target="_blank" class="match-link"><?= htmlspecialchars(getDomainFromUrl($match['vod'])) ?></a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
    .match-detail-container {
        max-width: 800px;
        margin: 2rem auto;
        padding: 20px;
    }

    .match-card {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.4);
    }

    .match-header {
        background: rgba(0, 0, 0, 0.3);
        padding: 15px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 2px solid #00d4ff;
    }

    .match-content {
        padding: 30px;
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        gap: 2rem;
        align-items: center;
        text-align: center;
    }

    .player {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .player-intro-wrapper {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 10px;
    }

    .player-intro-container {
        width: 120px;
        height: 120px;
        border-radius: 10px;
        overflow: hidden;
        background: rgba(0, 0, 0, 0.3);
        border: 2px solid rgba(255, 255, 255, 0.1);
    }

    .player-intro-container canvas {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .player-info {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 5px;
    }

    .race-badge {
        background: rgba(57, 255, 20, 0.2);
        color: #39ff14;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: bold;
        border: 1px solid #39ff14;
    }

    .player.winner .name {
        color: #28a745;
    }

    .player.loser .name {
        color: #dc3545;
    }

    .score {
        font-size: 2em;
        font-weight: bold;
        color: #00d4ff;
    }

    .player-link, .team-link {
        text-decoration: none;
        transition: all 0.3s ease;
    }

    .player-link {
        color: #e0e0e0;
    }

    .team-link {
        color: #ff6f61;
        font-size: 0.9em;
    }

    .player-link:hover {
        color: #00d4ff;
        text-shadow: 0 0 5px #00d4ff;
    }

    .team-link:hover {
        color: #ff8577;
        text-shadow: 0 0 5px #ff6f61;
    }

    .race {
        font-size: 0.9em;
        opacity: 0.8;
    }

    .match-details {
        padding: 20px;
        background: rgba(0, 0, 0, 0.2);
        border-top: 1px solid rgba(255, 255, 255, 0.1);
    }

    .detail-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 0;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .detail-item:last-child {
        border-bottom: none;
    }

    .detail-item label {
        font-weight: bold;
        color: #00d4ff;
        min-width: 120px;
    }

    .match-link {
        color: #00d4ff;
        text-decoration: none;
        transition: all 0.3s ease;
    }

    .match-link:hover {
        color: #00b8e6;
        text-shadow: 0 0 5px #00d4ff;
    }

    .video-container {
        margin-top: 20px;
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
    }

    .video-container iframe {
        border-radius: 8px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
    }

    /* Mobile Responsive */
    @media (max-width: 768px) {
        .match-content {
            grid-template-columns: 1fr;
            gap: 1rem;
            padding: 20px;
        }
        
        .player-intro-container {
            width: 100px;
            height: 100px;
        }
        
        .score {
            font-size: 1.5em;
            order: -1;
            margin-bottom: 10px;
        }
        
        .match-detail-container {
            padding: 10px;
        }
        
        .match-content {
            padding: 15px;
        }
    }
</style>

<?php include 'includes/footer.php'; ?> 