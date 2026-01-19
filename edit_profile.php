<?php
session_start();

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
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

// Get server's maximum upload size
function getMaximumFileUploadSize() {
    // Get raw values for debugging
    $raw_upload = ini_get('upload_max_filesize');
    $raw_post = ini_get('post_max_size');
    $raw_memory = ini_get('memory_limit');
    
    // Debug output
    error_log("Raw upload_max_filesize: " . $raw_upload);
    error_log("Raw post_max_size: " . $raw_post);
    error_log("Raw memory_limit: " . $raw_memory);
    
    // Extract numeric value from string (e.g., "5M" becomes 5)
    $max_upload = (int)$raw_upload;
    $max_post = (int)$raw_post;
    $memory_limit = (int)$raw_memory;
    
    // Return the smallest of the three values in MB
    return min($max_upload, $max_post, $memory_limit) . ' MB';
}

$max_upload_size = getMaximumFileUploadSize();

// Get user data
$stmt = $db->prepare('SELECT id, username, email, role, mmr, race_preference, avatar_url FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// If user not found, redirect to login
if (!$user) {
    header('Location: login.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mmr = isset($_POST['mmr']) && $_POST['mmr'] !== '' ? (int)$_POST['mmr'] : null;
    $race_preference = isset($_POST['race_preference']) && $_POST['race_preference'] !== '' ? $_POST['race_preference'] : null;
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Handle profile picture upload
    $avatar_url = $user['avatar_url']; // Keep existing avatar by default
    
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        $file_info = getimagesize($_FILES['profile_picture']['tmp_name']);
        $file_type = $file_info ? $file_info['mime'] : '';
        $file_size = $_FILES['profile_picture']['size'];
        
        if (!in_array($file_type, $allowed_types)) {
            $error = 'Invalid file type. Only JPG, PNG, and GIF images are allowed.';
        } elseif ($file_size > $max_size) {
            $error = 'File size exceeds the maximum limit of 5MB.';
        } else {
            // Create subfolder structure based on user ID
            // Use first 2 chars of user ID for first level, next 2 for second level
            $user_id = $_SESSION['user_id'];
            $subfolder1 = substr($user_id, 0, 2);
            $subfolder2 = substr($user_id, 2, 2);
            
            $upload_dir = "images/avatars/{$subfolder1}/{$subfolder2}";
            
            // Create directories if they don't exist
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate unique filename
            $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $filename = 'avatar_' . $_SESSION['user_id'] . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . '/' . $filename;
            
            // Move uploaded file
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                // Delete old avatar if exists and not default
                if ($avatar_url && $avatar_url !== 'images/default-avatar.png' && file_exists($avatar_url)) {
                    unlink($avatar_url);
                }
                
                $avatar_url = $upload_path;
            } else {
                $error = 'Failed to upload profile picture. Please try again.';
            }
        }
    }
    
    // Handle base64 image data if submitted
    if (isset($_POST['image_data']) && !empty($_POST['image_data'])) {
        // Extract the base64 data
        $image_data = $_POST['image_data'];
        $image_parts = explode(";base64,", $image_data);
        
        if (count($image_parts) === 2) {
            // Get the image type
            $image_type_aux = explode("image/", $image_parts[0]);
            $image_type = $image_type_aux[1];
            
            // Create subfolder structure based on user ID
            $user_id = $_SESSION['user_id'];
            $subfolder1 = substr($user_id, 0, 2);
            $subfolder2 = substr($user_id, 2, 2);
            
            $upload_dir = "images/avatars/{$subfolder1}/{$subfolder2}";
            
            // Create directories if they don't exist
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate unique filename
            $filename = 'avatar_' . $_SESSION['user_id'] . '_' . time() . '.' . $image_type;
            $upload_path = $upload_dir . '/' . $filename;
            
            // Decode and save the image
            $base64_data = $image_parts[1];
            $data = base64_decode($base64_data);
            
            if (file_put_contents($upload_path, $data)) {
                // Delete old avatar if exists and not default
                if ($avatar_url && $avatar_url !== 'images/default-avatar.png' && file_exists($avatar_url)) {
                    unlink($avatar_url);
                }
                
                $avatar_url = $upload_path;
            } else {
                $error = 'Failed to save profile picture. Please try again.';
            }
        }
    }
    
    // Validate current password if trying to change password
    $passwordChanged = false;
    if ($new_password) {
        if (!$current_password) {
            $error = 'Current password is required to set a new password';
        } else {
            // Verify current password
            $stmt = $db->prepare('SELECT password FROM users WHERE id = ?');
            $stmt->execute([$_SESSION['user_id']]);
            $storedPassword = $stmt->fetchColumn();
            
            if (!password_verify($current_password, $storedPassword)) {
                $error = 'Current password is incorrect';
            } elseif ($new_password !== $confirm_password) {
                $error = 'New passwords do not match';
            } else {
                // Password is valid, will be updated
                $passwordChanged = true;
            }
        }
    }
    
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
    
    // Update user data if no errors
    if (!isset($error)) {
        if ($passwordChanged) {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $db->prepare('UPDATE users SET mmr = ?, race_preference = ?, password = ?, avatar_url = ? WHERE id = ?');
            $stmt->execute([$mmr, $race_preference, $password_hash, $avatar_url, $_SESSION['user_id']]);
        } else {
            $stmt = $db->prepare('UPDATE users SET mmr = ?, race_preference = ?, avatar_url = ? WHERE id = ?');
            $stmt->execute([$mmr, $race_preference, $avatar_url, $_SESSION['user_id']]);
        }
        
        $success = 'Profile updated successfully';
        
        // Refresh user data
        $stmt = $db->prepare('SELECT id, username, email, role, mmr, race_preference, avatar_url FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Set page title
$pageTitle = "Edit Profile";

// Include header
include_once 'includes/header.php';
?>

<div class="container mt-4">
    <div class="admin-header">
        <h1><i class="fas fa-user-edit"></i> Edit Your Profile</h1>
        <div class="admin-user-info">
            <span>Logged in as: <?= htmlspecialchars($_SESSION['username'] ?? 'Unknown') ?></span>
            <a href="logout.php" class="btn btn-logout">Logout</a>
        </div>
    </div>
    
    <div class="profile-container">
        <div class="profile-box">
            <h2>Edit Your Profile</h2>
            <?php if (isset($error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if (isset($success)): ?>
                <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <form method="POST" action="edit_profile.php" enctype="multipart/form-data" id="profile-form">
                <div class="profile-picture-upload">
                    <div class="current-avatar">
                        <img src="<?php echo !empty($user['avatar_url']) ? htmlspecialchars($user['avatar_url']) : 'images/default-avatar.png'; ?>" alt="Profile Picture" id="avatar-preview">
                    </div>
                    <div class="form-group">
                        <label for="profile_picture">Profile Picture</label>
                        <input type="file" id="profile_picture" name="profile_picture" accept="image/jpeg, image/png, image/gif">
                        <div class="upload-notice">
                            <small class="form-text text-muted">
                                <strong>Server upload limit:</strong> <?php echo htmlspecialchars($max_upload_size); ?><br>
                                <strong>Recommended max size:</strong> 5 MB<br>
                                <strong>Supported formats:</strong> JPG, PNG, GIF<br>
                                <strong>Image processing:</strong> Your image will be cropped to a square and resized to 200x200 pixels while maintaining aspect ratio from the center.
                            </small>
                        </div>
                    </div>
                    <!-- Hidden field to store resized image data -->
                    <input type="hidden" name="image_data" id="image_data">
                </div>
                
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                    <small class="form-text text-muted">Username cannot be changed</small>
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                    <small class="form-text text-muted">Email cannot be changed</small>
                </div>
                <div class="form-group">
                    <label for="role">Role</label>
                    <input type="text" id="role" value="<?php echo ucfirst(htmlspecialchars($user['role'])); ?>" disabled>
                    <small class="form-text text-muted">Role cannot be changed</small>
                </div>
                <div class="form-group">
                    <label for="mmr">MMR (Optional)</label>
                    <input type="number" id="mmr" name="mmr" min="0" max="8000" value="<?php echo htmlspecialchars($user['mmr'] ?? ''); ?>">
                    <small class="form-text text-muted">Your current MMR in StarCraft II. Leave empty if unknown.</small>
                </div>
                <div class="form-group">
                    <label for="race_preference">Preferred Race</label>
                    <select id="race_preference" name="race_preference">
                        <option value="">Select a race</option>
                        <option value="Protoss" <?php echo ($user['race_preference'] === 'Protoss') ? 'selected' : ''; ?>>Protoss</option>
                        <option value="Terran" <?php echo ($user['race_preference'] === 'Terran') ? 'selected' : ''; ?>>Terran</option>
                        <option value="Zerg" <?php echo ($user['race_preference'] === 'Zerg') ? 'selected' : ''; ?>>Zerg</option>
                        <option value="Random" <?php echo ($user['race_preference'] === 'Random') ? 'selected' : ''; ?>>Random</option>
                    </select>
                </div>
                <h3 class="password-section-title">Change Password (Optional)</h3>
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password">
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password">
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password">
                </div>
                <div class="form-actions">
                    <button type="submit" class="primary-btn">Save Changes</button>
                    <a href="profile.php" class="secondary-btn">Cancel</a>
                </div>
            </form>
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
    
    h2 {
        color: #00d4ff;
        margin-bottom: 20px;
        text-align: center;
    }
    
    .profile-container {
        display: flex;
        justify-content: center;
        align-items: flex-start;
        min-height: 60vh;
    }
    
    .profile-box {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 15px;
        padding: 30px;
        width: 100%;
        max-width: 600px;
        border: 1px solid rgba(0, 212, 255, 0.2);
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        color: #00d4ff;
        font-weight: 600;
        font-size: 14px;
    }
    
    .form-group input,
    .form-group select {
        width: 100%;
        padding: 12px;
        border: 1px solid rgba(0, 212, 255, 0.3);
        border-radius: 8px;
        background: rgba(0, 0, 0, 0.5);
        color: #e0e0e0;
        font-size: 14px;
        transition: all 0.3s ease;
    }
    
    .form-group input:focus,
    .form-group select:focus {
        outline: none;
        border-color: #00d4ff;
        box-shadow: 0 0 15px rgba(0, 212, 255, 0.3);
        background: rgba(0, 0, 0, 0.7);
    }
    
    .form-group input:disabled {
        background: rgba(0, 0, 0, 0.3);
        color: #888;
        cursor: not-allowed;
    }
    
    .form-text {
        color: #ccc;
        font-size: 12px;
        margin-top: 5px;
        display: block;
    }
    
    .profile-picture-upload {
        text-align: center;
        margin-bottom: 30px;
        padding: 20px;
        background: rgba(0, 0, 0, 0.2);
        border-radius: 10px;
        border: 1px solid rgba(0, 212, 255, 0.2);
    }
    
    .current-avatar {
        margin-bottom: 20px;
    }
    
    .current-avatar img {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        border: 3px solid #00d4ff;
        box-shadow: 0 0 20px rgba(0, 212, 255, 0.3);
        object-fit: cover;
    }
    
    .upload-notice {
        margin-top: 15px;
        padding: 15px;
        background: rgba(0, 212, 255, 0.1);
        border-radius: 8px;
        border: 1px solid rgba(0, 212, 255, 0.2);
    }
    
    .upload-notice small {
        color: #ccc;
        line-height: 1.5;
    }
    
    .password-section-title {
        color: #00d4ff;
        margin: 30px 0 20px 0;
        padding-bottom: 10px;
        border-bottom: 2px solid rgba(0, 212, 255, 0.3);
    }
    
    .form-actions {
        display: flex;
        gap: 15px;
        justify-content: center;
        margin-top: 30px;
    }
    
    .primary-btn, .secondary-btn {
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.3s ease;
        display: inline-block;
    }
    
    .primary-btn {
        background: linear-gradient(135deg, #00d4ff, #0050ff);
        color: #0f0c29;
        box-shadow: 0 4px 15px rgba(0, 212, 255, 0.3);
    }
    
    .primary-btn:hover {
        background: linear-gradient(135deg, #0050ff, #00d4ff);
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 212, 255, 0.4);
    }
    
    .secondary-btn {
        background: rgba(108, 117, 125, 0.8);
        color: white;
        border: 1px solid rgba(108, 117, 125, 0.3);
    }
    
    .secondary-btn:hover {
        background: rgba(108, 117, 125, 1);
        transform: translateY(-2px);
    }
    
    .error-message {
        background: rgba(220, 53, 69, 0.2);
        border: 1px solid #dc3545;
        color: #dc3545;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        text-align: center;
        font-weight: 500;
    }
    
    .success-message {
        background: rgba(40, 167, 69, 0.2);
        border: 1px solid #28a745;
        color: #28a745;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        text-align: center;
        font-weight: 500;
    }
    
    .btn-logout {
        background: #dc3545;
        color: white;
        padding: 8px 16px;
        border-radius: 5px;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    
    .btn-logout:hover {
        opacity: 0.8;
        transform: translateY(-1px);
    }
    
    /* File input styling */
    input[type="file"] {
        background: rgba(0, 0, 0, 0.5);
        border: 1px solid rgba(0, 212, 255, 0.3);
        color: #e0e0e0;
        padding: 10px;
        border-radius: 5px;
        cursor: pointer;
    }
    
    input[type="file"]:hover {
        border-color: #00d4ff;
        box-shadow: 0 0 10px rgba(0, 212, 255, 0.2);
    }
    
    /* Responsive design */
    @media (max-width: 768px) {
        .container {
            padding: 10px;
        }
        
        .admin-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
        
        h1 {
            font-size: 2em;
        }
        
        .profile-box {
            padding: 20px;
            margin: 10px;
        }
        
        .form-actions {
            flex-direction: column;
            align-items: center;
        }
        
        .primary-btn, .secondary-btn {
            width: 100%;
            max-width: 200px;
        }
    }
</style>

<!-- JavaScript for image preview and resizing -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('profile_picture');
    const imagePreview = document.getElementById('avatar-preview');
    const imageData = document.getElementById('image_data');
    const form = document.getElementById('profile-form');
    
    // Handle file selection
    fileInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            const file = this.files[0];
            const reader = new FileReader();
            
            reader.onload = function(e) {
                // Create an image element to get dimensions
                const img = new Image();
                img.onload = function() {
                    // Create canvas for resizing
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    
                    // Set canvas size to 200x200
                    canvas.width = 200;
                    canvas.height = 200;
                    
                    // Calculate dimensions to maintain aspect ratio
                    let sourceX = 0, sourceY = 0, sourceWidth = img.width, sourceHeight = img.height;
                    
                    if (img.width > img.height) {
                        // Landscape image
                        sourceWidth = img.height;
                        sourceX = (img.width - sourceWidth) / 2;
                    } else if (img.height > img.width) {
                        // Portrait image
                        sourceHeight = img.width;
                        sourceY = (img.height - sourceHeight) / 2;
                    }
                    
                    // Draw the image on the canvas (cropping to square and resizing)
                    ctx.drawImage(img, sourceX, sourceY, sourceWidth, sourceHeight, 0, 0, 200, 200);
                    
                    // Get the resized image data
                    const resizedImageData = canvas.toDataURL(file.type);
                    
                    // Update preview and store data
                    imagePreview.src = resizedImageData;
                    imageData.value = resizedImageData;
                };
                
                img.src = e.target.result;
            };
            
            reader.readAsDataURL(file);
        }
    });
    
    // Handle form submission
    form.addEventListener('submit', function(e) {
        // If we have resized image data, we can proceed with the form submission
        // The PHP code will handle the base64 data
    });
});
</script>

<?php
// Include footer
include_once 'includes/footer.php';
?> 