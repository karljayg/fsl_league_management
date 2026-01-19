<?php
session_start();

// -------------------------------------------------------
// Check permission using the same approach as edit_player_statistics.php
// -------------------------------------------------------
$required_permission = 'edit player, team, stats';
include 'includes/check_permission_updated.php';

// -------------------------------------------------------
// Include Header (contains HTML <head>, styles, etc.)
// -------------------------------------------------------
include('includes/header.php');

// -------------------------------------------------------
// Database References: load connection variables
// -------------------------------------------------------
require_once('includes/db.php'); // Load database connection

// -------------------------------------------------------
// Establish DB connection
// -------------------------------------------------------
try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
} catch (Exception $e) {
    echo "<div class='error'><strong>Connection failed:</strong> " . $e->getMessage() . "</div>";
    include('includes/footer.php');
    exit;
}

echo "<div class='content'>";
echo "<h1>FSL Statistics Update Process</h1>";

// Check if the form has been submitted
$confirmed = isset($_POST['confirm']) && $_POST['confirm'] === 'yes';

if (!$confirmed) {
    // Display explanation and confirmation form
    ?>
    <div class="explanation-box">
        <h2>Process Explanation</h2>
        <p>This script will update the FSL_STATISTICS table with cumulative statistics from all matches in the fsl_matches table.</p>
        
        <h3>The process will:</h3>
        <ol>
            <li><strong>Reset all statistics</strong> - All MapsW, MapsL, SetsW, and SetsL values will be set to zero to avoid double-counting.</li>
            <li><strong>Process each match</strong> - Each match from the fsl_matches table will be processed individually.</li>
            <li><strong>Update winner statistics</strong> - For each match, the winner's statistics will be updated with:
                <ul>
                    <li>Maps Won: Incremented by the map_win value</li>
                    <li>Maps Lost: Incremented by the map_loss value</li>
                    <li>Sets Won: Incremented by 1</li>
                </ul>
            </li>
            <li><strong>Update loser statistics</strong> - For each match, the loser's statistics will be updated with:
                <ul>
                    <li>Maps Won: Incremented by the map_loss value (maps the loser actually won)</li>
                    <li>Maps Lost: Incremented by the map_win value (maps the loser lost)</li>
                    <li>Sets Lost: Incremented by 1</li>
                </ul>
            </li>
            <li><strong>Handle divisions</strong> - The division will be determined from the t_code field in the match data.</li>
            <li><strong>Handle aliases</strong> - The appropriate Alias_ID will be used for each player.</li>
        </ol>
        
        <h3>Important Notes:</h3>
        <ul>
            <li>This process will <strong>completely rebuild</strong> all statistics from the match data.</li>
            <li>Any manual adjustments to statistics will be lost.</li>
            <li>This process may take some time depending on the number of matches.</li>
        </ul>
        
        <form method="post" onsubmit="return confirm('Are you sure you want to proceed with the statistics update?');">
            <input type="hidden" name="confirm" value="yes">
            <button type="submit" class="btn btn-primary">Proceed with Update</button>
            <a href="index.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
    <style>
        .explanation-box {
            background-color: #000000;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .explanation-box h3 {
            margin-top: 15px;
        }
        .explanation-box ul, .explanation-box ol {
            margin-bottom: 15px;
        }
        .btn {
            display: inline-block;
            font-weight: 400;
            text-align: center;
            white-space: nowrap;
            vertical-align: middle;
            user-select: none;
            border: 1px solid transparent;
            padding: .375rem .75rem;
            font-size: 1rem;
            line-height: 1.5;
            border-radius: .25rem;
            transition: color .15s ease-in-out,background-color .15s ease-in-out,border-color .15s ease-in-out,box-shadow .15s ease-in-out;
            margin-right: 10px;
        }
        .btn-primary {
            color: #fff;
            background-color: #007bff;
            border-color: #007bff;
        }
        .btn-secondary {
            color: #fff;
            background-color: #6c757d;
            border-color: #6c757d;
        }
    </style>
    <?php
    // Include footer and exit
    include('includes/footer.php');
    exit;
}

// If we get here, the user has confirmed the update

// First, reset all statistics to zero to avoid double-counting
$resetQuery = "UPDATE FSL_STATISTICS SET MapsW = 0, MapsL = 0, SetsW = 0, SetsL = 0";
echo "<h2>Resetting All Statistics</h2>";
echo "<pre>" . htmlspecialchars($resetQuery) . "</pre>";

if ($conn->query($resetQuery) === TRUE) {
    echo "<p class='success'><strong>Statistics reset successful.</strong> Rows affected: " . $conn->affected_rows . "</p>";
} else {
    echo "<p class='error'><strong>Error resetting statistics:</strong> " . $conn->error . "</p>";
}

