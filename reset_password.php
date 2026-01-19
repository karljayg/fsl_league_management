<?php
session_start();

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Database connection
require_once 'includes/db.php';

try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$token = $_GET['token'] ?? '';
$error = '';
$success = '';

// Validate token
$user = null;
if ($token) {
    $stmt = $db->prepare('SELECT id, username, email FROM users WHERE reset_token = ? AND reset_token_expires > NOW()');
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        $error = 'Invalid or expired reset token. Please request a new password reset.';
    }
} else {
    $error = 'No reset token provided.';
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($password)) {
        $error = 'Please enter a new password';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } else {
        // Update password and clear reset token
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE users SET password = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?');
        $stmt->execute([$password_hash, $user['id']]);
        
        $success = 'Password reset successful! You can now log in with your new password.';
        
        // Clear user data to prevent further resets
        $user = null;
    }
}

$pageTitle = "Reset Password";
include_once 'includes/header.php';
?>

<section class="auth-section">
    <div class="auth-container">
        <div class="auth-box">
            <h2>Set New Password</h2>
            
            <?php if ($success): ?>
                <div class="success-message">
                    <?php echo htmlspecialchars($success); ?>
                    <br><br>
                    <a href="login.php" class="primary-btn">Go to Login</a>
                </div>
            <?php elseif ($error): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($error); ?>
                    <br><br>
                    <a href="forgot_password.php">Request New Reset Link</a>
                </div>
            <?php elseif ($user): ?>
                <p>Enter your new password for <strong><?php echo htmlspecialchars($user['email']); ?></strong></p>
                
                <form method="POST" action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>">
                    <div class="form-group">
                        <label for="password">New Password</label>
                        <input type="password" id="password" name="password" required minlength="8">
                        <small>Must be at least 8 characters long</small>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    <button type="submit" class="primary-btn">Reset Password</button>
                </form>
            <?php endif; ?>
            
            <p class="auth-link">
                <a href="login.php">← Back to Login</a>
            </p>
        </div>
    </div>
</section>

<style>
.success-message {
    background: rgba(40, 167, 69, 0.2);
    border: 1px solid #28a745;
    color: #28a745;
    padding: 15px;
    border-radius: 5px;
    margin-bottom: 20px;
    text-align: center;
}

.success-message .primary-btn {
    display: inline-block;
    margin-top: 10px;
    text-decoration: none;
}

.form-group small {
    display: block;
    color: #666;
    font-size: 0.9em;
    margin-top: 5px;
}
</style>

<?php include_once 'includes/footer.php'; ?> 