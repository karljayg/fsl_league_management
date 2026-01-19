<?php
/**
 * Simplified User Roles Management
 * Clean interface for managing user role assignments
 */

session_start();
require_once 'includes/db.php';
require_once 'includes/header.php';

// Check if user is logged in and has admin role
if (!isset($_SESSION['user_id'])) {
    die("Permission denied: user not logged in.");
}

try {
    $stmt = $db->prepare("
        SELECT COUNT(*) as cnt
        FROM ws_user_roles ur
        JOIN ws_roles r ON ur.role_id = r.role_id
        WHERE ur.user_id = ? AND r.role_name = ?
    ");
    $stmt->execute([$_SESSION['user_id'], 'admin']);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result || $result['cnt'] == 0) {
        die("Permission denied: You must be an admin to manage user roles.");
    }
} catch (PDOException $e) {
    die("Database connection error: " . $e->getMessage());
}

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        try {
            switch ($_POST['action']) {
                case 'assign_role':
                    $user_id = $_POST['user_id'];
                    $role_id = $_POST['role_id'];
                    
                    // Check if assignment already exists
                    $stmt = $db->prepare("SELECT COUNT(*) FROM ws_user_roles WHERE user_id = ? AND role_id = ?");
                    $stmt->execute([$user_id, $role_id]);
                    if ($stmt->fetchColumn() == 0) {
                        $stmt = $db->prepare("INSERT INTO ws_user_roles (user_id, role_id) VALUES (?, ?)");
                        $stmt->execute([$user_id, $role_id]);
                        $message = "Role assigned successfully!";
                        $messageType = "success";
                    } else {
                        $message = "User already has this role.";
                        $messageType = "warning";
                    }
                    break;
                    
                case 'remove_role':
                    $user_id = $_POST['user_id'];
                    $role_id = $_POST['role_id'];
                    
                    $stmt = $db->prepare("DELETE FROM ws_user_roles WHERE user_id = ? AND role_id = ?");
                    $stmt->execute([$user_id, $role_id]);
                    $message = "Role removed successfully!";
                    $messageType = "success";
                    break;
                    
                case 'edit_user_roles':
                    $user_id = $_POST['user_id'];
                    $selected_roles = $_POST['roles'] ?? [];
                    
                    // Remove all existing roles for this user
                    $stmt = $db->prepare("DELETE FROM ws_user_roles WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    
                    // Add selected roles
                    if (!empty($selected_roles)) {
                        $stmt = $db->prepare("INSERT INTO ws_user_roles (user_id, role_id) VALUES (?, ?)");
                        foreach ($selected_roles as $role_id) {
                            $stmt->execute([$user_id, $role_id]);
                        }
                    }
                    
                    $message = "User roles updated successfully!";
                    $messageType = "success";
                    break;
            }
        } catch (PDOException $e) {
            $message = "Error: " . $e->getMessage();
            $messageType = "error";
        }
    }
}

