<?php
/**
 * Team Logo Helper
 * Maps team names to their logo image paths. Returns placeholder for teams without a logo.
 */

/** Path to generic "Logo Here" placeholder for teams without a logo */
define('TEAM_LOGO_PLACEHOLDER', 'images/team_logo_placeholder.svg');

/**
 * Get the logo path for a team name
 * @param string $teamName The team name
 * @param string $size '256px' or '' for full size
 * @return string|null The logo path, or placeholder path if no logo, or null if no team name
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
        'PSIOP Gaming' => 'FSL_team_square_logo_PSISOP_Gaming',
        'Special Tactics' => 'FSL_team_square_logo_SpecialTactics',
        'TBD' => null,  // Placeholder display for schedule slots with NULL team_id
    ];
    
    $baseName = $logoMap[$teamName] ?? null;
    
    if ($baseName === null) {
        $placeholderPath = dirname(__DIR__) . '/' . TEAM_LOGO_PLACEHOLDER;
        return file_exists($placeholderPath) ? TEAM_LOGO_PLACEHOLDER : null;
    }
    
    $suffix = $size ? "_{$size}" : '';
    $path = "images/{$baseName}{$suffix}.png";
    
    $fullPath = dirname(__DIR__) . '/' . $path;
    if (file_exists($fullPath)) {
        return $path;
    }
    
    $path = "images/{$baseName}.png";
    $fullPath = dirname(__DIR__) . '/' . $path;
    if (file_exists($fullPath)) {
        return $path;
    }
    
    $placeholderPath = dirname(__DIR__) . '/' . TEAM_LOGO_PLACEHOLDER;
    return file_exists($placeholderPath) ? TEAM_LOGO_PLACEHOLDER : null;
}
