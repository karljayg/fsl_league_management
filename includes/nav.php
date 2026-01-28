<?php
// Check if user is logged in using session
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Get username if logged in
function getUsername() {
    return isset($_SESSION['username']) ? $_SESSION['username'] : null;
}

// Get current page for navigation highlighting
function isCurrentPage($page) {
    $currentFile = basename($_SERVER['PHP_SELF']);
    return $currentFile == $page;
}

// Check if user has any role assigned (updated for multi-role system)
function hasRole() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    // Check if we've already determined this in the current session
    if (isset($_SESSION['has_role'])) {
        return $_SESSION['has_role'];
    }
    
    // If not cached, check database
    try {
        $dbFile = dirname(__FILE__) . '/db.php';
        if (!file_exists($dbFile)) {
            return false;
        }
        
        require_once $dbFile;
        
        if (!isset($db_host) || !isset($db_name) || !isset($db_user) || !isset($db_pass)) {
            return false;
        }
        
        $connection = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $connection->prepare("SELECT COUNT(*) as role_count FROM ws_user_roles WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $hasRole = ($result && $result['role_count'] > 0);
        $_SESSION['has_role'] = $hasRole;
        
        return $hasRole;
    } catch (Exception $e) {
        return false;
    }
}

// Check if user has admin role
function hasAdminRole() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    if (isset($_SESSION['has_admin_role'])) {
        return $_SESSION['has_admin_role'];
    }
    
    try {
        $dbFile = dirname(__FILE__) . '/db.php';
        if (!file_exists($dbFile)) {
            return false;
        }
        
        require_once $dbFile;
        
        if (!isset($db_host) || !isset($db_name) || !isset($db_user) || !isset($db_pass)) {
            return false;
        }
        
        $connection = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Check if user has admin role
        $stmt = $connection->prepare("
            SELECT COUNT(*) as admin_count 
            FROM ws_user_roles ur 
            JOIN ws_roles r ON ur.role_id = r.role_id 
            WHERE ur.user_id = ? AND (r.role_id = 1 OR r.role_name = 'admin')
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $hasAdmin = ($result && $result['admin_count'] > 0);
        $_SESSION['has_admin_role'] = $hasAdmin;
        
        return $hasAdmin;
    } catch (Exception $e) {
        return false;
}
}