// ------------------------------------------------------------------
// 1. Get all matches and prepare for processing
// ------------------------------------------------------------------
echo "<h2>Processing Match Data</h2>";

$matchesQuery = "SELECT 
    m.fsl_match_id,
    m.season,
    m.t_code,
    m.winner_player_id,
    m.winner_race,
    m.map_win,
    m.map_loss,
    m.loser_player_id,
    m.loser_race,
    COALESCE(w_alias.Alias_ID, 
        (SELECT MIN(pa.Alias_ID) FROM Player_Aliases pa WHERE pa.Player_ID = m.winner_player_id)
    ) AS winner_alias_id,
    COALESCE(l_alias.Alias_ID, 
        (SELECT MIN(pa.Alias_ID) FROM Player_Aliases pa WHERE pa.Player_ID = m.loser_player_id)
    ) AS loser_alias_id,
    CASE 
        WHEN m.t_code LIKE '%S%' THEN 'S'
        WHEN m.t_code LIKE '%A%' THEN 'A'
        WHEN m.t_code LIKE '%B%' THEN 'B'
        WHEN m.t_code LIKE '%C%' THEN 'C'
        ELSE 'A' -- Default to 'A' if no division is found
    END AS division
FROM 
    fsl_matches m
LEFT JOIN 
    Player_Aliases w_alias ON m.winner_player_id = w_alias.Player_ID
LEFT JOIN 
    Player_Aliases l_alias ON m.loser_player_id = l_alias.Player_ID";

$matches = $conn->query($matchesQuery);

if (!$matches) {
    echo "<p class='error'><strong>Error retrieving matches:</strong> " . $conn->error . "</p>";
    include('includes/footer.php');
    exit;
}

$processedCount = 0;
$errorCount = 0;

// Process each match and update statistics
while ($match = $matches->fetch_assoc()) {
    // Process winner statistics
    $updateWinnerQuery = "INSERT INTO FSL_STATISTICS 
        (Player_ID, Alias_ID, Division, Race, MapsW, MapsL, SetsW, SetsL) 
    VALUES 
        (?, ?, ?, ?, ?, ?, 1, 0)
    ON DUPLICATE KEY UPDATE 
        MapsW = MapsW + ?, 
        MapsL = MapsL + ?, 
        SetsW = SetsW + 1";
    
    $winnerStmt = $conn->prepare($updateWinnerQuery);
    $winnerStmt->bind_param(
        "iissiiii", 
        $match['winner_player_id'], 
        $match['winner_alias_id'], 
        $match['division'], 
        $match['winner_race'], 
        $match['map_win'], 
        $match['map_loss'],
        $match['map_win'],
        $match['map_loss']
    );
    
    if (!$winnerStmt->execute()) {
        echo "<p class='error'><strong>Error updating winner statistics for match {$match['fsl_match_id']}:</strong> " . $winnerStmt->error . "</p>";
        $errorCount++;
    }
    $winnerStmt->close();
    
    // Process loser statistics
    $updateLoserQuery = "INSERT INTO FSL_STATISTICS 
        (Player_ID, Alias_ID, Division, Race, MapsW, MapsL, SetsW, SetsL) 
    VALUES 
        (?, ?, ?, ?, ?, ?, 0, 1)
    ON DUPLICATE KEY UPDATE 
        MapsW = MapsW + ?, 
        MapsL = MapsL + ?, 
        SetsL = SetsL + 1";
    
    $loserStmt = $conn->prepare($updateLoserQuery);
    $loserStmt->bind_param(
        "iissiiii", 
        $match['loser_player_id'], 
        $match['loser_alias_id'], 
        $match['division'], 
        $match['loser_race'], 
        $match['map_loss'], 
        $match['map_win'],
        $match['map_loss'],
        $match['map_win']
    );
    
    if (!$loserStmt->execute()) {
        echo "<p class='error'><strong>Error updating loser statistics for match {$match['fsl_match_id']}:</strong> " . $loserStmt->error . "</p>";
        $errorCount++;
    }
    $loserStmt->close();
    
    $processedCount++;
}

echo "<p class='info'>Processed $processedCount matches with $errorCount errors.</p>";

// -------------------------------------------------------
// Close connection and complete
// -------------------------------------------------------
$conn->close();
echo "<h3>Update process complete.</h3>";
echo "<p><a href='index.php' class='btn btn-primary'>Return to Home</a></p>";
echo "</div>";

// -------------------------------------------------------
// Include Footer (contains closing HTML tags, etc.)
// -------------------------------------------------------
include('includes/footer.php');
?>
