<?php
$pageTitle = "Voting Guide";
$additionalCss = ['css/styles.css'];

require_once 'includes/header.php';
?>

<style>
    /* Override any global white text colors */
    .voting-guide,
    .voting-guide * {
        color: #333 !important;
    }
    
    .voting-guide {
        max-width: 800px;
        margin: 0 auto;
        background: #f9f9f9;
        border-radius: 10px;
        overflow: hidden;
    }
    
    .guide-header {
        text-align: center;
        background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
        color: white !important;
        padding: 30px;
        margin-bottom: 0;
    }
    
    .guide-header h1 {
        margin: 0;
        font-size: 2.5em;
        color: #00d4ff !important;
    }
    
    .guide-header p {
        margin: 10px 0 0 0;
        font-size: 1.2em;
        opacity: 0.9;
        color: white !important;
    }
    
    .guide-content {
        padding: 0;
    }
    
    .section {
        background: white;
        padding: 25px;
        margin-bottom: 25px;
        border-radius: 0;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        color: #333 !important;
    }
    
    .section:first-child {
        border-radius: 0;
    }
    
    .section:last-child {
        margin-bottom: 0;
        border-radius: 0 0 10px 10px;
    }
    
    .section h2 {
        color: #00d4ff !important;
        border-bottom: 2px solid #00d4ff;
        padding-bottom: 10px;
        margin-top: 0;
    }
    
    .section h3 {
        color: #302b63 !important;
        margin-top: 25px;
    }
    
    .section p {
        color: #333 !important;
    }
    
    .section ul, .section ol {
        color: #333 !important;
    }
    
    .section li {
        color: #333 !important;
    }
    
    .step {
        background: #f8f9fa;
        border-left: 4px solid #00d4ff;
        padding: 15px;
        margin: 15px 0;
        border-radius: 0 5px 5px 0;
        color: #333 !important;
    }
    
    .step strong {
        color: #333 !important;
    }
    
    .step-number {
        background: #00d4ff;
        color: white !important;
        width: 25px;
        height: 25px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        margin-right: 10px;
    }
    
    .code-block {
        background: #2d3748;
        color: #e2e8f0 !important;
        padding: 15px;
        border-radius: 5px;
        font-family: 'Courier New', monospace;
        overflow-x: auto;
        margin: 15px 0;
    }
    
    .highlight {
        background: #fff3cd;
        border: 1px solid #ffeaa7;
        padding: 10px;
        border-radius: 5px;
        margin: 15px 0;
        color: #333 !important;
    }
    
    .highlight strong {
        color: #333 !important;
    }
    
    .highlight ul {
        color: #333 !important;
    }
    
    .highlight li {
        color: #333 !important;
    }
    
    .warning {
        background: #f8d7da;
        border: 1px solid #f5c6cb;
        color: #721c24 !important;
        padding: 10px;
        border-radius: 5px;
        margin: 15px 0;
    }
    
    .warning strong {
        color: #721c24 !important;
    }
    
    .warning ul {
        color: #721c24 !important;
    }
    
    .warning li {
        color: #721c24 !important;
    }
    
    .success {
        background: #d4edda;
        border: 1px solid #c3e6cb;
        color: #155724 !important;
        padding: 10px;
        border-radius: 5px;
        margin: 15px 0;
    }
    
    .success strong {
        color: #155724 !important;
    }
    
    .success ul {
        color: #155724 !important;
    }
    
    .success li {
        color: #155724 !important;
    }
    
    .attribute-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 15px;
        margin: 20px 0;
    }
    
    .attribute-card {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 15px;
        color: #333 !important;
    }
    
    .attribute-card h4 {
        color: #00d4ff !important;
        margin-top: 0;
    }
    
    .attribute-card p {
        color: #333 !important;
    }
    
    .attribute-card ul {
        color: #333 !important;
    }
    
    .attribute-card li {
        color: #333 !important;
    }
    
    .screenshot-placeholder {
        background: #e9ecef;
        border: 2px dashed #6c757d;
        padding: 40px;
        text-align: center;
        border-radius: 8px;
        margin: 20px 0;
        color: #6c757d;
    }
    
    .form-example {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 20px;
        margin: 20px 0;
        color: #333 !important;
    }
    
    .form-example label {
        display: block;
        margin: 10px 0 5px 0;
        font-weight: bold;
        color: #333 !important;
    }
    
    .form-example strong {
        color: #333 !important;
    }
    
    .form-example select {
        width: 100%;
        padding: 8px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        margin-bottom: 15px;
    }
    
    .form-example button {
        background: #00d4ff;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 5px;
        cursor: pointer;
    }
    
    .form-example button:hover {
        background: #00b8e6;
    }
    
    .vote-options {
        display: flex;
        gap: 10px;
        margin: 10px 0;
    }
    
    .vote-option {
        background: #e9ecef;
        border: 1px solid #ced4da;
        padding: 8px 12px;
        border-radius: 4px;
        text-align: center;
        min-width: 60px;
    }
    
    .vote-option.selected {
        background: #00d4ff;
        color: white;
        border-color: #00d4ff;
    }
    
    .toc {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 20px;
        margin: 20px 0;
        color: #333 !important;
    }
    
    .toc h3 {
        margin-top: 0;
        color: #00d4ff !important;
    }
    
    .toc ul {
        list-style-type: none;
        padding-left: 0;
        color: #333 !important;
    }
    
    .toc li {
        margin: 8px 0;
        padding-left: 20px;
        position: relative;
        color: #333 !important;
    }
    
    .toc li:before {
        content: "→";
        position: absolute;
        left: 0;
        color: #00d4ff !important;
    }
    
    .toc a {
        color: #333 !important;
        text-decoration: none;
    }
    
    .toc a:hover {
        color: #00d4ff !important;
    }
    
    .guide-image {
        max-width: 100%;
        height: auto;
        border-radius: 5px;
        cursor: pointer;
        transition: transform 0.2s;
    }
    
    .guide-image:hover {
        transform: scale(1.02);
    }
    
    @media (max-width: 768px) {
        .guide-header h1 {
            font-size: 2em;
        }
        
        .attribute-grid {
            grid-template-columns: 1fr;
        }
        
        .vote-options {
            flex-direction: column;
        }
    }
