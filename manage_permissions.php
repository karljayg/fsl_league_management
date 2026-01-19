<?php
/**
 * Simplified Permissions Management
 * Clean, intuitive interface for managing permissions and role assignments
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
        die("Permission denied: You must be an admin to manage permissions.");
    }
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($_POST['action']) {
            case 'add_permission':
                $stmt = $db->prepare("INSERT INTO ws_permissions (permission_name, description) VALUES (?, ?)");
                $stmt->execute([$_POST['permission_name'], $_POST['description']]);
                $message = "Permission '{$_POST['permission_name']}' added successfully!";
                break;
                
            case 'edit_permission':
                $stmt = $db->prepare("UPDATE ws_permissions SET permission_name = ?, description = ? WHERE permission_id = ?");
                $stmt->execute([$_POST['permission_name'], $_POST['description'], $_POST['permission_id']]);
                $message = "Permission updated successfully!";
                break;
                
            case 'delete_permission':
                // First remove all role assignments for this permission
                $stmt = $db->prepare("DELETE FROM ws_role_permissions WHERE permission_id = ?");
                $stmt->execute([$_POST['permission_id']]);
                
                // Then delete the permission
                $stmt = $db->prepare("DELETE FROM ws_permissions WHERE permission_id = ?");
                $stmt->execute([$_POST['permission_id']]);
                $message = "Permission deleted successfully!";
                break;
                
            case 'assign_permission':
                $stmt = $db->prepare("INSERT IGNORE INTO ws_role_permissions (role_id, permission_id) VALUES (?, ?)");
                $stmt->execute([$_POST['role_id'], $_POST['permission_id']]);
                $message = "Permission assigned to role successfully!";
                break;
                
            case 'remove_permission':
                $stmt = $db->prepare("DELETE FROM ws_role_permissions WHERE role_id = ? AND permission_id = ?");
                $stmt->execute([$_POST['role_id'], $_POST['permission_id']]);
                $message = "Permission removed from role successfully!";
                break;
                
            case 'add_role':
                $stmt = $db->prepare("INSERT INTO ws_roles (role_name, description) VALUES (?, ?)");
                $stmt->execute([$_POST['role_name'], $_POST['description']]);
                $message = "Role '{$_POST['role_name']}' added successfully!";
                break;
                
            case 'edit_role':
                $stmt = $db->prepare("UPDATE ws_roles SET role_name = ?, description = ? WHERE role_id = ?");
                $stmt->execute([$_POST['role_name'], $_POST['description'], $_POST['role_id']]);
                $message = "Role updated successfully!";
                break;
                
            case 'delete_role':
                // First remove all permission assignments for this role
                $stmt = $db->prepare("DELETE FROM ws_role_permissions WHERE role_id = ?");
                $stmt->execute([$_POST['role_id']]);
                
                // Then remove all user assignments for this role
                $stmt = $db->prepare("DELETE FROM ws_user_roles WHERE role_id = ?");
                $stmt->execute([$_POST['role_id']]);
                
                // Finally delete the role
                $stmt = $db->prepare("DELETE FROM ws_roles WHERE role_id = ?");
                $stmt->execute([$_POST['role_id']]);
                $message = "Role deleted successfully!";
                break;
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Get all permissions
$permissions = [];
try {
    $stmt = $db->query("SELECT * FROM ws_permissions ORDER BY permission_id");
    $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching permissions: " . $e->getMessage();
}

// Get all roles
$roles = [];
try {
    $stmt = $db->query("SELECT * FROM ws_roles ORDER BY role_id");
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching roles: " . $e->getMessage();
}

// Get role-permission assignments
$rolePermissions = [];
try {
    $stmt = $db->query("
        SELECT rp.role_id, rp.permission_id, r.role_name, p.permission_name
        FROM ws_role_permissions rp
        JOIN ws_roles r ON rp.role_id = r.role_id
        JOIN ws_permissions p ON rp.permission_id = p.permission_id
        ORDER BY r.role_name, p.permission_name
    ");
    $rolePermissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching role permissions: " . $e->getMessage();
}
?>

<div class="container mt-4">
    <div class="admin-header">
        <h1><i class="fas fa-shield-alt"></i> Permissions Management</h1>
        <div class="admin-user-info">
            <span>Logged in as: <?= htmlspecialchars($_SESSION['username'] ?? 'Unknown') ?></span>
            <a href="logout.php" class="btn btn-logout">Logout</a>
        </div>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="admin-tabs">
        <button class="tab-btn active" onclick="showTab('permissions', event)">Permissions</button>
        <button class="tab-btn" onclick="showTab('roles', event)">Roles</button>
        <button class="tab-btn" onclick="showTab('assignments', event)">Assignments</button>
    </div>
    
    <div id="permissions-tab" class="tab-content active">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3>Manage Permissions</h3>
            <button class="btn btn-primary" onclick="showAddPermissionModal()">Add Permission</button>
        </div>
        
        <div class="row">
            <?php foreach ($permissions as $permission): ?>
                <div class="col-md-6">
                    <div class="permission-card">
                        <h5><?= htmlspecialchars($permission['permission_name']) ?></h5>
                        <p><?= htmlspecialchars($permission['description']) ?></p>
                        <div class="btn-group">
                            <button class="btn btn-sm btn-outline-primary" onclick="editPermission(<?= $permission['permission_id'] ?>, '<?= htmlspecialchars($permission['permission_name']) ?>', '<?= htmlspecialchars($permission['description']) ?>')">Edit</button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deletePermission(<?= $permission['permission_id'] ?>, '<?= htmlspecialchars($permission['permission_name']) ?>')">Delete</button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div id="roles-tab" class="tab-content">
        <h3>Manage Roles</h3>
        <div class="table-responsive">
            <table class="table table-dark">
                <thead>
                    <tr>
                        <th>Role Name</th>
                        <th>Description</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $role): ?>
                        <tr>
                            <td><?= htmlspecialchars($role['role_name']) ?></td>
                            <td><?= htmlspecialchars($role['description']) ?></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" onclick="editRole(<?= $role['role_id'] ?>, '<?= htmlspecialchars($role['role_name']) ?>', '<?= htmlspecialchars($role['description']) ?>')">Edit</button>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteRole(<?= $role['role_id'] ?>, '<?= htmlspecialchars($role['role_name']) ?>')">Delete</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <button class="btn btn-primary mt-3" onclick="showAddRoleModal()">Add New Role</button>
    </div>
    
    <div id="assignments-tab" class="tab-content">
        <h3>Role-Permission Assignments</h3>
        <div class="assignment-section">
            <h4>Assign Permission to Role</h4>
            <form method="POST" class="assignment-form">
                <input type="hidden" name="action" value="assign_permission">
                <div class="form-row">
                    <div class="form-group">
                        <label>Role:</label>
                        <select name="role_id" required>
                            <option value="">Select role...</option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?= $role['role_id'] ?>"><?= htmlspecialchars($role['role_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Permission:</label>
                        <select name="permission_id" required>
                            <option value="">Select permission...</option>
                            <?php foreach ($permissions as $permission): ?>
                                <option value="<?= $permission['permission_id'] ?>"><?= htmlspecialchars($permission['permission_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">Assign Permission</button>
                    </div>
                </div>
            </form>
        </div>
        
        <div class="current-assignments">
            <h4>Current Assignments</h4>
            <?php foreach ($roles as $role): ?>
                <div class="role-section">
                    <h5><?= htmlspecialchars($role['role_name']) ?></h5>
                    <div class="permissions-list">
                        <?php
                        $rolePerms = array_filter($rolePermissions, function($rp) use ($role) {
                            return $rp['role_id'] == $role['role_id'];
                        });
                        ?>
                        <?php if (empty($rolePerms)): ?>
                            <p class="no-permissions">No permissions assigned</p>
                        <?php else: ?>
                            <?php foreach ($rolePerms as $rp): ?>
                                <span class="role-permission">
                                    <?= htmlspecialchars($rp['permission_name']) ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="remove_permission">
                                        <input type="hidden" name="role_id" value="<?= $rp['role_id'] ?>">
                                        <input type="hidden" name="permission_id" value="<?= $rp['permission_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" style="border: none; padding: 0; margin-left: 5px;" onclick="return confirm('Remove this permission?')">×</button>
                                    </form>
                                </span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Add Permission Modal -->
<div id="addPermissionModal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="close" onclick="closeAddPermissionModal()">&times;</span>
        <h3>Add New Permission</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_permission">
            <div class="form-group">
                <label>Permission Name:</label>
                <input type="text" name="permission_name" required>
            </div>
            <div class="form-group">
                <label>Description:</label>
                <textarea name="description" rows="3"></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeAddPermissionModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Permission</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Permission Modal -->
<div id="editPermissionModal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="close" onclick="closeEditPermissionModal()">&times;</span>
        <h3>Edit Permission</h3>
        <form method="POST">
            <input type="hidden" name="action" value="edit_permission">
            <input type="hidden" name="permission_id" id="edit_permission_id">
            <div class="form-group">
                <label>Permission Name:</label>
                <input type="text" name="permission_name" id="edit_permission_name" required>
            </div>
            <div class="form-group">
                <label>Description:</label>
                <textarea name="description" id="edit_permission_description" rows="3"></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeEditPermissionModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Permission</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Role Modal -->
<div id="editRoleModal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="close" onclick="closeEditRoleModal()">&times;</span>
        <h3>Edit Role</h3>
        <form method="POST">
            <input type="hidden" name="action" value="edit_role">
            <input type="hidden" name="role_id" id="edit_role_id">
            <div class="form-group">
                <label>Role Name:</label>
                <input type="text" name="role_name" id="edit_role_name" required>
            </div>
            <div class="form-group">
                <label>Description:</label>
                <textarea name="description" id="edit_role_description" rows="3"></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeEditRoleModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Role</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Role Modal -->
<div id="addRoleModal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="close" onclick="closeAddRoleModal()">&times;</span>
        <h3>Add New Role</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_role">
            <div class="form-group">
                <label>Role Name:</label>
                <input type="text" name="role_name" required>
            </div>
            <div class="form-group">
                <label>Description:</label>
                <textarea name="description" rows="3"></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeAddRoleModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Role</button>
            </div>
        </form>
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
    
    .admin-tabs {
        display: flex;
        margin-bottom: 30px;
        border-bottom: 2px solid rgba(0, 212, 255, 0.2);
    }
    
    .tab-btn {
        background: transparent;
        border: none;
        color: #ccc;
        padding: 15px 25px;
        cursor: pointer;
        border-bottom: 3px solid transparent;
        transition: all 0.3s ease;
    }
    
    .tab-btn.active,
    .tab-btn:hover {
        color: #00d4ff;
        border-bottom-color: #00d4ff;
    }
    
    .tab-content {
        display: none;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        padding: 25px;
    }
    
    .tab-content.active {
        display: block;
    }
    
    .permission-card {
        background: rgba(0, 0, 0, 0.3);
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
        border: 1px solid rgba(0, 212, 255, 0.2);
    }
    
    .permission-card h5 {
        color: #00d4ff;
        margin: 0 0 10px 0;
    }
    
    .permission-card p {
        color: #ccc;
        margin-bottom: 15px;
    }
    
    .role-permission {
        background: rgba(0, 212, 255, 0.2);
        padding: 5px 10px;
        margin: 2px;
        border-radius: 15px;
        display: inline-block;
        color: #00d4ff;
        border: 1px solid rgba(0, 212, 255, 0.3);
        font-size: 0.9em;
    }
    
    .role-section {
        background: rgba(0, 0, 0, 0.3);
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
        border: 1px solid rgba(0, 212, 255, 0.2);
    }
    
    .role-section h5 {
        color: #00d4ff;
        margin: 0 0 15px 0;
    }
    
    .no-permissions {
        color: #888;
        font-style: italic;
    }
    
    .assignment-section {
        background: rgba(0, 0, 0, 0.3);
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 30px;
        border: 1px solid rgba(0, 212, 255, 0.2);
    }
    
    .assignment-section h4 {
        color: #00d4ff;
        margin: 0 0 20px 0;
    }
    
    .form-row {
        display: flex;
        gap: 20px;
        align-items: end;
    }
    
    .form-group {
        flex: 1;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 5px;
        color: #00d4ff;
        font-weight: 600;
    }
    
    .form-group select,
    .form-group input,
    .form-group textarea {
        width: 100%;
        padding: 10px;
        border-radius: 5px;
        border: 1px solid rgba(0, 212, 255, 0.3);
        background: rgba(0, 0, 0, 0.5);
        color: #e0e0e0;
    }
    
    .form-group select:focus,
    .form-group input:focus,
    .form-group textarea:focus {
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
    
    .modal {
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.8);
    }
    
    .modal-content {
        background: linear-gradient(135deg, #0f0c29, #302b63);
        margin: 5% auto;
        padding: 30px;
        border-radius: 10px;
        width: 80%;
        max-width: 500px;
        border: 1px solid rgba(0, 212, 255, 0.3);
        position: relative;
    }
    
    .close {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
        position: absolute;
        top: 15px;
        right: 20px;
    }
    
    .close:hover {
        color: #00d4ff;
    }
    
    .modal h3 {
        color: #00d4ff;
        margin: 0 0 20px 0;
    }
    
    .form-actions {
        margin-top: 20px;
        text-align: right;
    }
    
    .form-actions .btn {
        margin-left: 10px;
    }
    
    .table-dark {
        background: rgba(0, 0, 0, 0.3);
        color: #e0e0e0;
        border: 1px solid rgba(0, 212, 255, 0.2);
    }
    
    .table-dark th {
        background: rgba(0, 212, 255, 0.1);
        color: #00d4ff;
        border-color: rgba(0, 212, 255, 0.2);
    }
    
    .table-dark td {
        border-color: rgba(0, 212, 255, 0.2);
    }
</style>

<script>
    function showTab(tabName, event) {
        console.log('showTab called with:', tabName); // Debug log
        
        // Hide all tab contents
        document.querySelectorAll('.tab-content').forEach(tab => {
            tab.classList.remove('active');
        });
        
        // Remove active class from all tab buttons
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        
        // Show selected tab content
        document.getElementById(tabName + '-tab').classList.add('active');
        
        // Add active class to clicked button
        if (event && event.target) {
            event.target.classList.add('active');
        }
        
        // Store the current tab in localStorage
        localStorage.setItem('manage_permissions_active_tab', tabName);
        console.log('Tab saved to localStorage:', tabName); // Debug log
    }
    
    // Function to restore the last active tab
    function restoreActiveTab() {
        const lastActiveTab = localStorage.getItem('manage_permissions_active_tab');
        console.log('Restoring tab:', lastActiveTab); // Debug log
        
        if (lastActiveTab) {
            // Hide all tabs first
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show the last active tab
            const tabContent = document.getElementById(lastActiveTab + '-tab');
            // Find the button by looking for the one that calls showTab with this tab name
            const tabButton = Array.from(document.querySelectorAll('.tab-btn')).find(btn => 
                btn.getAttribute('onclick') && btn.getAttribute('onclick').includes(`'${lastActiveTab}'`)
            );
            
            console.log('Tab content found:', !!tabContent); // Debug log
            console.log('Tab button found:', !!tabButton); // Debug log
            
            if (tabContent) {
                tabContent.classList.add('active');
            }
            if (tabButton) {
                tabButton.classList.add('active');
            }
        } else {
            console.log('No saved tab found, staying on default'); // Debug log
        }
    }
    
    function showAddPermissionModal() {
        document.getElementById('addPermissionModal').style.display = 'block';
    }
    
    function closeAddPermissionModal() {
        document.getElementById('addPermissionModal').style.display = 'none';
    }
    
    function editPermission(id, name, description) {
        document.getElementById('edit_permission_id').value = id;
        document.getElementById('edit_permission_name').value = name;
        document.getElementById('edit_permission_description').value = description;
        document.getElementById('editPermissionModal').style.display = 'block';
    }
    
    function closeEditPermissionModal() {
        document.getElementById('editPermissionModal').style.display = 'none';
    }
    
    function deletePermission(id, name) {
        if (confirm(`Are you sure you want to delete permission "${name}"? This will also remove it from all roles.`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_permission">
                <input type="hidden" name="permission_id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    function editRole(id, name, description) {
        document.getElementById('edit_role_id').value = id;
        document.getElementById('edit_role_name').value = name;
        document.getElementById('edit_role_description').value = description;
        document.getElementById('editRoleModal').style.display = 'block';
    }
    
    function closeEditRoleModal() {
        document.getElementById('editRoleModal').style.display = 'none';
    }
    
    function showAddRoleModal() {
        document.getElementById('addRoleModal').style.display = 'block';
    }
    
    function closeAddRoleModal() {
        document.getElementById('addRoleModal').style.display = 'none';
    }
    
    function deleteRole(id, name) {
        if (confirm(`Are you sure you want to delete role "${name}"? This will also remove all permission assignments for this role.`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_role">
                <input type="hidden" name="role_id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
        }
    }
    
    // Restore active tab when page loads
    document.addEventListener('DOMContentLoaded', function() {
        restoreActiveTab();
    });
</script>

<?php include 'includes/footer.php'; ?> 
