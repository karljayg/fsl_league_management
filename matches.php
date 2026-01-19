<?php
/**
 * Matches Page
 * Displays upcoming matches, allows bidding, and shows match schedules
 */

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

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
$userId = $isLoggedIn ? $_SESSION['user_id'] : null;

// Process bid submission
$bidMessage = '';
$bidStatus = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_bid'])) {
    if (!$isLoggedIn) {
        $bidMessage = "You must be logged in to place a bid.";
        $bidStatus = "error";
    } else {
        $matchId = $_POST['match_id'];
        $bidAmount = floatval($_POST['bid_amount']);
        $minBid = floatval($_POST['min_bid']);
        
        // Validate bid amount
        if ($bidAmount < $minBid) {
            $bidMessage = "Your bid must be at least $" . number_format($minBid, 2);
            $bidStatus = "error";
        } else {
            // Check if user already has a bid for this match
            $checkBidQuery = "SELECT id FROM bids WHERE user_id = ? AND match_id = ?";
            $checkStmt = $db->prepare($checkBidQuery);
            $checkStmt->execute([$userId, $matchId]);
            
            if ($checkStmt->rowCount() > 0) {
                // Update existing bid
                $updateBidQuery = "UPDATE bids SET amount = ? WHERE user_id = ? AND match_id = ?";
                $updateStmt = $db->prepare($updateBidQuery);
                $updateStmt->execute([$bidAmount, $userId, $matchId]);
                
                $bidMessage = "Your bid has been updated successfully!";
                $bidStatus = "success";
            } else {
                // Insert new bid
                $insertBidQuery = "INSERT INTO bids (id, user_id, match_id, amount, status) VALUES (UUID(), ?, ?, ?, 'pending')";
                $insertStmt = $db->prepare($insertBidQuery);
                $insertStmt->execute([$userId, $matchId, $bidAmount]);
                
                $bidMessage = "Your bid has been placed successfully!";
                $bidStatus = "success";
            }
        }
    }
}

// Get upcoming matches
$upcomingMatchesQuery = "
    SELECT 
        m.id, 
        m.title, 
        m.description,
        m.match_type, 
        m.date, 
        m.time,
        m.min_bid,
        u.username AS pro_name,
        (SELECT COUNT(*) FROM bids WHERE match_id = m.id) AS bid_count,
        (SELECT MAX(amount) FROM bids WHERE match_id = m.id) AS highest_bid
    FROM 
        matches m
    JOIN 
        users u ON m.pro_id = u.id
    WHERE 
        m.status = 'scheduled' 
        AND m.date >= CURDATE()
    ORDER BY 
        m.date ASC, m.time ASC
";

$upcomingMatches = $db->query($upcomingMatchesQuery)->fetchAll(PDO::FETCH_ASSOC);

// Get user's bids if logged in
$userBids = [];
if ($isLoggedIn) {
    $userBidsQuery = "
        SELECT 
            b.match_id,
            b.amount,
            b.status
        FROM 
            bids b
        WHERE 
            b.user_id = ?
    ";
    $userBidsStmt = $db->prepare($userBidsQuery);
    $userBidsStmt->execute([$userId]);
    
    while ($bid = $userBidsStmt->fetch(PDO::FETCH_ASSOC)) {
        $userBids[$bid['match_id']] = $bid;
    }
}

// Get completed matches
$completedMatchesQuery = "
    SELECT 
        m.id, 
        m.title, 
        m.match_type, 
        m.date, 
        m.time,
        m.winning_team, 
        m.result_description,
        u.username AS pro_name
    FROM 
        matches m
    JOIN 
        users u ON m.pro_id = u.id
    WHERE 
        m.status = 'completed' 
        AND m.match_completed = TRUE
    ORDER BY 
        m.date DESC, m.time DESC
    LIMIT 5
";

$completedMatches = $db->query($completedMatchesQuery)->fetchAll(PDO::FETCH_ASSOC);

// Set page title
$pageTitle = "Matches";

