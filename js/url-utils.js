/**
 * URL Utilities for FSL Website
 * Contains functions for manipulating URL parameters
 */

/**
 * Updates the URL with filter parameters without reloading the page
 * @param {HTMLElement} playerSearch - Player search input element
 * @param {HTMLElement} divisionFilter - Division filter select element
 * @param {HTMLElement} raceFilter - Race filter select element
 * @param {HTMLElement} teamFilter - Team filter select element
 */
function updateURLWithFilters(playerSearch, divisionFilter, raceFilter, teamFilter) {
    const url = new URL(window.location);
    
    // Clear existing filter parameters
    url.searchParams.delete('player');
    url.searchParams.delete('division');
    url.searchParams.delete('race');
    url.searchParams.delete('team');
    
    // Add current filter values
    if (playerSearch.value) {
        url.searchParams.set('player', playerSearch.value);
    }
    
    if (divisionFilter.value !== 'all') {
        url.searchParams.set('division', divisionFilter.value);
    }
    
    if (raceFilter.value !== 'all') {
        url.searchParams.set('race', raceFilter.value);
    }
    
    if (teamFilter.value !== 'all') {
        url.searchParams.set('team', teamFilter.value);
    }
    
    // Preserve sort parameters
    const currentSort = url.searchParams.get('sort');
    const currentDir = url.searchParams.get('dir');
    
    if (currentSort) {
        url.searchParams.set('sort', currentSort);
    }
    
    if (currentDir) {
        url.searchParams.set('dir', currentDir);
    }
    
    // Update browser history without reloading the page
    window.history.replaceState({}, '', url.toString());
}

/**
 * Updates the URL with sorting parameters
 * @param {string} sortField - Field to sort by
 * @param {string} sortDirection - Sort direction ('asc' or 'desc')
 * @param {boolean} pushState - Whether to use pushState (true) or replace state (false)
 * @returns {URL} - The updated URL object
 */
function updateURLWithSorting(sortField, sortDirection, pushState = false) {
    const url = new URL(window.location);
    url.searchParams.set('sort', sortField);
    url.searchParams.set('dir', sortDirection);
    
    if (pushState) {
        window.history.pushState({}, '', url.toString());
    } else {
        window.history.replaceState({}, '', url.toString());
    }
    
    return url;
}

/**
 * Preserves sort parameters when clearing filters
 * @returns {URL} - URL with only sort parameters
 */
function preserveSortParameters() {
    const url = new URL(window.location);
    const currentSort = url.searchParams.get('sort');
    const currentDir = url.searchParams.get('dir');
    
    // Clear all parameters
    url.search = '';
    
    // Add back sort parameters if they exist
    if (currentSort) {
        url.searchParams.set('sort', currentSort);
    }
    
    if (currentDir) {
        url.searchParams.set('dir', currentDir);
    }
    
    return url;
} 