<?php
/**
 * URL Utility Functions
 * Contains helper functions for handling URL parameters
 */

/**
 * Gets filter parameters from URL
 * 
 * @return array Array of filter parameters
 */
function getFilterParameters() {
    return [
        'player' => isset($_GET['player']) ? htmlspecialchars($_GET['player']) : '',
        'division' => isset($_GET['division']) ? htmlspecialchars($_GET['division']) : 'all',
        'race' => isset($_GET['race']) ? htmlspecialchars($_GET['race']) : 'all',
        'team' => isset($_GET['team']) ? htmlspecialchars($_GET['team']) : 'all'
    ];
}

/**
 * Gets sort parameters from URL
 * 
 * @return array Array of sort parameters
 */
function getSortParameters() {
    return [
        'field' => isset($_GET['sort']) ? htmlspecialchars($_GET['sort']) : 'MapsW',
        'direction' => isset($_GET['dir']) ? htmlspecialchars($_GET['dir']) : 'desc'
    ];
} 