// Add additional CSS files
$additionalCss = ["css/matches.css"];

// Include header
include_once 'includes/header.php';
?>

<h1 class="text-center">FSL Pros and Joes Matches</h1>

<?php if (!empty($bidMessage)): ?>
    <div class="alert alert-<?php echo $bidStatus; ?>">
        <?php echo $bidMessage; ?>
    </div>
<?php endif; ?>

<!-- Upcoming Matches -->
<section class="mb-5">
    <h2>Upcoming Matches</h2>
    
    <?php if (empty($upcomingMatches)): ?>
        <p class="no-matches">No upcoming matches scheduled at this time. Check back soon!</p>
    <?php else: ?>
        <div class="match-grid">
            <?php foreach ($upcomingMatches as $match): ?>
                <div class="match-card">
                    <div class="match-card-header">
                        <h3><?= htmlspecialchars($match['title']) ?></h3>
                        <div class="match-info">
                            <?= htmlspecialchars($match['match_type']) ?> • 
                            <?= date('F j, Y', strtotime($match['date'])) ?> at
                            <?= date('g:i A', strtotime($match['time'])) ?>
                        </div>
                    </div>
                    <div class="match-card-body">
                        <p class="match-description"><?= htmlspecialchars($match['description']) ?></p>
                        
                        <?php
                        // Get players for this match
                        $playersQuery = "
                            SELECT 
                                mp.team_id,
                                u.username,
                                u.role,
                                mp.is_pro
                            FROM 
                                match_players mp
                            JOIN 
                                users u ON mp.user_id = u.id
                            WHERE 
                                mp.match_id = ?
                            ORDER BY 
                                mp.team_id, mp.is_pro DESC
                        ";
                        $stmt = $db->prepare($playersQuery);
                        $stmt->execute([$match['id']]);
                        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        // Group players by team
                        $teams = [];
                        foreach ($players as $player) {
                            $teams[$player['team_id']][] = $player;
                        }
                        
                        // Display teams
                        if (!empty($teams)) {
                            echo '<div class="match-teams">';
                            foreach ($teams as $teamId => $teamPlayers) {
                                echo '<div class="team">';
                                echo '<span class="team-name">Team ' . $teamId . ':</span> ';
                                
                                $playerNames = [];
                                foreach ($teamPlayers as $player) {
                                    $playerType = $player['is_pro'] ? 'Pro' : 'Amateur';
                                    $playerNames[] = htmlspecialchars($player['username']) . ' (' . $playerType . ')';
                                }
                                
                                echo implode(', ', $playerNames);
                                echo '</div>';
                            }
                            echo '</div>';
                        }
                        ?>
                        
                        <p class="match-host">Hosted by: <?= htmlspecialchars($match['pro_name']) ?></p>
                        
                        <div class="match-bid-info">
                            <div class="bid-stats">
                                <span>Minimum Bid: $<?= number_format($match['min_bid'], 2) ?></span>
                                <span>Current Bids: <?= $match['bid_count'] ?></span>
                                <?php if ($match['highest_bid']): ?>
                                    <span>Highest Bid: $<?= number_format($match['highest_bid'], 2) ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (isset($userBids[$match['id']])): ?>
                                <div class="user-bid">
                                    <p>Your Bid: $<?= number_format($userBids[$match['id']]['amount'], 2) ?></p>
                                    <p>Status: <?= ucfirst($userBids[$match['id']]['status']) ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($isLoggedIn): ?>
                                <form class="bid-form" method="post" action="">
                                    <input type="hidden" name="match_id" value="<?= $match['id'] ?>">
                                    <input type="hidden" name="min_bid" value="<?= $match['min_bid'] ?>">
                                    <div class="form-group">
                                        <label for="bid_amount_<?= $match['id'] ?>">Your Bid ($):</label>
                                        <input type="number" id="bid_amount_<?= $match['id'] ?>" name="bid_amount" 
                                               min="<?= $match['min_bid'] ?>" step="0.01" 
                                               value="<?= isset($userBids[$match['id']]) ? $userBids[$match['id']]['amount'] : $match['min_bid'] ?>" 
                                               required>
                                    </div>
                                    <button type="submit" name="submit_bid" class="btn-primary">
                                        <?= isset($userBids[$match['id']]) ? 'Update Bid' : 'Place Bid' ?>
                                    </button>
                                </form>
                            <?php else: ?>
                                <p class="login-prompt">
                                    <a href="login.php">Log in</a> to place a bid on this match.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Recent Match Results -->
