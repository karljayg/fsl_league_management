/**
 * Filter Utilities for FSL Website
 * Contains functions for filtering table data
 */

/**
 * Debug function to check data attributes on rows
 * @param {NodeList} playerRows - Collection of player row elements
 */
function logRowAttributes(playerRows) {
    console.log("Checking row attributes:");
    playerRows.forEach((row, index) => {
        console.log(`Row ${index}:`, {
            division: row.dataset.division,
            race: row.dataset.race,
            team: row.dataset.team
        });
    });
}

/**
 * Applies filters to player rows based on selected criteria
 * @param {HTMLElement} divisionFilter - Division filter select element
 * @param {HTMLElement} raceFilter - Race filter select element
 * @param {HTMLElement} teamFilter - Team filter select element
 * @param {HTMLElement} playerSearch - Player search input element
 * @param {NodeList} playerRows - Collection of player row elements
 */
function applyFilters(divisionFilter, raceFilter, teamFilter, playerSearch, playerRows) {
    const divisionValue = divisionFilter.value;
    const raceValue = raceFilter.value;
    const teamValue = teamFilter.value;
    const searchValue = playerSearch.value.toLowerCase();
    
    console.log("Applying filters:", {
        division: divisionValue,
        race: raceValue,
        team: teamValue,
        search: searchValue
    });
    
    playerRows.forEach(row => {
        const playerName = row.querySelector('td:first-child').textContent.toLowerCase();
        const aliasName = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
        const division = row.dataset.division;
        const race = row.dataset.race;
        const team = row.dataset.team;
        
        // Check if race cell contains the selected race text
        let raceCell = row.querySelector('td:nth-child(4)');
        let raceText = raceCell ? raceCell.textContent.trim() : '';
        
        // Check if team cell contains the selected team text
        let teamCell = row.querySelector('td:nth-child(9)');
        let teamText = teamCell ? teamCell.textContent.trim() : '';
        
        const divisionMatch = divisionValue === 'all' || division === divisionValue;
        const raceMatch = raceValue === 'all' || 
                         race === raceValue || 
                         raceText.includes(raceValue);
        const teamMatch = teamValue === 'all' || 
                         team === teamValue || 
                         teamText.includes(teamValue);
        const searchMatch = searchValue === '' || 
                           playerName.includes(searchValue) || 
                           aliasName.includes(searchValue);
        
        if (divisionMatch && raceMatch && teamMatch && searchMatch) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

/**
 * Clears all filters and redirects to the page with only sort parameters
 * @param {HTMLElement} divisionFilter - Division filter select element
 * @param {HTMLElement} raceFilter - Race filter select element
 * @param {HTMLElement} teamFilter - Team filter select element
 * @param {HTMLElement} playerSearch - Player search input element
 */
function clearFilters(divisionFilter, raceFilter, teamFilter, playerSearch) {
    divisionFilter.value = 'all';
    raceFilter.value = 'all';
    teamFilter.value = 'all';
    playerSearch.value = '';
    
    // Get URL with only sort parameters preserved
    const url = preserveSortParameters();
    
    // Redirect to the filtered URL
    window.location.href = url.toString();
}

/**
 * Initializes filter functionality for player statistics table
 */
function initializeFilters() {
    const divisionFilter = document.getElementById('division-filter');
    const raceFilter = document.getElementById('race-filter');
    const teamFilter = document.getElementById('team-filter');
    const playerSearch = document.getElementById('player-search');
    const clearFiltersBtn = document.getElementById('clear-filters');
    const playerRows = document.querySelectorAll('.player-row');
    
    // Debug check of row attributes
    logRowAttributes(playerRows);
    
    // Define filter application function with specific elements
    const applyFiltersWithElements = () => {
        applyFilters(divisionFilter, raceFilter, teamFilter, playerSearch, playerRows);
        updateURLWithFilters(playerSearch, divisionFilter, raceFilter, teamFilter);
    };
    
    // Define clear filters function with specific elements
    const clearFiltersWithElements = () => {
        clearFilters(divisionFilter, raceFilter, teamFilter, playerSearch);
    };
    
    // Add event listeners
    divisionFilter.addEventListener('change', applyFiltersWithElements);
    raceFilter.addEventListener('change', applyFiltersWithElements);
    teamFilter.addEventListener('change', applyFiltersWithElements);
    playerSearch.addEventListener('input', applyFiltersWithElements);
    clearFiltersBtn.addEventListener('click', clearFiltersWithElements);
    
    // Initial filter application
    applyFiltersWithElements();
} 