<?php
/**
 * Formatting Utility Functions
 * Contains helper functions for formatting data for display
 */

/**
 * Format championship record for display
 * 
 * @param string $jsonData JSON string containing championship data
 * @return string Formatted HTML for championship record
 */
function formatChampionshipRecord($jsonData) {
    if (empty($jsonData)) {
        return '';
    }
    
    return processChampionshipJSON($jsonData, 2);
}

/**
 * Format team championship record for display
 * 
 * @param string $jsonData JSON string containing team championship data
 * @return string Formatted HTML for team championship record
 */
function formatTeamChampionshipRecord($jsonData) {
    if (empty($jsonData)) {
        return '';
    }
    
    return processChampionshipJSON($jsonData, 2);
}

/**
 * Format teams history for display
 * 
 * @param string $teamsHistory String containing teams history
 * @return string Formatted HTML for teams history
 */
function formatTeamsHistory($teamsHistory) {
    if (empty($teamsHistory)) {
        return '';
    }
    
    $teams = explode(',', $teamsHistory);
    $formattedTeams = [];
    
    foreach ($teams as $team) {
        $team = trim($team);
        if (!empty($team)) {
            $formattedTeams[] = '<div class="team-history-item">' . htmlspecialchars($team) . '</div>';
        }
    }
    
    return implode('', $formattedTeams);
}

/**
 * Gets the full race name from the race code
 * 
 * @param string $raceCode Single character race code (T, P, Z, R)
 * @return string Full race name
 */
function getRaceNameFromCode($raceCode) {
    switch ($raceCode) {
        case 'T':
            return 'Terran';
        case 'P':
            return 'Protoss';
        case 'Z':
            return 'Zerg';
        case 'R':
            return 'Random';
        default:
            return $raceCode;
    }
}

/**
 * Gets the race icon path from the race code
 * 
 * @param string $raceCode Single character race code (T, P, Z, R)
 * @return string Path to race icon image
 */
function getRaceIconFromCode($raceCode) {
    switch ($raceCode) {
        case 'T':
            return 'images/terran_icon.png';
        case 'P':
            return 'images/protoss_icon.png';
        case 'Z':
            return 'images/zerg_icon.png';
        case 'R':
            return 'images/random_icon.png';
        default:
            return '';
    }
} 