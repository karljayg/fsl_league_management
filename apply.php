<?php
// Set the include path to ensure proper file resolution
set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__);

// Start output buffering and session
ob_start();
session_start();

// Set page title
$pageTitle = "Apply to Join FSL";

// Add any additional CSS files
$additionalCss = [];

// Include header
include_once 'includes/header.php';
?>

<style>
    body {
        font-family: 'Inter', sans-serif;
        background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
        color: #e0e0e0;
        margin: 0;
        padding: 0;
        line-height: 1.6;
    }
    
    .container {
        max-width: 1000px;
        margin: 20px auto;
        padding: 20px;
        overflow-x: auto;
    }
    
    h1 {
        text-align: center;
        color: #00d4ff;
        text-shadow: 0 0 15px #00d4ff;
        font-size: 2.8em;
        margin-bottom: 40px;
    }
    
    h2 {
        color: #ff6f61;
        font-size: 2em;
        margin-bottom: 15px;
        border-bottom: 2px solid #ff6f61;
        padding-bottom: 5px;
    }
    
    h3 {
        color: #00d4ff;
        font-size: 1.5em;
        margin-top: 25px;
        margin-bottom: 10px;
    }
    
    p {
        font-size: 1.1em;
        margin: 10px 0;
    }
    
    ul {
        margin: 10px 0 20px 20px;
        padding-left: 20px;
    }
    
    li {
        margin-bottom: 10px;
        font-size: 1.1em;
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
    
    .section {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        padding: 25px;
        margin-bottom: 25px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.4);
        border-left: 5px solid #ff6f61;
    }
    
    .application-options {
        display: flex;
        flex-direction: column;
        gap: 15px;
        margin-top: 20px;
    }
    
    .application-option {
        background: rgba(0, 212, 255, 0.1);
        border: 2px solid #00d4ff;
        border-radius: 8px;
        padding: 20px;
        transition: all 0.3s ease;
    }
    
    .application-option:hover {
        background: rgba(0, 212, 255, 0.2);
        border-color: #ff6f61;
        transform: translateX(5px);
    }
    
    .application-option h4 {
        color: #00d4ff;
        margin: 0 0 10px 0;
        font-size: 1.3em;
    }
    
    .application-option p {
        margin: 5px 0;
    }
    
    .username {
        color: #00d4ff;
        font-weight: 600;
        font-family: 'Courier New', monospace;
        background: rgba(0, 212, 255, 0.1);
        padding: 2px 8px;
        border-radius: 4px;
        display: inline-block;
    }
    
    .reddit-link {
        display: inline-block;
        margin-top: 10px;
        padding: 8px 15px;
        background: rgba(255, 69, 0, 0.2);
        border: 1px solid rgba(255, 69, 0, 0.5);
        border-radius: 5px;
        color: #ff6f61;
        text-decoration: none;
        transition: all 0.3s ease;
    }
    
    .reddit-link:hover {
        background: rgba(255, 69, 0, 0.3);
        border-color: #ff6f61;
        color: #ffffff;
        text-decoration: none;
    }
    
    .coming-soon {
        color: #ff6f61;
        font-style: italic;
    }
    
    .youtube-container {
        margin: 30px 0;
        text-align: center;
    }
    
    .youtube-embed {
        position: relative;
        padding-bottom: 56.25%;
        height: 0;
        overflow: hidden;
        max-width: 100%;
        border-radius: 10px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.4);
    }
    
    .youtube-embed iframe {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        border: none;
    }
    
    .highlight-box {
        background: rgba(255, 111, 97, 0.1);
        border: 2px solid #ff6f61;
        border-radius: 8px;
        padding: 20px;
        margin: 20px 0;
        text-align: center;
    }
    
    .highlight-box h3 {
        color: #ff6f61;
        margin-top: 0;
    }
    
    @media (max-width: 768px) {
        h1 { font-size: 2em; }
        h2 { font-size: 1.6em; }
        h3 { font-size: 1.3em; }
        p, li { font-size: 1em; }
        .container { padding: 10px; }
        .application-option {
            padding: 15px;
        }
    }
</style>

