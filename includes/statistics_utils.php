<?php
/**
 * Statistics Utility Functions
 * Contains helper functions for formatting and calculating statistics
 */

/**
 * Determines CSS class based on win rate percentage
 * 
 * @param float $winRate Win rate percentage
 * @return string CSS class name
 */
function getWinRateClass($winRate) {
    if ($winRate >= 60) {
        return 'high-win-rate';
    } elseif ($winRate >= 45) {
        return 'medium-win-rate';
    } else {
        return 'low-win-rate';
    }
}

/**
 * Calculates win rates for maps and sets
 * 
 * @param array $playerStats Array of player statistics
 * @return array Updated player statistics with win rates
 */
function calculateWinRates($playerStats) {
    foreach ($playerStats as &$player) {
        // Calculate win rates
        $totalMaps = ($player['MapsW'] ?? 0) + ($player['MapsL'] ?? 0);
        $player['MapWinRate'] = $totalMaps > 0 ? round(($player['MapsW'] / $totalMaps) * 100, 1) : 0;
        
        $totalSets = ($player['SetsW'] ?? 0) + ($player['SetsL'] ?? 0);
        $player['SetWinRate'] = $totalSets > 0 ? round(($player['SetsW'] / $totalSets) * 100, 1) : 0;
    }
    
    return $playerStats;
}

/**
 * Sorts player statistics based on specified field and direction
 * 
 * @param array $playerStats Array of player statistics
 * @param string $sortField Field to sort by
 * @param string $sortDirection Sort direction ('asc' or 'desc')
 * @return array Sorted player statistics
 */
function sortPlayerStats($playerStats, $sortField, $sortDirection) {
    usort($playerStats, function($a, $b) use ($sortField, $sortDirection) {
        $aValue = $a[$sortField] ?? '';
        $bValue = $b[$sortField] ?? '';

        // Handle calculated fields
        if ($sortField === 'MapWinRate' || $sortField === 'SetWinRate') {
            $aValue = $a[$sortField];
            $bValue = $b[$sortField];
        }

        // Handle numeric values
        if (is_numeric($aValue) && is_numeric($bValue)) {
            $comparison = $aValue <=> $bValue;
        } else {
            // Case-insensitive string comparison
            $comparison = strcasecmp($aValue, $bValue);
        }

        // Reverse for descending order
        return $sortDirection === 'desc' ? -$comparison : $comparison;
    });
    
    return $playerStats;
} 