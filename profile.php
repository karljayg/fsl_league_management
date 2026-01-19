<?php
session_start();

// Database connection
require_once 'includes/db.php';

// Connect to database
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Initialize login state
$isLoggedIn = isset($_SESSION['user_id']);
$loggedInUsername = $isLoggedIn ? $_SESSION['username'] : null;

// Determine which profile to show
$requestedUsername = isset($_GET['username']) ? $_GET['username'] : $loggedInUsername;

// If not logged in and no username specified, redirect to login
if (!$requestedUsername) {
    header('Location: login.php');
    exit;
}

// Get user data
$stmt = $db->prepare('SELECT id, username, email, role, mmr, race_preference, avatar_url FROM users WHERE username = ?');
$stmt->execute([$requestedUsername]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// If user not found, show error
if (!$user) {
    $error = 'User not found';
}

// Get user's match history (if they're a regular user)
$matchHistory = [];
if ($user && $user['role'] === 'user') {
    $stmt = $db->prepare('
        SELECT b.id as bid_id, b.amount, b.status, b.bid_time, 
               m.id as match_id, m.title, m.date, m.time, m.match_type,
               u.username as pro_username, u.mmr as pro_mmr, u.race_preference as pro_race
        FROM bids b
        JOIN matches m ON b.match_id = m.id
        JOIN users u ON m.pro_id = u.id
        WHERE b.user_id = ?
        ORDER BY b.bid_time DESC
    ');
    $stmt->execute([$user['id']]);
    $matchHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get pro's scheduled matches (if they're a pro)
$scheduledMatches = [];
if ($user && $user['role'] === 'pro') {
    $stmt = $db->prepare('
        SELECT m.id, m.title, m.description, m.date, m.time, m.match_type, m.min_bid, m.status,
               COUNT(b.id) as bid_count
        FROM matches m
        LEFT JOIN bids b ON m.id = b.match_id
        WHERE m.pro_id = ?
        GROUP BY m.id
        ORDER BY m.date ASC, m.time ASC
    ');
    $stmt->execute([$user['id']]);
    $scheduledMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Check if viewing own profile
$isOwnProfile = $isLoggedIn && $loggedInUsername === $requestedUsername;

// Set page title
$pageTitle = htmlspecialchars($requestedUsername) . "'s Profile";

// Include header
include_once 'includes/header.php';
?>

<section class="profile-section">
    <?php if (isset($error)): ?>
        <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
    <?php else: ?>
        <div class="profile-header">
            <div class="profile-avatar">
                <img src="<?php echo !empty($user['avatar_url']) ? htmlspecialchars($user['avatar_url']) : 'images/default-avatar.png'; ?>" alt="<?php echo htmlspecialchars($user['username']); ?>">
            </div>
            <div class="profile-info">
                <h1><?php echo htmlspecialchars($user['username']); ?></h1>
                <div class="profile-details">
                    <span class="profile-role <?php echo $user['role']; ?>"><?php echo ucfirst($user['role']); ?></span>
                    <?php if ($user['mmr']): ?>
                        <span class="profile-mmr">MMR: <?php echo htmlspecialchars($user['mmr']); ?></span>
                    <?php endif; ?>
                    <?php if ($user['race_preference']): ?>
                        <span class="profile-race">Race: <?php echo htmlspecialchars($user['race_preference']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($isOwnProfile): ?>
                <a href="edit_profile.php" class="edit-profile-btn">Edit Profile</a>
            <?php endif; ?>
        </div>

        <?php if ($user['role'] === 'user'): ?>
            <!-- Regular User Profile Content -->
            <div class="profile-content">
                <h2>Match History</h2>
                <?php if (empty($matchHistory)): ?>
                    <p class="no-data">No matches found. Start bidding to see your match history!</p>
                <?php else: ?>
                    <div class="match-history">
                        <?php foreach ($matchHistory as $match): ?>
                            <div class="match-card">
                                <div class="match-card-header">
                                    <div class="match-title"><?php echo htmlspecialchars($match['title']); ?></div>
                                    <div class="match-date">
                                        <?php echo date('M d, Y', strtotime($match['date'])); ?> at 
                                        <?php echo date('g:i A', strtotime($match['time'])); ?>
                                    </div>
                                </div>
                                <div class="match-details">
                                    <span class="match-type"><?php echo htmlspecialchars($match['match_type']); ?></span>
                                    <span class="bid-amount">Your bid: $<?php echo htmlspecialchars($match['amount']); ?></span>
                                    <span class="bid-status <?php echo $match['status']; ?>"><?php echo ucfirst($match['status']); ?></span>
                                </div>
                                <div class="pro-details">
                                    <div class="pro-info">
                                        <div class="pro-username">Pro: <?php echo htmlspecialchars($match['pro_username']); ?></div>
                                        <div class="pro-stats">
                                            MMR: <?php echo $match['pro_mmr'] ?: 'N/A'; ?> | 
                                            Race: <?php echo $match['pro_race'] ?: 'Random'; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- Pro Player Profile Content -->
            <div class="profile-content">
                <h2>Scheduled Matches</h2>
                <?php if (empty($scheduledMatches)): ?>
                    <p class="no-data">No scheduled matches found.</p>
                <?php else: ?>
                    <div class="scheduled-matches">
                        <?php foreach ($scheduledMatches as $match): ?>
                            <div class="match-card">
                                <div class="match-card-header">
                                    <div class="match-title"><?php echo htmlspecialchars($match['title']); ?></div>
                                    <div class="match-date">
                                        <?php echo date('M d, Y', strtotime($match['date'])); ?> at 
                                        <?php echo date('g:i A', strtotime($match['time'])); ?>
                                    </div>
                                </div>
                                <div class="match-details">
                                    <p><?php echo htmlspecialchars($match['description'] ?: 'No description available'); ?></p>
                                    <span class="match-type"><?php echo htmlspecialchars($match['match_type']); ?></span>
                                    <span class="match-status <?php echo $match['status']; ?>"><?php echo ucfirst($match['status']); ?></span>
                                </div>
                                <div class="match-stats">
                                    <div class="min-bid">Minimum Bid: $<?php echo htmlspecialchars($match['min_bid']); ?></div>
                                    <div class="bid-count">Bids: <?php echo $match['bid_count']; ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php
// Include footer
include_once 'includes/footer.php';
?> 