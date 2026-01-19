<?php
/**
 * Player Database Queries
 * Contains functions for retrieving player data from the database
 */

/**
 * Gets player statistics with sorting
 * 
 * @param PDO $db Database connection
 * @param string $sortField Field to sort by
 * @param string $sortDirection Sort direction ('asc' or 'desc')
 * @return array Player statistics
 */
function getPlayerStatistics($db, $sortField, $sortDirection) {
    // Validate sort field
    $validSortFields = ['Player_Name', 'Alias_Name', 'Division', 'Race', 'MapsW', 'SetsW', 'Current_Team_Name'];
    if (!in_array($sortField, $validSortFields)) {
        $sortField = 'MapsW'; // Default to MapsW if invalid
    }
    
    // Validate sort direction
    $sortDirection = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';
    
    // Build and execute query
    $playerStatsQuery = "
        SELECT 
            p.Player_ID,
            p.Real_Name AS Player_Name,
            a.Alias_ID,
            a.Alias_Name,
            s.Division,
            s.Race,
            s.MapsW,
            s.MapsL,
            s.SetsW,
            s.SetsL,
            t.Team_ID AS Current_Team_ID,
            t.Team_Name AS Current_Team_Name,
            p.Championship_Record,
            p.TeamLeague_Championship_Record,
            p.Teams_History AS Past_Team_History
        FROM Players p
        LEFT JOIN Player_Aliases a ON p.Player_ID = a.Player_ID
        LEFT JOIN FSL_STATISTICS s ON p.Player_ID = s.Player_ID AND a.Alias_ID = s.Alias_ID
        LEFT JOIN Teams t ON p.Team_ID = t.Team_ID
        ORDER BY $sortField $sortDirection, p.Real_Name, t.Team_Name, s.Division, s.Race
    ";
    
    return $db->query($playerStatsQuery)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Gets unique teams from player statistics
 * 
 * @param array $playerStats Array of player statistics
 * @return array Unique team names
 */
function getUniqueTeams($playerStats) {
    $teams = [];
    foreach ($playerStats as $player) {
        if (!empty($player['Current_Team_Name']) && $player['Current_Team_Name'] !== 'None' && !in_array($player['Current_Team_Name'], $teams)) {
            $teams[] = $player['Current_Team_Name'];
        }
    }
    sort($teams);
    return $teams;
} 