/**
 * Main JavaScript file for FSL Website
 * Initializes all functionality when the DOM is loaded
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize player statistics table functionality if elements exist
    if (document.getElementById('player-stats-table')) {
        initializeFilters();
        initializeSorting();
    }
    
    // Add other page initializations here as needed
}); 