<section class="mb-5">
    <h2>Recent Match Results</h2>
    <?php if (empty($completedMatches)): ?>
        <p class="no-matches">No completed matches yet.</p>
    <?php else: ?>
        <div class="match-results">
            <?php foreach ($completedMatches as $match): ?>
                <div class="match-card">
                    <div class="match-card-header">
                        <h3><?= htmlspecialchars($match['title']) ?></h3>
                        <div class="match-info">
                            <?= htmlspecialchars($match['match_type']) ?> • 
                            <?= date('F j, Y', strtotime($match['date'])) ?> at
                            <?= date('g:i A', strtotime($match['time'])) ?>
                        </div>
                    </div>
                    <div class="match-card-body">
                        <?php
                        // Get players for this match
                        $playersQuery = "
                            SELECT 
                                mp.team_id,
                                u.username,
                                u.role,
                                mp.is_pro
                            FROM 
                                match_players mp
                            JOIN 
                                users u ON mp.user_id = u.id
                            WHERE 
                                mp.match_id = ?
                            ORDER BY 
                                mp.team_id, mp.is_pro DESC
                        ";
                        $stmt = $db->prepare($playersQuery);
                        $stmt->execute([$match['id']]);
                        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        // Group players by team
                        $teams = [];
                        foreach ($players as $player) {
                            $teams[$player['team_id']][] = $player;
                        }
                        
                        // Display teams
                        echo '<div class="match-teams">';
                        foreach ($teams as $teamId => $teamPlayers) {
                            $isWinner = $match['winning_team'] == $teamId;
                            echo '<div class="team ' . ($isWinner ? 'winner' : '') . '">';
                            echo '<span class="team-name">Team ' . $teamId . ($isWinner ? ' (Winner)' : '') . ':</span> ';
                            
                            $playerNames = [];
                            foreach ($teamPlayers as $player) {
                                $playerType = $player['is_pro'] ? 'Pro' : 'Amateur';
                                $playerNames[] = htmlspecialchars($player['username']) . ' (' . $playerType . ')';
                            }
                            
                            echo implode(', ', $playerNames);
                            echo '</div>';
                        }
                        echo '</div>';
                        ?>
                        
                        <p class="match-result"><?= htmlspecialchars($match['result_description']) ?></p>
                        <p class="match-host">Hosted by: <?= htmlspecialchars($match['pro_name']) ?></p>
                        
                        <?php
                        // Get winning bids
                        $bidsQuery = "
                            SELECT 
                                b.amount,
                                u.username
                            FROM 
                                bids b
                            JOIN 
                                users u ON b.user_id = u.id
                            WHERE 
                                b.match_id = ?
                            ORDER BY 
                                b.amount DESC
                            LIMIT 3
                        ";
                        $bidsStmt = $db->prepare($bidsQuery);
                        $bidsStmt->execute([$match['id']]);
                        $bids = $bidsStmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        if (!empty($bids)) {
                            echo '<div class="top-bids">';
                            echo '<h4>Top Bids:</h4>';
                            echo '<ul>';
                            foreach ($bids as $index => $bid) {
                                echo '<li>' . ($index + 1) . '. ' . htmlspecialchars($bid['username']) . ' - $' . number_format($bid['amount'], 2) . '</li>';
                            }
                            echo '</ul>';
                            echo '</div>';
                        }
                        ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php
// Include footer
include_once 'includes/footer.php';
?> 