// Check if user has specific permission
function hasNavPermission($permissionName) {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    $cacheKey = 'has_permission_' . $permissionName;
    if (isset($_SESSION[$cacheKey])) {
        return $_SESSION[$cacheKey];
    }
    
    try {
        $dbFile = dirname(__FILE__) . '/db.php';
        if (!file_exists($dbFile)) {
            return false;
        }
        
        require_once $dbFile;
        
        if (!isset($db_host) || !isset($db_name) || !isset($db_user) || !isset($db_pass)) {
            return false;
        }
        
        $connection = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Check if user has permission through any role
        $stmt = $connection->prepare("
            SELECT COUNT(*) as perm_count 
            FROM ws_user_roles ur 
            JOIN ws_role_permissions rp ON ur.role_id = rp.role_id 
            JOIN ws_permissions p ON rp.permission_id = p.permission_id 
            WHERE ur.user_id = ? AND p.permission_name = ?
        ");
        $stmt->execute([$_SESSION['user_id'], $permissionName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $hasPerm = ($result && $result['perm_count'] > 0);
        $_SESSION[$cacheKey] = $hasPerm;
        
        return $hasPerm;
    } catch (Exception $e) {
        return false;
    }
}

// Clear the role cache if requested (for debugging)
if (isset($_GET['clear_role_cache'])) {
    unset($_SESSION['has_role']);
    unset($_SESSION['ws_role']);
    error_log("Role cache cleared via URL parameter");
}

$isLoggedIn = isLoggedIn();
$username = $isLoggedIn ? getUsername() : null;
$hasAdminRole = $isLoggedIn ? hasAdminRole() : false;

// Calculate base path for links and images based on where nav.php is included from
// If $basePath is already set by the including file, use that; otherwise calculate it
if (!isset($basePath)) {
    // Get the file that included nav.php via backtrace
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    $includingFile = isset($backtrace[1]) ? $backtrace[1]['file'] : ($backtrace[0]['file'] ?? __FILE__);
    
    // Get absolute paths
    $includingFileAbs = realpath($includingFile);
    $rootDirAbs = realpath(dirname(__FILE__) . '/..'); // Root is one level up from includes/
    
    $basePath = '';
    
    if ($includingFileAbs && $rootDirAbs) {
        // Get the directory of the including file
        $includingDir = dirname($includingFileAbs);
        
        // Normalize paths for comparison (handle trailing slashes)
        $includingDir = rtrim($includingDir, DIRECTORY_SEPARATOR);
        $rootDirAbs = rtrim($rootDirAbs, DIRECTORY_SEPARATOR);
        
        // If including file is in root directory, basePath is empty
        if ($includingDir === $rootDirAbs) {
            $basePath = '';
        } 
        // If including file is in a subdirectory of root
        elseif (strpos($includingDir, $rootDirAbs) === 0) {
            // Get the relative path from root to including file's directory
            $relativePath = substr($includingDir, strlen($rootDirAbs));
            $relativePath = trim($relativePath, DIRECTORY_SEPARATOR);
            
            if ($relativePath) {
                // Count directory separators to determine depth
                // Handle both / and \ for cross-platform compatibility
                $depth = 0;
                if (DIRECTORY_SEPARATOR === '/') {
                    $depth = substr_count($relativePath, '/') + 1;
                } else {
                    // Windows: count both \ and /
                    $depth = max(substr_count($relativePath, '\\'), substr_count($relativePath, '/')) + 1;
                }
                
                // Generate ../ for each level
                $basePath = str_repeat('../', $depth);
            } else {
                $basePath = '';
            }
        } else {
            // Including file is outside root - default to empty (shouldn't happen)
            $basePath = '';
        }
    } else {
        // Fallback: if realpath fails, default to empty
        $basePath = '';
    }
}

// Ensure basePath ends with / if it's not empty
$basePath = rtrim($basePath, '/');
if ($basePath !== '') {
    $basePath .= '/';
}
?>


<nav class="nav-menu">
    <div class="nav-brand">
        <a href="<?= $basePath ?>about_psistorm.php">
            <img src="<?= $basePath ?>images/psistorm_gaming_logo_strip.png" alt="PSISTORM GAMING" class="nav-logo">
        </a>
    </div>
    
    <!-- Desktop Navigation -->
    <div class="nav-links desktop-nav">
        <!-- FSL Dropdown -->
        <div class="dropdown">
            <a href="<?= $basePath ?>fsl_season.php" class="menu-item logo-button">
                <img src="<?= $basePath ?>images/fsl_sc2_logo.png" alt="FSL" class="nav-logo">
                <span class="dropdown-arrow">&#9662;</span>
            </a>
            <div class="dropdown-content">
                <div class="dropdown-section">
                    <h4>FSL</h4>
                    <a href="<?= $basePath ?>fsl_season.php" class="dropdown-link">Seasons</a>
                    <a href="<?= $basePath ?>fsl_schedule.php" class="dropdown-link">Schedule</a>
                    <a href="<?= $basePath ?>fsl_teams.php" class="dropdown-link">Teams</a>
                    <a href="<?= $basePath ?>fsl_roster.php" class="dropdown-link">Players</a>
                    <a href="<?= $basePath ?>fsl_matches.php" class="dropdown-link">Matches</a>
                    <a href="<?= $basePath ?>draft/public" class="dropdown-link">Draft</a>
                    <a href="<?= $basePath ?>faq.php" class="dropdown-link">FAQ</a>
                    <a href="<?= $basePath ?>apply.php" class="dropdown-link">Apply</a>
                </div>
                
                <div class="dropdown-subsection">
                    <h5>Player Analysis</h5>
                    <a href="<?= $basePath ?>player_statistics.php" class="dropdown-link sub-link">Statistics</a>
                    <a href="<?= $basePath ?>public_spider_chart.php" class="dropdown-link sub-link">Spider Charts</a>
                    <a href="<?= $basePath ?>player_network.php" class="dropdown-link sub-link">Player Network</a>
                    <a href="<?= $basePath ?>voting_guide.php" class="dropdown-link sub-link">Voting Guide</a>
                </div>
            </div>
        </div>

        <!-- Pros and Joes Dropdown -->
        <div class="dropdown">
            <a href="<?= $basePath ?>pros_and_joes.php" class="menu-item logo-button">
                <img src="<?= $basePath ?>images/pros_and_joes_transparent_bg.png" alt="Pros and Joes" class="nav-logo">
                <span class="dropdown-arrow">&#9662;</span>
            </a>
            <div class="dropdown-content">
                <div class="dropdown-section">
                    <h4>Pros and Joes</h4>
                    <a href="<?= $basePath ?>matches.php" class="dropdown-link">Matches</a>
                    <a href="<?= $basePath ?>leaderboard.php" class="dropdown-link">Leaderboard</a>
                </div>
            </div>
        </div>

        <!-- Community Dropdown -->
        <div class="dropdown">
            <a href="#" class="menu-item button">
                Community
                <span class="dropdown-arrow">&#9662;</span>
            </a>
            <div class="dropdown-content">
                <div class="dropdown-section">
                    <h4>Community</h4>
                    <a href="<?= $basePath ?>discord.php" class="dropdown-link">Discord</a>
                    <a href="<?= $basePath ?>chat.php" class="dropdown-link">Chat</a>
                </div>
            </div>
        </div>

        <!-- Admin Dropdown -->
        <?php if ($isLoggedIn && ($hasAdminRole || hasNavPermission('manage fsl schedule') || hasNavPermission('edit_matches') || hasNavPermission('faq') || hasNavPermission('manage_permissions') || hasNavPermission('manage_user_roles') || hasNavPermission('manage spider charts'))): ?>
        <div class="dropdown">
            <a href="#" class="menu-item button">
                Admin
                <span class="dropdown-arrow">&#9662;</span>
            </a>
            <div class="dropdown-content">
                <div class="dropdown-section">
                    <h4>Admin</h4>
                    <?php if ($hasAdminRole || hasNavPermission('manage fsl schedule')): ?>
                    <a href="<?= $basePath ?>admin_schedule.php" class="dropdown-link">Manage FSL Schedule</a>
                    <?php endif; ?>
                    
                    <?php if ($hasAdminRole || hasNavPermission('edit_matches')): ?>
                    <a href="<?= $basePath ?>edit_fsl_matches.php" class="dropdown-link">Edit Matches</a>
                    <?php endif; ?>
                    
                    <?php if ($hasAdminRole || hasNavPermission('edit player, team, stats')): ?>
                    <a href="<?= $basePath ?>edit_player_statistics.php" class="dropdown-link">Edit Player Statistics</a>
                    <?php endif; ?>
                    
                    <?php if ($hasAdminRole || hasNavPermission('edit player, team, stats')): ?>
                    <a href="<?= $basePath ?>update_player_statistics.php" class="dropdown-link">Run Stats Updater</a>
                    <?php endif; ?>
                    
                    <?php if ($hasAdminRole || hasNavPermission('faq')): ?>
                    <a href="<?= $basePath ?>edit_faq.php" class="dropdown-link">Edit FAQ</a>
                    <?php endif; ?>
                    
                    <?php if ($hasAdminRole || hasNavPermission('manage_permissions')): ?>
                    <a href="<?= $basePath ?>manage_permissions.php" class="dropdown-link">Manage Permissions</a>
                    <?php endif; ?>
                    
                    <?php if ($hasAdminRole || hasNavPermission('manage_user_roles')): ?>
                    <a href="<?= $basePath ?>manage_user_roles.php" class="dropdown-link">Manage User Roles</a>
                    <?php endif; ?>

                    <?php if ($hasAdminRole || hasNavPermission('manage_user_roles')): ?>
                        <a href="<?= $basePath ?>draft/admin/index.php" class="dropdown-link">Manage Draft</a>
                    <?php endif; ?>

                </div>
                
                <?php if ($hasAdminRole || hasNavPermission('manage spider charts')): ?>
                <div class="dropdown-subsection">
                    <h5>Spider Chart System</h5>
                    <a href="<?= $basePath ?>spider_chart_admin.php" class="dropdown-link sub-link">Dashboard</a>
                    <a href="<?= $basePath ?>manage_reviewers.php" class="dropdown-link sub-link">Manage Reviewers</a>
                    <a href="<?= $basePath ?>voting_activity.php" class="dropdown-link sub-link">Voting Activity</a>
                    <a href="<?= $basePath ?>player_analysis.php" class="dropdown-link sub-link">Player Analysis</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Login, Register, Profile, Logout -->
        <?php if (!$isLoggedIn): ?>
            <a href="<?= $basePath ?>login.php" class="menu-item button">Login</a>
            <a href="<?= $basePath ?>register.php" class="menu-item button">Register</a>
        <?php else: ?>
            <a href="<?= $basePath ?>profile.php" class="menu-item button"><?php echo htmlspecialchars($username); ?></a>
            <a href="<?= $basePath ?>logout.php" class="menu-item button">Logout</a>
        <?php endif; ?>
    </div>

    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <span class="hamburger-line"></span>
        <span class="hamburger-line"></span>
        <span class="hamburger-line"></span>
    </button>

    <!-- Mobile Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <div class="mobile-nav-header">
            <h3>Menu</h3>
            <button class="mobile-close" id="mobileClose">&times;</button>
        </div>
        
        <div class="mobile-nav-content">
            <!-- FSL Section -->
            <div class="mobile-section">
                <h4>FSL</h4>
                <a href="<?= $basePath ?>fsl_season.php" class="mobile-link">Seasons</a>
                <a href="<?= $basePath ?>fsl_schedule.php" class="mobile-link">Schedule</a>
                <a href="<?= $basePath ?>fsl_teams.php" class="mobile-link">Teams</a>
                <a href="<?= $basePath ?>fsl_roster.php" class="mobile-link">Players</a>
                <a href="<?= $basePath ?>fsl_matches.php" class="mobile-link">Matches</a>
                <a href="<?= $basePath ?>draft/public/index.php" class="mobile-link">Draft</a>
                <a href="<?= $basePath ?>faq.php" class="mobile-link">FAQ</a>
                <a href="<?= $basePath ?>apply.php" class="mobile-link">Apply</a>
                
                <div class="mobile-subsection">
                    <h5>Player Analysis</h5>
                    <a href="<?= $basePath ?>player_statistics.php" class="mobile-link sub-link">Statistics</a>
                    <a href="<?= $basePath ?>public_spider_chart.php" class="mobile-link sub-link">Spider Charts</a>
                    <a href="<?= $basePath ?>player_network.php" class="mobile-link sub-link">Player Network</a>
                    <a href="<?= $basePath ?>voting_guide.php" class="mobile-link sub-link">Voting Guide</a>
                </div>
            </div>

            <!-- Pros and Joes Section -->
            <div class="mobile-section">
                <h4>Pros and Joes</h4>
                <a href="<?= $basePath ?>matches.php" class="mobile-link">Matches</a>
                <a href="<?= $basePath ?>leaderboard.php" class="mobile-link">Leaderboard</a>
            </div>

            <!-- Community Section -->
            <div class="mobile-section">
                <h4>Community</h4>
                <a href="<?= $basePath ?>discord.php" class="mobile-link">Discord</a>
                <a href="<?= $basePath ?>chat.php" class="mobile-link">Chat</a>
            </div>

            <!-- Admin Section -->
            <?php if ($isLoggedIn && ($hasAdminRole || hasNavPermission('manage fsl schedule') || hasNavPermission('edit_matches') || hasNavPermission('faq') || hasNavPermission('manage_permissions') || hasNavPermission('manage_user_roles') || hasNavPermission('manage spider charts'))): ?>
            <div class="mobile-section">
                <h4>Admin</h4>
                <?php if ($hasAdminRole || hasNavPermission('manage fsl schedule')): ?>
                <a href="<?= $basePath ?>admin_schedule.php" class="mobile-link">Manage FSL Schedule</a>
                <?php endif; ?>
                
                <?php if ($hasAdminRole || hasNavPermission('edit_matches')): ?>
                <a href="<?= $basePath ?>edit_fsl_matches.php" class="mobile-link">Edit Matches</a>
                <?php endif; ?>
                
                <?php if ($hasAdminRole || hasNavPermission('edit player, team, stats')): ?>
                <a href="<?= $basePath ?>edit_player_statistics.php" class="mobile-link">Edit Player Statistics</a>
                <?php endif; ?>
                
                <?php if ($hasAdminRole || hasNavPermission('edit player, team, stats')): ?>
                <a href="<?= $basePath ?>update_player_statistics.php" class="mobile-link">Run Stats Updater</a>
                <?php endif; ?>
                
                <?php if ($hasAdminRole || hasNavPermission('faq')): ?>
                <a href="<?= $basePath ?>edit_faq.php" class="mobile-link">Edit FAQ</a>
                <?php endif; ?>
                
                <?php if ($hasAdminRole || hasNavPermission('manage_permissions')): ?>
                <a href="<?= $basePath ?>manage_permissions.php" class="mobile-link">Manage Permissions</a>
                <?php endif; ?>
                
                <?php if ($hasAdminRole || hasNavPermission('manage_user_roles')): ?>
                <a href="<?= $basePath ?>manage_user_roles.php" class="mobile-link">Manage User Roles</a>
                <?php endif; ?>
                
                <?php if ($hasAdminRole || hasNavPermission('manage spider charts')): ?>
                <div class="mobile-subsection">
                    <h5>Spider Chart System</h5>
                    <a href="<?= $basePath ?>spider_chart_admin.php" class="mobile-link sub-link">Dashboard</a>
                    <a href="<?= $basePath ?>manage_reviewers.php" class="mobile-link sub-link">Manage Reviewers</a>
                    <a href="<?= $basePath ?>voting_activity.php" class="mobile-link sub-link">Voting Activity</a>
                    <a href="<?= $basePath ?>player_analysis.php" class="mobile-link sub-link">Player Analysis</a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- User Section -->
            <div class="mobile-section">
                <h4>User</h4>
                <?php if (!$isLoggedIn): ?>
                    <a href="<?= $basePath ?>login.php" class="mobile-link">Login</a>
                    <a href="<?= $basePath ?>register.php" class="mobile-link">Register</a>
                <?php else: ?>
                    <a href="<?= $basePath ?>profile.php" class="mobile-link"><?php echo htmlspecialchars($username); ?></a>
                    <a href="<?= $basePath ?>logout.php" class="mobile-link">Logout</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<!-- Scoped CSS (Fixing Button Borders & Hover Effects) -->
<style>
    /* Limit styles to only .nav-menu */
    .nav-menu {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 20px;
        background-color: #0a0a16;
        position: relative;
        z-index: 10000; /* Ensure nav menu is always on top */
    }

    .nav-menu .nav-brand {
        flex: 1; /* Allow brand to grow and take available space */
        text-align: left;
    }

    .nav-menu .nav-links {
        display: flex;
        align-items: center;
        gap: 20px;
    }

    /* Dropdown Container */
    .nav-menu .dropdown {
        position: relative;
        z-index: 10001; /* Higher than nav-menu to ensure dropdowns are on top */
    }

    /* Align Main Menu Item and Dropdown Arrow */
    .nav-menu .logo-button {
        display: flex;
        align-items: center;
        gap: 5px;
        text-decoration: none;
        padding: 10px 15px;
        border: 2px solid #666;
        border-radius: 5px;
        transition: all 0.3s ease-in-out;
        background-color: transparent;
    }

    .nav-menu .logo-button:hover {
        background-color: rgba(80, 80, 100, 0.5);
        border-color: #888;
    }

    /* Dropdown Arrow */
    .nav-menu .dropdown-arrow {
        font-size: 14px;
        color: white;
        margin-left: 5px;
        transition: transform 0.3s ease-in-out;
    }

    /* Enhanced Dropdown Menu with Mobile Menu Styling */
    .nav-menu .dropdown-content {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        background: rgba(10, 10, 22, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 8px;
        min-width: 250px;
        box-shadow: 0px 8px 16px rgba(0, 0, 0, 0.3);
        z-index: 10002; /* Highest z-index to ensure dropdowns appear above all content */
        opacity: 0;
        transform: translateY(10px);
        transition: opacity 0.3s ease, transform 0.3s ease;
        padding: 15px;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .nav-menu .dropdown-section {
        margin-bottom: 15px;
    }

    .nav-menu .dropdown-section h4 {
        color: #00d4ff;
        margin: 0 0 10px 0;
        font-size: 1.1em;
        border-bottom: 1px solid rgba(0, 212, 255, 0.3);
        padding-bottom: 5px;
    }

    .nav-menu .dropdown-subsection {
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
    }

    .nav-menu .dropdown-subsection h5 {
        color: #00d4ff;
        margin: 0 0 10px 0;
        font-size: 1em;
        padding-left: 10px;
    }

    .nav-menu .dropdown-link {
        display: block;
        padding: 10px 12px;
        color: #e0e0e0;
        text-decoration: none;
        border-radius: 6px;
        margin-bottom: 3px;
        transition: all 0.2s ease;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .nav-menu .dropdown-link:hover {
        background: rgba(0, 212, 255, 0.1);
        border-color: #00d4ff;
        color: #00d4ff;
        transform: translateX(3px);
    }

    .nav-menu .dropdown-link.sub-link {
        margin-left: 10px;
        background: rgba(255, 255, 255, 0.03);
        border-color: rgba(255, 255, 255, 0.05);
        font-size: 0.95em;
    }

    .nav-menu .dropdown-link.sub-link:hover {
        background: rgba(0, 212, 255, 0.08);
        border-color: rgba(0, 212, 255, 0.5);
    }

    /* Show Dropdown on Hover */
    .nav-menu .dropdown:hover .dropdown-content {
        display: block;
        opacity: 1;
        transform: translateY(0);
    }

    /* Rotate Arrow on Hover */
    .nav-menu .dropdown:hover .dropdown-arrow {
        transform: rotate(180deg);
    }

    /* Menu Buttons (Login, Register, Logout) */
    .nav-menu .button {
        display: inline-block;
        padding: 10px 15px;
        border: 2px solid #666;
        border-radius: 5px;
        text-decoration: none;
        font-weight: bold;
        color: white;
        background-color: transparent;
        text-align: center;
        transition: all 0.3s ease-in-out;
    }

    /* Button Hover Effect */
    .nav-menu .button:hover {
        background-color: rgba(80, 80, 100, 0.5);
        border-color: #888;
    }

    /* Active (Current Page) */
    .nav-menu .button.active {
        background-color: rgba(90, 90, 120, 1);
        border-color: #aaa;
    }

    /* Logo Styling */
    .nav-menu .nav-logo {
        height: 40px;
        width: auto;
        max-width: 100%;
    }

    /* Mobile Menu Toggle */
    .nav-menu .mobile-menu-toggle {
        display: none; /* Hidden on desktop */
        background: none;
        border: none;
        color: white;
        font-size: 24px;
        cursor: pointer;
        padding: 10px;
        z-index: 1001; /* Ensure it's above other content */
    }

    .nav-menu .hamburger-line {
        display: block;
        width: 25px;
        height: 3px;
        background-color: white;
        margin: 5px 0;
        transition: transform 0.3s ease-in-out;
    }

    .nav-menu .mobile-menu-toggle:hover .hamburger-line {
        background-color: #00d4ff;
    }

    .nav-menu .mobile-menu-toggle.active .hamburger-line:nth-child(1) {
        transform: translateY(8px) rotate(45deg);
    }

    .nav-menu .mobile-menu-toggle.active .hamburger-line:nth-child(2) {
        opacity: 0;
    }

    .nav-menu .mobile-menu-toggle.active .hamburger-line:nth-child(3) {
        transform: translateY(-8px) rotate(-45deg);
    }

    /* Mobile Navigation Styles */
    .mobile-nav {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100vh;
        background: rgba(10, 10, 22, 0.95);
        backdrop-filter: blur(10px);
        z-index: 1000;
        transform: translateX(-100%);
        transition: transform 0.3s ease-in-out;
        overflow-y: auto;
    }

    .mobile-nav-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        background: rgba(0, 0, 0, 0.3);
    }

    .mobile-nav-header h3 {
        margin: 0;
        color: #00d4ff;
        font-size: 1.5em;
    }

    .mobile-close {
        background: none;
        border: none;
        color: #e0e0e0;
        font-size: 2em;
        cursor: pointer;
        padding: 0;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: all 0.2s ease;
    }

    .mobile-close:hover {
        background: rgba(255, 255, 255, 0.1);
        color: #00d4ff;
    }

    .mobile-nav-content {
        padding: 20px;
    }

    .mobile-section {
        margin-bottom: 30px;
    }

    .mobile-section h4 {
        color: #00d4ff;
        margin: 0 0 15px 0;
        font-size: 1.2em;
        border-bottom: 1px solid rgba(0, 212, 255, 0.3);
        padding-bottom: 8px;
    }

    .mobile-section h5 {
        color: #00d4ff;
        margin: 15px 0 10px 0;
        font-size: 1em;
        padding-left: 15px;
    }

    .mobile-link {
        display: block;
        padding: 12px 15px;
        color: #e0e0e0;
        text-decoration: none;
        border-radius: 8px;
        margin-bottom: 5px;
        transition: all 0.2s ease;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .mobile-link:hover {
        background: rgba(0, 212, 255, 0.1);
        border-color: #00d4ff;
        color: #00d4ff;
        transform: translateX(5px);
    }

    .mobile-link.sub-link {
        margin-left: 15px;
        background: rgba(255, 255, 255, 0.03);
        border-color: rgba(255, 255, 255, 0.05);
        font-size: 0.95em;
    }

    .mobile-link.sub-link:hover {
        background: rgba(0, 212, 255, 0.08);
        border-color: rgba(0, 212, 255, 0.5);
    }

    /* Mobile Navigation Animation */
    .mobile-nav.show {
        transform: translateX(0);
    }

    /* Prevent body scroll when mobile menu is open */
    body.menu-open {
        overflow: hidden;
    }

    /* Mobile-Friendly Dropdown */
    @media (max-width: 768px) {
        .nav-menu .mobile-menu-toggle {
            display: block;
        }
        
        .nav-menu .desktop-nav {
            display: none; /* Hide desktop navigation on mobile */
        }
    }
</style>

<!-- Mobile Navigation JavaScript -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileClose = document.getElementById('mobileClose');
        const mobileNav = document.getElementById('mobileNav');
        const body = document.body;

        // Show mobile menu
        mobileMenuToggle.addEventListener('click', function() {
            mobileNav.style.transform = 'translateX(0)';
            body.classList.add('menu-open');
            mobileMenuToggle.classList.add('active');
        });

        // Hide mobile menu
        mobileClose.addEventListener('click', function() {
            mobileNav.style.transform = 'translateX(-100%)';
            body.classList.remove('menu-open');
            mobileMenuToggle.classList.remove('active');
        });

        // Close mobile menu when clicking outside
        document.addEventListener('click', function(event) {
            if (mobileNav.style.transform === 'translateX(0px)' && 
                !mobileNav.contains(event.target) && 
                !mobileMenuToggle.contains(event.target)) {
                mobileNav.style.transform = 'translateX(-100%)';
                body.classList.remove('menu-open');
                mobileMenuToggle.classList.remove('active');
            }
        });

        // Close mobile menu on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && mobileNav.style.transform === 'translateX(0px)') {
                mobileNav.style.transform = 'translateX(-100%)';
                body.classList.remove('menu-open');
                mobileMenuToggle.classList.remove('active');
            }
        });

        // Auto-close mobile menu when clicking on a link
        const mobileLinks = document.querySelectorAll('.mobile-link');
        mobileLinks.forEach(link => {
            link.addEventListener('click', function() {
                mobileNav.style.transform = 'translateX(-100%)';
                body.classList.remove('menu-open');
                mobileMenuToggle.classList.remove('active');
            });
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                // Hide mobile menu on desktop
                mobileNav.style.transform = 'translateX(-100%)';
                body.classList.remove('menu-open');
                mobileMenuToggle.classList.remove('active');
            }
        });
    });
</script>