// Get all users
$users = $db->query("SELECT id, username, email FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Get all roles
$roles = $db->query("SELECT role_id, role_name, description FROM ws_roles ORDER BY role_name")->fetchAll(PDO::FETCH_ASSOC);

// Get current user-role assignments
$assignments = $db->query("
    SELECT ur.user_id, ur.role_id, u.username, r.role_name
    FROM ws_user_roles ur
    JOIN users u ON ur.user_id = u.id
    JOIN ws_roles r ON ur.role_id = r.role_id
    ORDER BY u.username, r.role_name
")->fetchAll(PDO::FETCH_ASSOC);

// Group assignments by user for easier display
$userAssignments = [];
foreach ($assignments as $assignment) {
    $userAssignments[$assignment['user_id']][] = $assignment;
}
?>

<div class="container mt-4">
    <div class="admin-header">
        <h1><i class="fas fa-users-cog"></i> Manage User Roles</h1>
        <div class="admin-user-info">
            <span>Logged in as: <?= htmlspecialchars($_SESSION['username'] ?? 'Unknown') ?></span>
            <a href="logout.php" class="btn btn-logout">Logout</a>
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
        
        h5 {
            color: #00d4ff;
            margin: 0;
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
        
        .alert-warning {
            background: rgba(255, 193, 7, 0.2);
            border: 1px solid #ffc107;
            color: #ffc107;
        }
        
        .card { 
            margin-bottom: 20px; 
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.3);
            background: rgba(26, 26, 46, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
        }
        
        .card-header {
            background: rgba(0, 212, 255, 0.1);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            color: #e0e0e0;
            padding: 15px 20px;
            border-radius: 10px 10px 0 0;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .assignment-item { 
            background: rgba(0, 212, 255, 0.1); 
            padding: 10px; 
            margin: 5px 0; 
            border-radius: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid rgba(0, 212, 255, 0.3);
            color: #e0e0e0;
        }
        
        .form-label {
            color: #00d4ff;
            font-weight: 600;
            margin-bottom: 8px;
            display: block;
        }
        
        .form-control, .form-select {
            background-color: rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(0, 212, 255, 0.3);
            color: #e0e0e0;
            padding: 10px;
            border-radius: 5px;
            width: 100%;
        }
        
        .form-control:focus, .form-select:focus {
            background-color: rgba(0, 0, 0, 0.5);
            border-color: #00d4ff;
            color: #e0e0e0;
            box-shadow: 0 0 10px rgba(0, 212, 255, 0.3);
            outline: none;
        }
        
        .form-check {
            margin-bottom: 8px;
        }
        
        .form-check-label {
            color: #e0e0e0;
            margin-left: 8px;
        }
        
        .form-check-input {
            background-color: rgba(0, 0, 0, 0.5);
            border-color: rgba(0, 212, 255, 0.3);
        }
        
        .form-check-input:checked {
            background-color: #00d4ff;
            border-color: #00d4ff;
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
        }
        
        .btn-primary {
            background: #00d4ff;
            color: #0f0c29;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-outline-primary {
            background: transparent;
            color: #00d4ff;
            border: 1px solid #00d4ff;
        }
        
        .btn-outline-danger {
            background: transparent;
            color: #dc3545;
            border: 1px solid #dc3545;
        }
        
        .btn-outline-primary:hover {
            background: #00d4ff;
            color: #0f0c29;
        }
        
        .btn-outline-danger:hover {
            background: #dc3545;
            color: white;
        }
        
        .btn:hover {
            opacity: 0.8;
            transform: translateY(-1px);
        }
        
        .btn-sm {
            padding: 4px 8px;
            font-size: 0.8em;
        }
        
        .btn-logout {
            background: #dc3545;
            color: white;
        }
        
        .text-muted {
            color: #888;
            font-style: italic;
        }
        
        /* Modal styling */
        .modal-content {
            background: linear-gradient(135deg, #0f0c29, #302b63);
            border: 1px solid rgba(0, 212, 255, 0.3);
            border-radius: 10px;
        }
        
        .modal-header {
            background: rgba(0, 212, 255, 0.1);
            border-bottom: 1px solid rgba(0, 212, 255, 0.2);
            color: #e0e0e0;
        }
        
        .modal-title {
            color: #00d4ff;
        }
        
        .modal-body {
            color: #e0e0e0;
        }
        
        .modal-footer {
            border-top: 1px solid rgba(0, 212, 255, 0.2);
        }
        
        .btn-close {
            background: transparent;
            border: none;
            color: #ccc;
            font-size: 1.5rem;
            opacity: 0.7;
        }
        
        .btn-close:hover {
            opacity: 1;
            color: #00d4ff;
        }
    </style>
    
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType === 'error' ? 'error' : ($messageType === 'warning' ? 'warning' : 'success') ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>
    
    <div class="row">
        <!-- Assign New Role -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-plus-circle"></i> Assign New Role</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="assign_role">
                        
                        <div class="mb-3">
                            <label for="user_id" class="form-label">User</label>
                            <select name="user_id" id="user_id" class="form-select" required>
                                <option value="">Select User</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?> (<?= htmlspecialchars($user['email']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="role_id" class="form-label">Role</label>
                            <select name="role_id" id="role_id" class="form-select" required>
                                <option value="">Select Role</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= $role['role_id'] ?>"><?= htmlspecialchars($role['role_name']) ?> - <?= htmlspecialchars($role['description']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Assign Role
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Current Assignments -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-list"></i> Current Role Assignments</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($userAssignments)): ?>
                        <p class="text-muted">No role assignments found.</p>
                    <?php else: ?>
                        <?php foreach ($userAssignments as $userId => $userRoles): ?>
                            <div class="mb-3">
                                <strong><?= htmlspecialchars($users[array_search($userId, array_column($users, 'id'))]['username'] ?? 'Unknown User') ?></strong>
                                <?php foreach ($userRoles as $assignment): ?>
                                    <div class="assignment-item">
                                        <span><?= htmlspecialchars($assignment['role_name']) ?></span>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="remove_role">
                                            <input type="hidden" name="user_id" value="<?= $assignment['user_id'] ?>">
                                            <input type="hidden" name="role_id" value="<?= $assignment['role_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this role?')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                                
                                <button class="btn btn-sm btn-outline-primary" onclick="editUserRoles('<?= $userId ?>', '<?= htmlspecialchars($users[array_search($userId, array_column($users, 'id'))]['username'] ?? 'Unknown User') ?>')">
                                    <i class="fas fa-edit"></i> Edit All Roles
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="mt-3">
        <a href="index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Home
        </a>
    </div>
</div>

<!-- Edit User Roles Modal -->
<div class="modal fade" id="editUserRolesModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit User Roles</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editRolesForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_user_roles">
                    <input type="hidden" name="user_id" id="editUserId">
                    
                    <div class="mb-3">
                        <label class="form-label">User: <strong id="editUsername"></strong></label>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Select Roles:</label>
                        <?php foreach ($roles as $role): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="roles[]" value="<?= $role['role_id'] ?>" id="role_<?= $role['role_id'] ?>">
                                <label class="form-check-label" for="role_<?= $role['role_id'] ?>">
                                    <?= htmlspecialchars($role['role_name']) ?> - <?= htmlspecialchars($role['description']) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Roles</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function editUserRoles(userId, username) {
        // Show edit modal
        document.getElementById('editUserId').value = userId;
        document.getElementById('editUsername').textContent = username;
        
        // Get current roles for this user and check them
        const currentUserRoles = <?= json_encode($userAssignments) ?>;
        const userRoles = currentUserRoles[userId] || [];
        const currentRoleIds = userRoles.map(role => role.role_id.toString());
        
        // Check current roles
        document.querySelectorAll('#editRolesForm input[type="checkbox"]').forEach(checkbox => {
            checkbox.checked = currentRoleIds.includes(checkbox.value);
        });
        
        new bootstrap.Modal(document.getElementById('editUserRolesModal')).show();
    }
</script>

<?php include 'includes/footer.php'; ?>
