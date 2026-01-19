/**
 * Sorting Utilities for FSL Website
 * Contains functions for sorting table data
 */

/**
 * Performs client-side sorting of table rows
 * @param {string} sortField - Field to sort by
 * @param {string} sortDirection - Sort direction ('asc' or 'desc')
 * @param {NodeList} sortableHeaders - Collection of sortable header elements
 */
function performClientSideSorting(sortField, sortDirection, sortableHeaders) {
    // Get all rows
    const tbody = document.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr.player-row'));
    
    // Sort rows
    rows.sort((a, b) => {
        const aValue = parseFloat(a.querySelector(`.${sortField}`).textContent);
        const bValue = parseFloat(b.querySelector(`.${sortField}`).textContent);
        
        return sortDirection === 'asc' ? aValue - bValue : bValue - aValue;
    });
    
    // Reorder rows
    rows.forEach(row => tbody.appendChild(row));
    
    // Update visual indication
    sortableHeaders.forEach(h => h.classList.remove('asc', 'desc'));
    const currentHeader = Array.from(sortableHeaders).find(h => h.getAttribute('data-sort') === sortField);
    if (currentHeader) {
        currentHeader.classList.add(sortDirection);
    }
    
    // Update URL without reloading
    updateURLWithSorting(sortField, sortDirection, true);
}

/**
 * Performs server-side sorting by redirecting to the page with sort parameters
 * @param {string} sortField - Field to sort by
 * @param {string} sortDirection - Sort direction ('asc' or 'desc')
 * @param {NodeList} sortableHeaders - Collection of sortable header elements
 */
function performServerSideSorting(sortField, sortDirection, sortableHeaders) {
    // Update URL with sort parameters
    const url = updateURLWithSorting(sortField, sortDirection);
    
    // Update visual indication before redirect
    sortableHeaders.forEach(h => h.classList.remove('asc', 'desc'));
    const currentHeader = Array.from(sortableHeaders).find(h => h.getAttribute('data-sort') === sortField);
    if (currentHeader) {
        currentHeader.classList.add(sortDirection);
    }
    
    // Redirect to the sorted URL
    window.location.href = url.toString();
}

/**
 * Initializes sorting functionality for player statistics table
 */
function initializeSorting() {
    const sortableHeaders = document.querySelectorAll('th.sortable');
    
    sortableHeaders.forEach(header => {
        header.addEventListener('click', function() {
            const sortField = this.getAttribute('data-sort');
            let sortDirection = 'asc';
            
            // If already sorted by this field, toggle direction
            if (this.classList.contains('asc')) {
                sortDirection = 'desc';
            } else if (this.classList.contains('desc')) {
                sortDirection = 'asc';
            }
            
            // For client-side sorting (if needed)
            if (sortField === 'MapWinRate' || sortField === 'SetWinRate') {
                performClientSideSorting(sortField, sortDirection, sortableHeaders);
                return; // Skip the page reload
            }
            
            // For server-side sorting
            performServerSideSorting(sortField, sortDirection, sortableHeaders);
        });
    });
} 