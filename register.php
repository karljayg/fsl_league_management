<?php
session_start();

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

// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = $_POST['role'] ?? 'user';
    $mmr = isset($_POST['mmr']) && $_POST['mmr'] !== '' ? (int)$_POST['mmr'] : null;
    $race_preference = isset($_POST['race_preference']) && $_POST['race_preference'] !== '' ? $_POST['race_preference'] : null;
    
    if ($email && $username && $password && $confirm_password) {
        if ($password !== $confirm_password) {
            $error = 'Passwords do not match';
        } else {
            // Check if email already exists
            $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'Email already exists';
            } else {
                // Check if username already exists
                $stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                    $error = 'Username already exists';
                } else {
                    // Validate MMR if provided
                    if (isset($_POST['mmr']) && $_POST['mmr'] !== '') {
                        if (!is_numeric($_POST['mmr']) || (int)$_POST['mmr'] < 0 || (int)$_POST['mmr'] > 8000) {
                            $error = 'MMR must be a number between 0 and 8000';
                        }
                    }
                    
                    // Validate race_preference if provided
                    if (isset($_POST['race_preference']) && $_POST['race_preference'] !== '') {
                        $valid_races = ['Protoss', 'Terran', 'Zerg', 'Random'];
                        if (!in_array($_POST['race_preference'], $valid_races)) {
                            $error = 'Invalid race preference selected';
                        }
                    }
                    
                    if (!isset($error)) {
                        // Create new user
                        $id = uniqid('usr_', true);
                        $password_hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare('INSERT INTO users (id, email, username, password, role, mmr, race_preference) VALUES (?, ?, ?, ?, ?, ?, ?)');
                        $stmt->execute([$id, $email, $username, $password_hash, $role, $mmr, $race_preference]);
                        
                        // Log the user in
                        $_SESSION['user_id'] = $id;
                        $_SESSION['username'] = $username;
                        header('Location: index.php');
                        exit;
                    }
                }
            }
        }
    } else {
        $error = 'Please fill in all required fields';
    }
}

// Set page title
$pageTitle = "Register";

// Include header
include_once 'includes/header.php';
?>

<section class="auth-section">
    <div class="auth-container">
        <div class="auth-box">
            <h2>Create an Account</h2>
            <?php if (isset($error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST" action="register.php">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
                <div class="form-group">
                    <label for="role">Role</label>
                    <select id="role" name="role" class="form-control">
                        <option value="user">Regular User</option>
                        <option value="pro">Pro Player</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="mmr">MMR (Optional)</label>
                    <input type="number" id="mmr" name="mmr" min="0" max="8000" class="form-control">
                    <small class="form-text text-muted">Your current MMR in StarCraft II. Leave empty if unknown.</small>
                </div>
                <div class="form-group">
                    <label for="race_preference">Preferred Race (Optional)</label>
                    <select id="race_preference" name="race_preference" class="form-control">
                        <option value="">Select a race</option>
                        <option value="Protoss">Protoss</option>
                        <option value="Terran">Terran</option>
                        <option value="Zerg">Zerg</option>
                        <option value="Random">Random</option>
                    </select>
                </div>
                <button type="submit" class="primary-btn">Register</button>
            </form>
            <p class="auth-link">Already have an account? <a href="login.php">Login here</a></p>
        </div>
    </div>
</section>

<?php
// Include footer
include_once 'includes/footer.php';
?> 