</style>

<div class="voting-guide">
    <div class="guide-header">
        <h1>FSL Player Attributes</h1>
        <p>Complete Guide to the Spider Chart Review System</p>
        <p>Franchise Star League - Player Performance Assessment</p>
    </div>

    <div class="guide-content">
        <div class="section">
            <div class="toc">
                <h3>Table of Contents</h3>
                <ul>
                    <li><a href="#overview">1. System Overview</a></li>
                    <li><a href="#data-science-method">2. Why We Use the "Data Science" Method</a></li>
                    <li><a href="#getting-started">3. Getting Started</a></li>
                    <li><a href="#voting-process">4. The Voting Process</a></li>
                    <li><a href="#attributes">5. Understanding Attributes</a></li>
                    <li><a href="#scoring-system">6. Scoring System</a></li>
                    <li><a href="#examples">7. Voting Examples</a></li>
                    <li><a href="#best-practices">8. Best Practices</a></li>
                    <li><a href="#troubleshooting">9. Troubleshooting</a></li>
                </ul>
            </div>
        </div>

        <div class="section" id="overview">
            <h2>1. System Overview</h2>
            
            <p>The FSL Player Attributes Voting System is a peer-reviewed assessment tool that allows qualified reviewers to evaluate player performance across six core attributes. This system creates comprehensive spider charts that visualize player strengths and weaknesses based on actual match performance.</p>
            
            <div class="highlight">
                <strong>Key Features:</strong>
                <ul>
                    <li><strong>Secure Access:</strong> Each reviewer receives a unique URL token</li>
                    <li><strong>Weighted Voting:</strong> Different reviewers may have different vote weights</li>
                    <li><strong>Match-Based Assessment:</strong> Votes are tied to specific matches</li>
                    <li><strong>Six Core Attributes:</strong> Comprehensive player evaluation</li>
                    <li><strong>Progress Tracking:</strong> See which matches you've already voted on</li>
                    <li><strong>Public Spider Charts:</strong> View results in our public spider chart viewer</li>
                    <li><strong>5-10 Scoring Scale:</strong> Balanced scoring system with no zero scores</li>
                </ul>
            </div>
            
            <h3>How It Works</h3>
            <ol>
                <li>Administrators create reviewer accounts and assign unique tokens</li>
                <li>Reviewers receive their personal voting URL</li>
                <li>Reviewers watch matches and score players on six attributes</li>
                <li>Scores are aggregated and weighted to create spider charts</li>
                <li>Results are displayed in interactive visualizations</li>
                <li>Public spider chart viewer allows anyone to explore the data</li>
            </ol>
            
            <h3>Accessing Results</h3>
            <p>Once voting is complete and scores are aggregated, you can view the results in several ways:</p>
            
            <div class="attribute-card">
                <h4>Public Spider Chart Viewer</h4>
                <p>Visit the <strong>"Spider Charts"</strong> link in the main FSL menu to access our public spider chart viewer. This tool is available to everyone and provides:</p>
                <ul>
                    <li>Player search with autocomplete</li>
                    <li>Individual spider charts with detailed statistics</li>
                    <li>Division-based player rankings</li>
                    <li>Voting activity and reviewer information</li>
                </ul>
            </div>
            
            <div class="attribute-card">
                <h4>Admin Dashboard</h4>
                <p>Administrators and authorized users can access the spider chart admin dashboard for:</p>
                <ul>
                    <li>Managing reviewers and permissions</li>
                    <li>Viewing voting activity and statistics</li>
                    <li>Running score aggregation scripts</li>
                    <li>Analyzing player performance data</li>
                </ul>
            </div>
        </div>

        <div class="section" id="data-science-method">
            <h2>2. Why We Use the "Data Science" Method of Voting</h2>
            
            <p>To get the clearest picture of a player's strength going into a tournament, we use what we call the Data Science Method of voting: breaking things down match by match, and even attribute by attribute — and letting voters choose Player A, Player B, or "Tie/Unsure" for each item. This method might seem more detailed than a single yes/no vote, but it's exactly this structure that gives us accuracy, fairness, and insight.</p>
            
            <h3>Why It Works</h3>
            
            <div class="attribute-card">
                <h4>Precision Through Simplicity</h4>
                <p>Instead of forcing a big judgment call ("Who's better overall?"), this method lets us focus on one matchup or one skill area at a time. It's much easier to say, for example, "Player A has better ZvZ" than to rank them in general.</p>
            </div>
            
            <div class="attribute-card">
                <h4>Reduces Bias</h4>
                <p>We avoid the usual traps of favoritism or reputation. Voters don't have to commit to one player being "the best" overall — just give their honest input in small, manageable pieces.</p>
            </div>
            
            <div class="attribute-card">
                <h4>"Tie/Unsure" is a Strength, Not a Weakness</h4>
                <p>Not sure who's better in PvT? That's okay! Selecting "Tie/Unsure" tells us that this area isn't clear — and that uncertainty is valuable data. It means we're not forcing guesses, which makes the final picture more honest.</p>
            </div>
            
            <h3>The Power of Crowds</h3>
            
            <p>As more people vote, the system gets smarter. Each voter brings a bit of signal — and when aggregated across many matchups and categories, patterns emerge that no single voter could produce alone. We don't need everyone to be an expert on every player. We just need people to focus on what they do know.</p>
            
            <p>In data science, this is known as <strong>distributed judgment</strong>. When many people independently answer smaller questions, the overall result often outperforms expert consensus or single-score ratings.</p>
            
            <div class="success">
                <strong>Summary</strong>
                <ul>
                    <li>This method breaks down complexity into bite-sized decisions.</li>
                    <li>It gives us richer and more balanced insight than broad rankings.</li>
                    <li>It improves with every new voter, even if they don't vote on everything.</li>
                    <li>It respects uncertainty, rather than guessing through it.</li>
                </ul>
                <p>This isn't just a voting system — it's a community-powered scouting report. And the more people contribute, the stronger and clearer the picture becomes.</p>
            </div>
        </div>

        <div class="section" id="getting-started">
            <h2>3. Getting Started</h2>
            
            <div class="step">
                <span class="step-number">1</span>
                <strong>Receive Your Voting URL</strong><br>
                An administrator will provide you with a unique voting URL that looks like this:
                <div class="code-block">
                    https://yourdomain.com/score_match.php?token=abc123def456ghi789
                </div>
            </div>
            
            <div class="step">
                <span class="step-number">2</span>
                <strong>Access the Voting Interface</strong><br>
                Click on your unique URL to access the voting interface. You'll see a list of matches available for voting.
                <div class="highlight">
                    <strong>Voting Interface Features:</strong>
                    <ul>
                        <li><strong>Single Match Mode:</strong> Focus on one match at a time with auto-advance</li>
                        <li><strong>Radio Button Selection:</strong> Easy-to-use radio buttons instead of dropdowns</li>
                        <li><strong>Progress Tracking:</strong> See which matches you've completed</li>
                        <li><strong>Match Details:</strong> Click player names to view profiles and match information</li>
                        <li><strong>Player Intros:</strong> Watch player introduction videos for context</li>
                    </ul>
                </div>
            </div>
            
            <div class="step">
                <span class="step-number">3</span>
                <strong>Select a Match</strong><br>
                Choose a match you want to review. Each match will show:
                <ul>
                    <li>Player names and races</li>
                    <li>Match details and VOD link</li>
                    <li>Voting progress (if you've already voted)</li>
                </ul>
            </div>
            
            <div class="screenshot-placeholder">
                <img src="docs/FSL_player_attributes_pic1.png" alt="Voting interface showing match list with player names, races, and voting status" class="guide-image" onclick="window.open('docs/FSL_player_attributes_pic1.png', '_blank')" title="Click to view full size">
            </div>
        </div>

        <div class="section" id="voting-process">
            <h2>4. The Voting Process</h2>
            
            <h3>Step-by-Step Voting</h3>
            
            <div class="step">
                <span class="step-number">1</span>
                <strong>Watch the Match</strong><br>
                Click on the VOD link to watch the full match. Take notes on player performance across all six attributes.
            </div>
            
            <div class="step">
                <span class="step-number">2</span>
                <strong>Access the Voting Form</strong><br>
                After watching the match, return to the voting interface and click "Vote on This Match" for the selected match.
            </div>
            
            <div class="step">
                <span class="step-number">3</span>
                <strong>Score Each Attribute</strong><br>
                For each of the six attributes, select one of three options:
                <div class="vote-options">
                    <div class="vote-option">0</div>
                    <div class="vote-option">1</div>
                    <div class="vote-option">2</div>
                </div>
                <ul>
                    <li><strong>0 = Tie:</strong> Both players performed equally well in this attribute</li>
                    <li><strong>1 = Player 1:</strong> Player 1 performed better in this attribute</li>
                    <li><strong>2 = Player 2:</strong> Player 2 performed better in this attribute</li>
                </ul>
            </div>
            
            <div class="step">
                <span class="step-number">4</span>
                <strong>Submit Your Votes</strong><br>
                Review all six attribute scores and click "Submit Votes" to save your assessment.
            </div>
            
            <div class="screenshot-placeholder">
                <img src="docs/FSL_player_attributes_pic2.png" alt="Voting form showing six attribute dropdowns with 0/1/2 options and submit button" class="guide-image" onclick="window.open('docs/FSL_player_attributes_pic2.png', '_blank')" title="Click to view full size">
            </div>
            
            <div class="warning">
                <strong>Important:</strong> You can only vote once per attribute per match. If you've already voted on a match, those attributes will be disabled and marked as "✓ Voted".
            </div>
        </div>

        <div class="section" id="attributes">
            <h2>5. Understanding Attributes</h2>
            
            <p>The FSL Spider Chart System evaluates players across six core attributes that capture different aspects of StarCraft II gameplay:</p>
            
            <div class="attribute-grid">
                <div class="attribute-card">
                    <h4>Micro</h4>
                    <p><strong>Definition:</strong> Fine unit control in combat situations</p>
                    <p><strong>Examples:</strong></p>
                    <ul>
                        <li>Marine splitting against banelings</li>
                        <li>Spell usage (EMP, storm, fungal)</li>
                        <li>Unit positioning and kiting</li>
                        <li>Worker micro during harassment</li>
                    </ul>
                </div>
                
                <div class="attribute-card">
                    <h4>Macro</h4>
                    <p><strong>Definition:</strong> Resource management and production efficiency</p>
                    <p><strong>Examples:</strong></p>
                    <ul>
                        <li>Worker production and saturation</li>
                        <li>Base expansion timing</li>
                        <li>Supply management</li>
                        <li>Production queue management</li>
                    </ul>
                </div>
                
                <div class="attribute-card">
                    <h4>Clutch</h4>
                    <p><strong>Definition:</strong> Performance under pressure and in pivotal moments</p>
                    <p><strong>Examples:</strong></p>
                    <ul>
                        <li>Game-winning decisions</li>
                        <li>Performance when behind</li>
                        <li>Critical engagement execution</li>
                        <li>Comeback scenarios</li>
                    </ul>
                </div>
                
                <div class="attribute-card">
                    <h4>Creativity</h4>
                    <p><strong>Definition:</strong> Off-meta builds and unexpected strategies</p>
                    <p><strong>Examples:</strong></p>
                    <ul>
                        <li>Unconventional build orders</li>
                        <li>Surprise unit compositions</li>
                        <li>Innovative timing attacks</li>
                        <li>Creative map-specific strategies</li>
                    </ul>
                </div>
                
                <div class="attribute-card">
                    <h4>Aggression</h4>
                    <p><strong>Definition:</strong> Proactive attacking style and constant pressure</p>
                    <p><strong>Examples:</strong></p>
                    <ul>
                        <li>Early game harassment</li>
                        <li>Constant map presence</li>
                        <li>Multi-pronged attacks</li>
                        <li>Denying opponent expansions</li>
                    </ul>
                </div>
                
                <div class="attribute-card">
                    <h4>Strategy</h4>
                    <p><strong>Definition:</strong> Build order planning and adaptation</p>
                    <p><strong>Examples:</strong></p>
                    <ul>
                        <li>Build order selection</li>
                        <li>Scouting and adaptation</li>
                        <li>Tech path decisions</li>
                        <li>Map-specific strategies</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="section" id="scoring-system">
            <h2>6. Scoring System</h2>
            
            <h3>Voting Scale</h3>
            <p>For each attribute, you must choose one of three options:</p>
            
            <div class="form-example">
                <label>Micro Performance:</label>
                <select>
                    <option value="">Select...</option>
                    <option value="0">0 - Tie (Both players equal)</option>
                    <option value="1">1 - Player 1 better</option>
                    <option value="2">2 - Player 2 better</option>
                </select>
            </div>
            
            <h3>Score Calculation</h3>
            <p>Your votes are processed through the following system:</p>
            
            <ol>
                <li><strong>Raw Votes:</strong> Your 0/1/2 votes are collected</li>
                <li><strong>Weighted Calculation:</strong> Votes are multiplied by your reviewer weight</li>
                <li><strong>Aggregation:</strong> All reviewer votes for a player are combined</li>
                <li><strong>Normalization:</strong> Final scores are converted to a 5-10 scale using the formula: (original_score / 2) + 5</li>
            </ol>
            
            <div class="highlight">
                <strong>Example Calculation:</strong><br>
                If you vote "1" (Player 1 better) for Micro with a weight of 1.5, your contribution to Player 1's Micro score would be 1.5 points. After aggregation and normalization, this becomes part of the final 5-10 scale score.
            </div>
            
            <h3>Final Spider Chart</h3>
            <p>The final spider chart shows each player's scores across all six attributes on a 5-10 scale, creating a visual representation of their strengths and weaknesses. The 5-10 scale ensures that no player receives a score of 0, providing a more balanced and meaningful comparison.</p>
            
            <div class="screenshot-placeholder">
                <img src="docs/FSL_player_attributes_pic3.png" alt="Sample spider chart showing player attributes as a radar chart with 5-10 scale" class="guide-image" onclick="window.open('docs/FSL_player_attributes_pic3.png', '_blank')" title="Click to view full size">
            </div>

            <h3>Understanding Your Scores - Common Misconceptions</h3>
            
            <div class="warning">
                <strong>Important: What Your 10/10 Score Really Means</strong>
                <p>Many players misunderstand what their spider chart scores represent. Here's a common example:</p>
            </div>
            
            <div class="form-example">
                <strong>Q: I got 10/10 on macro - am I a perfect macro player?</strong><br><br>
                
                <strong>A:</strong> You got 10/10 macro because reviewers watched your games and said "Player A's macro was better than Player B's macro." That's it. You didn't demonstrate perfect macro - you just had better macro than your opponent in those specific games.
                <br><br>
                <strong>10/10 = "You win 100% of your macro comparisons"</strong> (not "You have perfect macro")
                <br><br>
                This could be based on:
                <ul>
                    <li>1 reviewer comparing you vs 1 opponent in 1 game</li>
                    <li>4 reviewers comparing you vs 1 opponent in 1 game</li>
                    <li>Multiple reviewers across multiple games</li>
                </ul>
                All scenarios can result in 10/10, but they represent very different levels of evidence.
            </div>
            
            <div class="highlight">
                <strong>Key Takeaway:</strong> Your spider chart shows your <strong>"comparison win rate"</strong>, not your <strong>"absolute skill level"</strong>. A 10/10 score means you consistently perform better than your opponents in that attribute, relative to the matches that were reviewed.
            </div>
            
            <h3>Viewing Your Spider Chart</h3>
            <p>You can view your spider chart and compare with other players using our public spider chart viewer:</p>
            
            <div class="success">
                <strong>Public Spider Chart Viewer:</strong><br>
                Visit the <strong>"Spider Charts"</strong> link in the main FSL menu to access the public spider chart viewer. This tool allows anyone to:
                <ul>
                    <li>Search for any player by name (autocomplete after 3 characters)</li>
                    <li>View individual player spider charts with detailed statistics</li>
                    <li>See voting data including total votes, matches voted on, and unique reviewers</li>
                    <li>Compare players across different divisions</li>
                    <li>Browse top players by division rankings</li>
                    <li>Access full player profiles and match details</li>
                </ul>
                <p><strong>No login required!</strong> The spider chart viewer is completely public and accessible to everyone.</p>
            </div>
        </div>

        <div class="section" id="examples">
            <h2>7. Voting Examples</h2>
            
            <h3>Example 1: Balanced Match</h3>
            <p><strong>Scenario:</strong> A close macro game where both players show similar skill levels</p>
            
            <div class="form-example">
                <strong>Sample Votes:</strong><br>
                <label>Micro:</label>
                <select>
                    <option value="0" selected>0 - Tie (Both players equal)</option>
                </select>
                
                <label>Macro:</label>
                <select>
                    <option value="0" selected>0 - Tie (Both players equal)</option>
                </select>
                
                <label>Clutch:</label>
                <select>
                    <option value="1">1 - Player 1 better (slightly better late game)</option>
                </select>
                
                <label>Creativity:</label>
                <select>
                    <option value="0" selected>0 - Tie (Both players equal)</option>
                </select>
                
                <label>Aggression:</label>
                <select>
                    <option value="2">2 - Player 2 better (more aggressive style)</option>
                </select>
                
                <label>Strategy:</label>
                <select>
                    <option value="0" selected>0 - Tie (Both players equal)</option>
                </select>
            </div>
            
            <h3>Example 2: One-Sided Match</h3>
            <p><strong>Scenario:</strong> Player 1 dominates with superior micro and strategy</p>
            
            <div class="form-example">
                <strong>Sample Votes:</strong><br>
                <label>Micro:</label>
                <select>
                    <option value="1" selected>1 - Player 1 better (excellent unit control)</option>
                </select>
                
                <label>Macro:</label>
                <select>
                    <option value="1" selected>1 - Player 1 better (better economy)</option>
                </select>
                
                <label>Clutch:</label>
                <select>
                    <option value="1" selected>1 - Player 1 better (handled pressure well)</option>
                </select>
                
                <label>Creativity:</label>
                <select>
                    <option value="0" selected>0 - Tie (Both players equal)</option>
                </select>
                
                <label>Aggression:</label>
                <select>
                    <option value="1" selected>1 - Player 1 better (controlled aggression)</option>
                </select>
                
                <label>Strategy:</label>
                <select>
                    <option value="1" selected>1 - Player 1 better (superior build order)</option>
                </select>
            </div>
        </div>

        <div class="section" id="best-practices">
            <h2>8. Best Practices</h2>
            
            <h3>Before Voting</h3>
            <ul>
                <li><strong>Watch the Full Match:</strong> Don't vote based on highlights or partial viewing</li>
                <li><strong>Take Notes:</strong> Jot down key moments for each attribute</li>
                <li><strong>Consider Context:</strong> Account for match-up, map, and game state</li>
                <li><strong>Be Objective:</strong> Focus on performance, not personal preferences</li>
            </ul>
            
            <h3>During Voting</h3>
            <ul>
                <li><strong>Evaluate Each Attribute Independently:</strong> Don't let one attribute influence others</li>
                <li><strong>Use the Full Scale:</strong> Don't default to ties - make clear distinctions</li>
                <li><strong>Consider the Match as a Whole:</strong> Look at overall performance patterns</li>
                <li><strong>Be Consistent:</strong> Apply the same standards across all matches</li>
            </ul>
            
            <h3>Common Mistakes to Avoid</h3>
            <div class="warning">
                <ul>
                    <li><strong>Voting Based on Results:</strong> Don't just vote for the winner</li>
                    <li><strong>Ignoring Context:</strong> Consider the match-up and map</li>
                    <li><strong>Rushing:</strong> Take time to properly evaluate each attribute</li>
                    <li><strong>Bias:</strong> Avoid favoritism toward specific players or playstyles</li>
                </ul>
            </div>
            
            <h3>Quality Assurance</h3>
            <div class="success">
                <ul>
                    <li><strong>Review Your Votes:</strong> Double-check before submitting</li>
                    <li><strong>Be Honest:</strong> If you're unsure about an attribute, consider it a tie</li>
                    <li><strong>Stay Consistent:</strong> Apply the same evaluation criteria</li>
                    <li><strong>Focus on Performance:</strong> Evaluate what happened, not what could have happened</li>
                </ul>
            </div>
        </div>

        <div class="section" id="troubleshooting">
            <h2>9. Troubleshooting</h2>
            
            <h3>Common Issues</h3>
            
            <div class="step">
                <strong>Issue: "Invalid or inactive reviewer token"</strong><br>
                <strong>Solution:</strong> Contact an administrator to verify your token is active and correct.
            </div>
            
            <div class="step">
                <strong>Issue: Can't access voting form</strong><br>
                <strong>Solution:</strong> Make sure you're using the complete URL including the token parameter.
            </div>
            
            <div class="step">
                <strong>Issue: Attributes are disabled/grayed out</strong><br>
                <strong>Solution:</strong> You've already voted on those attributes for this match. You can only vote once per attribute per match.
            </div>
            
            <div class="step">
                <strong>Issue: "You have already voted on this match"</strong><br>
                <strong>Solution:</strong> Each reviewer can only vote once per match. Check if you've already submitted votes for this match.
            </div>
            
            <div class="step">
                <strong>Issue: VOD link doesn't work</strong><br>
                <strong>Solution:</strong> Contact an administrator to verify the VOD link is correct and accessible.
            </div>
            
            <h3>Getting Help</h3>
            <p>If you encounter any issues not covered above:</p>
            <ul>
                <li>Contact the FSL administration team</li>
                <li>Provide your reviewer token and the specific error message</li>
                <li>Include screenshots if possible</li>
                <li>Describe what you were trying to do when the issue occurred</li>
            </ul>
            
            <div class="highlight">
                <strong>Technical Support:</strong><br>
                For technical issues with the voting system, please contact the system administrator with your reviewer token and a detailed description of the problem.
            </div>
        </div>

        <div class="section">
            <h2>Conclusion</h2>
            
            <p>The FSL Player Attributes Voting System provides a comprehensive and fair way to evaluate player performance across multiple dimensions of StarCraft II gameplay. By following this guide and applying consistent evaluation criteria, you'll contribute to creating accurate and meaningful spider charts that help players understand their strengths and areas for improvement.</p>
            
            <div class="success">
                <strong>Remember:</strong> Your votes directly impact how players are evaluated and can influence their development and team placement. Take your role as a reviewer seriously and provide thoughtful, objective assessments.
            </div>
            
            <h3>Explore the Results</h3>
            <p>Once voting is complete and scores are aggregated, you can explore the results using our public spider chart viewer:</p>
            
            <div class="highlight">
                <strong>Public Spider Chart Viewer Features:</strong>
                <ul>
                    <li><strong>Player Search:</strong> Find any player with autocomplete search</li>
                    <li><strong>Interactive Charts:</strong> View detailed spider charts for each player</li>
                    <li><strong>Division Rankings:</strong> See top players by division</li>
                    <li><strong>Voting Statistics:</strong> View total votes, matches, and reviewer counts</li>
                    <li><strong>Player Profiles:</strong> Access full player information and match details</li>
                </ul>
                <p><strong>Access:</strong> Click "Spider Charts" in the main FSL menu - no login required!</p>
            </div>
            
            <p><strong>Thank you for contributing to the FSL community!</strong></p>
        </div>

        <div class="section" style="text-align: center; margin-top: 40px; padding: 20px; border-top: 1px solid #ddd;">
            <p><strong>FSL Player Attributes Voting Guide</strong></p>
            <p>Version 2.0 | Last Updated: January 2025</p>
            <p><strong>New in Version 2.0:</strong> Updated to 5-10 scoring scale and added public spider chart viewer</p>
            <p>Franchise Star League - Spider Chart Review System</p>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?> 