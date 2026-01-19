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
    <h1><i class="fas fa-users-cog"></i> Manage User Roles</h1>
    
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType === 'error' ? 'danger' : ($messageType === 'warning' ? 'warning' : 'success') ?> message">
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

