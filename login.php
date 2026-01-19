<?php
// Start the session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
require_once 'includes/db.php';

// Connect to database
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Initialize login state
$isLoggedIn = isset($_SESSION['user_id']);
$username = $isLoggedIn ? $_SESSION['username'] : null;

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($email && $password) {
        // Updated query for new multi-role system
        $stmt = $db->prepare('SELECT id, email, username, password FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            
            // Check if user has any roles in the new multi-role system
            $roleStmt = $db->prepare('SELECT COUNT(*) as role_count FROM ws_user_roles WHERE user_id = ?');
            $roleStmt->execute([$user['id']]);
            $roleResult = $roleStmt->fetch(PDO::FETCH_ASSOC);
            
            // Set has_role flag for compatibility with nav.php
            $_SESSION['has_role'] = ($roleResult && $roleResult['role_count'] > 0);
            
            // Debug log
            error_log("User logged in - ID: {$user['id']}, Username: {$user['username']}, Has Roles: " . 
                     ($_SESSION['has_role'] ? 'true' : 'false'));
            
            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid email or password';
        }
    } else {
        $error = 'Please enter both email and password';
    }
}

// Set page title
$pageTitle = "Login";

// Include header
include_once 'includes/header.php';
?>

<section class="auth-section">
    <div class="auth-container">
        <div class="auth-box">
            <h2>Login to FSL Pros and Joes</h2>
            <?php if (isset($error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST" action="login.php">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="primary-btn">Login</button>
            </form>
            <p class="auth-link"><a href="forgot_password.php">Forgot your password?</a></p>
            <p class="auth-link">Don't have an account? <a href="register.php">Register here</a></p>
        </div>
    </div>
</section>

<?php
// Include footer
include_once 'includes/footer.php';
?> 