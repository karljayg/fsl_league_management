<?php
// Set the include path to ensure proper file resolution
set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__);

// Start output buffering and session
ob_start();
session_start();

// Set page title
$pageTitle = "The Ultimate StarCraft II Experience";

// Add any additional CSS files
$additionalCss = [];

// Include header
include_once 'includes/header.php';
?>

<section class="hero">
    <div class="hero-content">

	<div>
  		<iframe width="854" height="480" src="https://www.youtube.com/embed/8yP_wG3s_mU?mute=1&vq=medium&loop=1&playlist=8yP_wG3s_mU&autoplay=1&origin=http://psistorm.com" frameborder="0" allow="autoplay; encrypted-media" allowfullscreen></iframe>
	</div>

        <h1>Pros & Joes:<br>The Ultimate StarCraft II Experience</h1>
        <p>Battle alongside or against professional StarCraft II players in an exclusive gaming experience designed for both casual gamers and competitors.</p>
        <div class="hero-buttons">
            <?php if (!isset($_SESSION['user_id'])): ?>
                <a href="register.php" class="primary-btn">Register Now</a>
            <?php endif; ?>
            <button class="secondary-btn">View Matches</button>
        </div>
    </div>
    <div class="hero-image">
        <div class="sc-logo">
            <span>SC</span>
            <small>FSL Pros and Joes</small>
        </div>
    </div>
</section>

<section id="about" class="about">
    <div class="about-content">
        <div class="about-image">
            <img src="images/pros_and_joes_serral.jpeg" alt="VS Logo">
        </div>
        <div class="about-text">
            <h2>ABOUT FSL Pros and Joes</h2>
            <h3>The Ultimate Pro-Amateur Gaming Experience</h3>
            <p>FSL Pros and Joes is a revolutionary platform bringing together professional StarCraft II players and casual enthusiasts in an intense, competitive environment. Our mission is to bridge the gap between pros and casual players, creating unique gaming experiences that are both challenging and rewarding.</p>
            
            <div class="features">
                <div class="feature">
                    <h4>Elite Competition</h4>
                    <p>Face off against or team up with world-class professional StarCraft II players!</p>
                </div>
                <div class="feature">
                    <h4>Fair Matchmaking</h4>
                    <p>Our 1v1 ladder features will levels to ensure exciting, competitive matches.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section id="bidding" class="bidding">
    <div class="bidding-content">
        <div class="bidding-info">
            <h2>BIDDING SYSTEM</h2>
            <h3>Secure Your Spot in the Arena</h3>
            <p>Our transparent bidding system ensures fair access to matches while supporting professional players and the StarCraft II community.</p>
            
            <div class="available-matches" id="availableMatches">
                <!-- Available matches will be populated by JavaScript -->
            </div>
        </div>
        
        <div class="current-queue">
        <div class="about-image">
            <img src="images/FSL_PSISTORMCup_Cheesadelphia_viewing.png" alt="Viewers">
        </div>
            <div id="selectedMatch" class="selected-match" style="display: none;">
                <h3>Selected Match</h3>
                <div class="match-details">
                    <!-- Selected match details will be populated by JavaScript -->
                </div>
                <div class="pro-details">
                    <!-- Pro player details will be populated by JavaScript -->
                </div>
            </div>

            <div id="bidsList" class="bids-list" style="display: none;">
                <h3>Current Bids</h3>
                <div class="queue-list" id="queueList">
                    <!-- Bids will be populated by JavaScript -->
                </div>
            </div>

            <div class="bid-form" id="bidForm" style="display: none;">
                <h3>Place Your Bid</h3>
                <div class="bid-input">
                    <input type="number" id="bidAmount" placeholder="Enter bid amount" step="0.01" min="0">
                    <button class="primary-btn" id="placeBidBtn">Place Bid</button>
                </div>
                <p class="min-bid-note" id="minBidNote"></p>
            </div>
        </div>
    </div>
</section>

<?php
// Include footer
include_once 'includes/footer.php';
?> 