<div class="container">
    <h1>Apply to Join FSL</h1>

    <div class="section">
        <h2>Welcome to FSL - Fun StarCraft League</h2>
        <p>FSL is a welcoming, family-friendly StarCraft II league that brings together players of all skill levels. We pride ourselves on creating a positive, inclusive environment where everyone can enjoy competitive StarCraft II while maintaining good sportsmanship and respect for one another.</p>
        <p>Whether you're a seasoned veteran or just starting your StarCraft journey, FSL offers a place where you can compete, learn, and grow as a player in a supportive community atmosphere.</p>
    </div>

    <div class="section">
        <h2>Our Family-Friendly Atmosphere</h2>
        <p>At FSL, we believe that competitive gaming should be accessible and enjoyable for everyone. Our community values:</p>
        <ul>
            <li><strong>Respect:</strong> We treat all players with dignity and respect, both in-game and in our community spaces.</li>
            <li><strong>Sportsmanship:</strong> Good manners and fair play are at the heart of everything we do.</li>
            <li><strong>Inclusivity:</strong> Players of all backgrounds, skill levels, and ages are welcome.</li>
            <li><strong>Support:</strong> We help each other improve and celebrate each other's successes.</li>
            <li><strong>Fun:</strong> While we take competition seriously, we never forget that gaming should be enjoyable.</li>
        </ul>
    </div>

    <div class="section">
        <h2>Requirements</h2>
        <p>Before applying, please review our requirements and frequently asked questions. We want to make sure FSL is the right fit for you, and that you understand what's expected of all participants.</p>
        <p>For detailed information about our requirements, rules, and expectations, please visit our <a href="faq.php">FAQ page</a>.</p>
    </div>

    <div class="highlight-box">
        <h3>Season 10 Draft - January 31st</h3>
        <p style="font-size: 1.2em; margin: 10px 0;">Don't miss out! The Season 10 draft is scheduled for January 31st. Apply now to be considered for the upcoming season!</p>
    </div>

    <div class="section">
        <h2>How to Apply</h2>
        <p>Submit your application to join FSL. Additional contact options for questions and discussion are available:</p>
        
        <div class="application-options">
            <div class="application-option">
                <h4>Google Form</h4>
		<a href="https://forms.cloud.microsoft/pages/responsepage.aspx?id=DQSIkWdsW0yxEjajBLZtrQAAAAAAAAAAAAN__qmuwg9UMUpaODVSNEdBNzFGN0ZaTUZRRzVISTJQTy4u&route=shorturl" target="_blank" class="reddit-link">
		Apply here
		</a>
            </div>
            <div class="application-option">
                <h4>Discord</h4>
                <p>Send a direct message to <span class="username">hyper_turtle</span> on Discord to express your interest in joining FSL. You'll need to fill out the form above regardless, but he will be discussing the league with you to ensure full understanding.</p>
            </div>
            
            <div class="application-option">
                <h4>Reddit</h4>
                <p>We posted on reddit, you can message <span class="username">CounterfeitDLC</span> on Reddit also.</p>
                <a href="https://www.reddit.com/r/starcraft/comments/1qcsg5l/casual_team_league_fsl_is_looking_for_players/" target="_blank" class="reddit-link">
                    View Latest Reddit Post →
                </a>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>Get a Feel for FSL</h2>
        <p>Want to see what FSL is all about? Check out our Season 9 broadcast to experience the excitement, commentary, and community spirit that makes FSL special.</p>
        
        <div class="youtube-container">
            <div class="youtube-embed">
                <iframe 
                    src="https://www.youtube.com/embed/7pRh7ohhXrk" 
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                    allowfullscreen>
                </iframe>
            </div>
        </div>
        
        <p style="text-align: center; margin-top: 15px;">
            <a href="https://youtu.be/7pRh7ohhXrk" target="_blank">Watch on YouTube</a>
        </p>
    </div>

    <div class="section">
        <h2>Questions?</h2>
        <p>If you have any questions about applying to FSL, our requirements, or what to expect, please don't hesitate to:</p>
        <ul>
            <li>Check out our <a href="faq.php">FAQ page</a> for common questions and answers</li>
            <li>Reach out to <span class="username">hyper_turtle</span> on Discord or <span class="username">CounterfeitDLC</span> on Reddit</li>
            <li>Join our community Discord server to connect with current players</li>
        </ul>
        <p>We look forward to welcoming you to the FSL family!</p>
    </div>
</div>

<?php
// Include footer
include_once 'includes/footer.php';
?>

