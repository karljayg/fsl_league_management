<?php
/**
 * Team Logo Helper
 * Maps team names to their logo image paths
 */

/**
 * Get the logo path for a team name
 * @param string $teamName The team name
 * @param string $size '256px' or '' for full size
 * @return string|null The logo path or null if not found
 */
function getTeamLogo($teamName, $size = '256px') {
    if (empty($teamName)) {
        return null;
    }
    
    // Map of team names to their logo file names
    $logoMap = [
        'Infinite Cyclists' => 'FSL_team_square_logo_Infinite_Cyclists',
        'Rages Raiders' => 'FSL_team_square_logo_Rages_Raiders',
        "Rage's Raiders" => 'FSL_team_square_logo_Rages_Raiders',
        'Angry Space Hares' => 'FSL_team_square_logo_Angry_Space_Hares',
        'PulledTheBoys' => 'FSL_team_square_logo_PulledTheBoys',
        'Pulled The Boys' => 'FSL_team_square_logo_PulledTheBoys',
        'CheesyNachos' => 'FSL_team_square_logo_CheesyNachos',
        'Cheesy Nachos' => 'FSL_team_square_logo_CheesyNachos',
    ];
    
    $baseName = $logoMap[$teamName] ?? null;
    
    if (!$baseName) {
        return null;
    }
    
    $suffix = $size ? "_{$size}" : '';
    $path = "images/{$baseName}{$suffix}.png";
    
    // Check if file exists (relative to document root)
    $fullPath = dirname(__DIR__) . '/' . $path;
    if (file_exists($fullPath)) {
        return $path;
    }
    
    // Try without size suffix
    $path = "images/{$baseName}.png";
    $fullPath = dirname(__DIR__) . '/' . $path;
    if (file_exists($fullPath)) {
        return $path;
    }
    
    return null;
}
