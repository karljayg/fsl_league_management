<?php
session_start();

// Configuration
require_once 'config.php';

// Check admin permission
require_once 'includes/db.php';

// Connect to database
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Check if user is admin
function userHasAdminRole($user_id) {
    global $db;
    if (!$user_id) return false;
    
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt
            FROM ws_user_roles ur
            JOIN ws_roles r ON ur.role_id = r.role_id
            WHERE ur.user_id = ? AND r.role_name = 'admin'
        ");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return ($result && $result['cnt'] > 0);
    } catch (Exception $e) {
        return false;
    }
}

if (!userHasAdminRole($_SESSION['user_id'] ?? 0)) {
    header('Location: index.php');
    exit;
}

// Handle actions
if ($_POST['action'] ?? '' === 'clear_token') {
    $user_id = $_POST['user_id'] ?? '';
    
    if (empty($user_id)) {
        $error = "No user ID provided";
    } else {
        $stmt = $db->prepare('UPDATE users SET reset_token = NULL, reset_token_expires = NULL WHERE id = ?');
        $result = $stmt->execute([$user_id]);
        $affected = $stmt->rowCount();
        
        if ($result && $affected > 0) {
            $success = "Reset token cleared for user: $user_id";
        } else if ($result && $affected === 0) {
            $error = "No token found for user: $user_id (may already be cleared)";
        } else {
            $error = "Failed to clear token for user: $user_id";
        }
    }
    
    // Redirect to prevent resubmission
    header('Location: admin_password_resets.php?success=' . urlencode($success ?? '') . '&error=' . urlencode($error ?? ''));
    exit;
}

if ($_POST['action'] ?? '' === 'clear_all_expired') {
    $stmt = $db->prepare('UPDATE users SET reset_token = NULL, reset_token_expires = NULL WHERE reset_token_expires < NOW()');
    $result = $stmt->execute();
    $affected = $stmt->rowCount();
    if ($result) {
        $success = "Cleared $affected expired reset tokens";
    } else {
        $error = "Failed to clear expired tokens";
    }
    
    // Redirect to prevent resubmission
    header('Location: admin_password_resets.php?success=' . urlencode($success ?? '') . '&error=' . urlencode($error ?? ''));
    exit;
}

// Handle success/error messages from redirect
if (isset($_GET['success']) && !empty($_GET['success'])) {
    $success = $_GET['success'];
}
if (isset($_GET['error']) && !empty($_GET['error'])) {
    $error = $_GET['error'];
}

// Get all pending password reset requests
$stmt = $db->prepare('
    SELECT id, username, email, reset_token, reset_token_expires,
           CASE WHEN reset_token_expires > NOW() THEN "Valid" ELSE "Expired" END as status
    FROM users 
    WHERE reset_token IS NOT NULL 
    ORDER BY reset_token_expires DESC
');
$stmt->execute();
$reset_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Password Reset Management";
include_once 'includes/header.php';
?>

<div class="container mt-4">
    <div class="admin-header">
        <h1><i class="fas fa-key"></i> Password Reset Management</h1>
        <div class="admin-user-info">
            <span>Logged in as: <?= htmlspecialchars($_SESSION['username'] ?? 'Unknown') ?></span>
            <a href="logout.php" class="btn btn-logout">Logout</a>
        </div>
    </div>
        
    <?php if (isset($success)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
        
        <div class="admin-actions">
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="clear_all_expired">
                <button type="submit" class="btn btn-warning" onclick="return confirm('Clear all expired reset tokens?')">
                    Clear Expired Tokens
                </button>
            </form>
            <a href="index.php" class="btn btn-secondary">← Back to Admin</a>
        </div>
        
        <?php if (empty($reset_requests)): ?>
            <div class="info-message">
                <p>No pending password reset requests.</p>
                <p><small>Users are told to contact: <strong><?php echo htmlspecialchars($config['reset_admin_contact']); ?></strong></small></p>
            </div>
        <?php else: ?>
            <div class="reset-requests-table">
                <h3>Pending Reset Requests (<?php echo count($reset_requests); ?>)</h3>
                <table class="table table-dark">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Expires</th>
                            <th>Reset Link</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reset_requests as $request): ?>
                            <tr class="<?php echo $request['status'] === 'Expired' ? 'expired' : 'valid'; ?>">
                                <td><strong><?php echo htmlspecialchars($request['username']); ?></strong></td>
                                <td><?php echo htmlspecialchars($request['email']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower($request['status']); ?>">
                                        <?php echo $request['status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    $expires = new DateTime($request['reset_token_expires'], new DateTimeZone('UTC'));
                                    $expires->setTimezone(new DateTimeZone($config['timezone']));
                                    echo $expires->format('M j, Y g:i A T');
                                    ?>
                                </td>
                                <td>
                                    <?php if ($request['status'] === 'Valid'): ?>
                                        <div class="reset-link-container">
                                            <input type="text" class="reset-link-input" 
                                                   value="<?php echo 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/reset_password.php?token=' . $request['reset_token']; ?>" 
                                                   readonly onclick="this.select()">
                                            <button type="button" class="btn btn-small btn-copy" 
                                                    onclick="copyToClipboard(this.previousElementSibling)">Copy</button>
                                        </div>
                                    <?php else: ?>
                                        <span class="expired-text">Expired</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Clear reset token for <?php echo htmlspecialchars($request['username']); ?>?')">
                                        <input type="hidden" name="action" value="clear_token">
                                        <input type="hidden" name="user_id" value="<?php echo $request['id']; ?>">
                                        <button type="submit" class="btn btn-small btn-danger">
                                            Clear
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
        <div class="instructions">
            <h4>Instructions:</h4>
            <ul>
                <li><strong>Valid tokens:</strong> Copy the reset link and send it to the user via your preferred method</li>
                <li><strong>Expired tokens:</strong> User needs to request a new password reset</li>
                <li><strong>Clear tokens:</strong> Remove tokens when no longer needed</li>
                <li>Reset tokens expire automatically after <?php echo $config['reset_token_expiry_hours']; ?> hours</li>
            </ul>
            
            <div class="admin-info">
                <h5>Configuration:</h5>
                <p><strong>Users are told to contact:</strong> <?php echo htmlspecialchars($config['reset_admin_contact']); ?></p>
                <p><small>You can change this in <code>config.php</code> under <code>'reset_admin_contact'</code></small></p>
            </div>
        </div>
    </div>
</div>

<style>
    body {
        font-family: 'Inter', sans-serif;
        background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
        color: #e0e0e0;
        margin: 0;
        padding: 0;
        line-height: 1.4;
    }
    
    .container {
        max-width: 1200px;
        margin: 20px auto;
        padding: 20px;
    }
    
    .admin-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
    }
    
    .admin-user-info {
        display: flex;
        align-items: center;
        gap: 15px;
        color: #ccc;
    }
    
    h1 {
        color: #00d4ff;
        text-shadow: 0 0 15px #00d4ff;
        font-size: 2.4em;
        margin: 0;
    }
    
    h3 {
        color: #00d4ff;
        margin: 20px 0 15px 0;
    }
    
    .alert {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 5px;
    }
    
    .alert-success {
        background: rgba(40, 167, 69, 0.2);
        border: 1px solid #28a745;
        color: #28a745;
    }
    
    .alert-error {
        background: rgba(220, 53, 69, 0.2);
        border: 1px solid #dc3545;
        color: #dc3545;
    }
    
    .admin-actions {
        margin: 20px 0;
        display: flex;
        gap: 10px;
        align-items: center;
    }
    
    .reset-requests-table {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        padding: 25px;
        margin: 20px 0;
    }
    
    .table-dark {
        background: rgba(0, 0, 0, 0.3);
        color: #e0e0e0;
        border: 1px solid rgba(0, 212, 255, 0.2);
        width: 100%;
        border-collapse: collapse;
        margin: 20px 0;
        border-radius: 8px;
        overflow: hidden;
    }
    
    .table-dark th {
        background: rgba(0, 212, 255, 0.1);
        color: #00d4ff;
        border-color: rgba(0, 212, 255, 0.2);
        padding: 15px 12px;
        text-align: left;
        font-weight: bold;
        text-transform: uppercase;
        font-size: 13px;
        letter-spacing: 0.5px;
    }
    
    .table-dark td {
        border-color: rgba(0, 212, 255, 0.2);
        padding: 15px 12px;
        vertical-align: middle;
    }
    
    .table-dark tr.expired {
        background-color: rgba(255, 152, 0, 0.1);
        border-left: 4px solid #ff9800;
    }
    
    .table-dark tr.valid {
        background-color: rgba(76, 175, 80, 0.1);
        border-left: 4px solid #4caf50;
    }
    
    .table-dark tr:hover {
        background-color: rgba(0, 212, 255, 0.1);
        transition: background-color 0.2s ease;
    }
    
    .status-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: inline-block;
        min-width: 70px;
        text-align: center;
    }
    
    .status-valid {
        background: #4caf50;
        color: white;
        box-shadow: 0 2px 4px rgba(76, 175, 80, 0.3);
    }
    
    .status-expired {
        background: #ff9800;
        color: white;
        box-shadow: 0 2px 4px rgba(255, 152, 0, 0.3);
    }
    
    .reset-link-container {
        display: flex;
        gap: 8px;
        align-items: center;
        max-width: 400px;
    }
    
    .reset-link-input {
        flex: 1;
        padding: 8px 10px;
        border: 1px solid rgba(0, 212, 255, 0.3);
        border-radius: 5px;
        font-size: 12px;
        font-family: 'Courier New', monospace;
        background: rgba(0, 0, 0, 0.5);
        color: #e0e0e0;
        transition: border-color 0.2s ease;
    }
    
    .reset-link-input:focus {
        outline: none;
        border-color: #00d4ff;
        box-shadow: 0 0 10px rgba(0, 212, 255, 0.3);
    }
    
    .btn {
        padding: 8px 16px;
        border-radius: 5px;
        border: none;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-block;
        font-size: 14px;
    }
    
    .btn:hover {
        opacity: 0.8;
        transform: translateY(-1px);
    }
    
    .btn-small {
        padding: 6px 12px;
        font-size: 12px;
    }
    
    .btn-copy {
        background: #00d4ff;
        color: #0f0c29;
    }
    
    .btn-danger {
        background: #dc3545;
        color: white;
    }
    
    .btn-warning {
        background: #ffc107;
        color: #212529;
    }
    
    .btn-secondary {
        background: #6c757d;
        color: white;
    }
    
    .btn-logout {
        background: #dc3545;
        color: white;
    }
    
    .expired-text {
        color: #ff9800;
        font-style: italic;
        font-weight: 500;
        text-align: center;
        padding: 8px;
    }
    
    .info-message {
        background: rgba(33, 150, 243, 0.1);
        border: 1px solid rgba(33, 150, 243, 0.3);
        color: #2196f3;
        padding: 25px;
        border-radius: 8px;
        text-align: center;
        border-left: 4px solid #2196f3;
        font-size: 16px;
    }
    
    .instructions {
        margin-top: 30px;
        padding: 25px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        border: 1px solid rgba(0, 212, 255, 0.2);
    }
    
    .instructions h4 {
        margin-top: 0;
        color: #00d4ff;
        font-size: 18px;
        font-weight: 600;
    }
    
    .instructions ul {
        margin: 15px 0;
        padding-left: 20px;
    }
    
    .instructions li {
        margin: 8px 0;
        line-height: 1.5;
        color: #e0e0e0;
    }
    
    .admin-info {
        margin-top: 20px;
        padding: 15px;
        background: rgba(0, 0, 0, 0.3);
        border-radius: 8px;
        border: 1px solid rgba(0, 212, 255, 0.2);
    }
    
    .admin-info h5 {
        margin: 0 0 10px 0;
        color: #00d4ff;
    }
    
    .admin-info p {
        margin: 5px 0;
        color: #e0e0e0;
    }
    
    .admin-info code {
        background: rgba(0, 0, 0, 0.5);
        padding: 2px 4px;
        border-radius: 3px;
        font-family: monospace;
        color: #00d4ff;
        border: 1px solid rgba(0, 212, 255, 0.3);
    }
</style>

<script>
function copyToClipboard(input) {
    input.select();
    input.setSelectionRange(0, 99999); // For mobile devices
    document.execCommand('copy');
    
    // Show feedback
    const button = input.nextElementSibling;
    const originalText = button.textContent;
    button.textContent = 'Copied!';
    button.style.background = '#28a745';
    
    setTimeout(() => {
        button.textContent = originalText;
        button.style.background = '#007bff';
    }, 2000);
}
</script>

<?php include_once 'includes/footer.php'; ?> 