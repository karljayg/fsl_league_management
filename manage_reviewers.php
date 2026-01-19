<?php
/**
 * FSL Spider Chart Reviewer Management
 * Admin interface to manage reviewers via CSV and individual management
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Include database connection first
require_once 'includes/db.php';
require_once 'includes/reviewer_functions.php';

// Set required permission
$required_permission = 'manage spider charts';

// Include permission check
require_once 'includes/check_permission.php';

$message = '';
$error = '';

// Database operations now handled by reviewer_functions.php

// Database operations now handled by reviewer_functions.php

// Function to get the full URL for a reviewer
function getReviewerUrl($token) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $path = dirname($_SERVER['REQUEST_URI']);
    
    // Ensure path ends with /fsl if it's not already there
    if (!str_ends_with($path, '/fsl')) {
        $path = rtrim($path, '/') . '/fsl';
    }
    
    // Handle edge cases where path might be empty or just /
    if ($path === '/' || empty($path)) {
        $path = '/fsl';
    }
    
    return $protocol . '://' . $host . $path . '/score_match.php?token=' . $token;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add_reviewer':
                    // Auto-generate unique URL if not provided
                    $unique_url = !empty($_POST['unique_url']) ? $_POST['unique_url'] : generateReviewerToken();
                    
                    $new_id = createReviewer(
                        $_POST['name'], 
                        $unique_url, 
                        floatval($_POST['weight']), 
                        $_POST['status']
                    );
                    
                    if ($new_id) {
                        $message = "Reviewer added successfully!";
                    } else {
                        $error = "Failed to save reviewer.";
                    }
                    break;
                    
                case 'update_reviewer':
                    $id = intval($_POST['reviewer_id']);
                    
                    $update_data = [
                        'name' => $_POST['name'],
                        'unique_url' => $_POST['unique_url'],
                        'weight' => floatval($_POST['weight']),
                        'status' => $_POST['status']
                    ];
                    
                    if (updateReviewer($id, $update_data)) {
                        $message = "Reviewer updated successfully!";
                    } else {
                        $error = "Failed to update reviewer.";
                    }
                    break;
                    
                case 'delete_reviewer':
                    $id = intval($_POST['reviewer_id']);
                    
                    if (deleteReviewer($id)) {
                        $message = "Reviewer deleted successfully!";
                    } else {
                        $error = "Failed to delete reviewer.";
                    }
                    break;
                    
                case 'regenerate_url':
                    $id = intval($_POST['reviewer_id']);
                    
                    $new_token = generateReviewerToken();
                    $update_data = ['unique_url' => $new_token];
                    
                    if (updateReviewer($id, $update_data)) {
                        $message = "URL regenerated successfully!";
                    } else {
                        $error = "Failed to regenerate URL.";
                    }
                    break;
            }
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Token generation now handled by reviewer_functions.php

// Get all reviewers from database
$reviewers = getReviewers('all');

// Get voting statistics from database
$voting_stats = [];
foreach ($reviewers as $reviewer) {
    // Get total votes for this reviewer
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_votes,
            COUNT(DISTINCT fsl_match_id) as matches_voted
        FROM Player_Attribute_Votes 
        WHERE reviewer_id = ?
    ");
    $stmt->execute([$reviewer['id']]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $voting_stats[] = [
        'id' => $reviewer['id'],
        'name' => $reviewer['name'],
        'total_votes' => $stats['total_votes'] ?? 0,
        'matches_voted' => $stats['matches_voted'] ?? 0
    ];
}

// Set page title
$pageTitle = "FSL Spider Chart Reviewer Management";

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <div class="admin-header">
        <h1><i class="fas fa-user-friends"></i> FSL Spider Chart Reviewer Management</h1>
        <div class="admin-user-info">
            <a href="spider_chart_admin.php" class="btn btn-secondary">← Back to Dashboard</a>
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
        <button class="tab-btn active" onclick="showTab('reviewers', event)">Reviewers</button>
        <button class="tab-btn" onclick="showTab('stats', event)">Voting Statistics</button>
        <button class="tab-btn" onclick="showTab('add', event)">Add Reviewer</button>
    </div>

    <!-- Reviewers Tab -->
    <div id="reviewers-tab" class="tab-content active">
        <h2>Current Reviewers</h2>
        
        <div class="reviewers-table">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Voting URL</th>
                        <th>Weight</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reviewers as $reviewer): ?>
                        <tr>
                            <td><?= htmlspecialchars($reviewer['name']) ?></td>
                                                                                <td>
                                <code><?= htmlspecialchars(getReviewerUrl($reviewer['unique_url'])) ?></code>
                                <button class="btn btn-sm btn-link" onclick="copyToClipboard('<?= htmlspecialchars(getReviewerUrl($reviewer['unique_url'])) ?>')">Copy URL</button>
                            </td>
                            <td><?= $reviewer['weight'] ?></td>
                            <td>
                                <span class="status-badge <?= $reviewer['status'] ?>">
                                    <?= ucfirst($reviewer['status']) ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-primary" onclick="editReviewer(<?= $reviewer['id'] ?>, '<?= htmlspecialchars($reviewer['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($reviewer['unique_url'], ENT_QUOTES) ?>', <?= $reviewer['weight'] ?>, '<?= htmlspecialchars($reviewer['status'], ENT_QUOTES) ?>')">Edit</button>
                                <button class="btn btn-sm btn-warning" onclick="regenerateUrl(<?= $reviewer['id'] ?>)">Regenerate URL</button>
                                <button class="btn btn-sm btn-danger" onclick="deleteReviewer(<?= $reviewer['id'] ?>)">Delete</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Voting Statistics Tab -->
    <div id="stats-tab" class="tab-content">
        <h2>Voting Statistics</h2>
        
        <div class="stats-table">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Reviewer</th>
                        <th>Total Votes</th>
                        <th>Matches Voted</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($voting_stats as $stat): ?>
                        <tr>
                            <td><?= htmlspecialchars($stat['name']) ?></td>
                            <td><?= $stat['total_votes'] ?></td>
                            <td><?= $stat['matches_voted'] ?></td>
                            <td>
                                <?php 
                                $reviewer = array_filter($reviewers, function($r) use ($stat) { return $r['id'] == $stat['id']; });
                                $reviewer = reset($reviewer);
                                ?>
                                <span class="status-badge <?= $reviewer['status'] ?>">
                                    <?= ucfirst($reviewer['status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add Reviewer Tab -->
    <div id="add-tab" class="tab-content">
        <h2>Add New Reviewer</h2>
        
        <form method="post" class="add-reviewer-form">
            <input type="hidden" name="action" value="add_reviewer">
            
            <div class="form-group">
                <label>Name:</label>
                <input type="text" name="name" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label>Unique URL:</label>
                <input type="text" name="unique_url" class="form-control" placeholder="Leave empty to auto-generate">
                <small>Leave empty to auto-generate a secure token</small>
            </div>
            
            <div class="form-group">
                <label>Weight:</label>
                <input type="number" name="weight" class="form-control" step="0.01" min="0.01" max="10.00" value="1.00" required>
                <small>Default is 1.0. Your weight could be 2.0 for double voting power.</small>
            </div>
            
            <div class="form-group">
                <label>Status:</label>
                <select name="status" class="form-control">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            
            <button type="submit" class="btn btn-primary">Add Reviewer</button>
        </form>
    </div>
</div>

<!-- Edit Reviewer Modal -->
<div id="editModal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="close" onclick="closeEditModal()">&times;</span>
        <h2>Edit Reviewer</h2>
        <form id="editForm" method="post">
            <input type="hidden" name="action" value="update_reviewer">
            <input type="hidden" name="reviewer_id" id="edit_reviewer_id">
            
            <div class="form-group">
                <label>Name:</label>
                <input type="text" name="name" id="edit_name" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label>Unique URL:</label>
                <input type="text" name="unique_url" id="edit_unique_url" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label>Weight:</label>
                <input type="number" name="weight" id="edit_weight" class="form-control" step="0.01" min="0.01" max="10.00" required>
            </div>
            
            <div class="form-group">
                <label>Status:</label>
                <select name="status" id="edit_status" class="form-control">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            
            <button type="submit" class="btn btn-primary">Update Reviewer</button>
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
    
    .csv-section {
        background: rgba(0, 0, 0, 0.3);
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
    }
    
    .csv-section h3 {
        color: #00d4ff;
        margin-bottom: 15px;
    }
    
    .csv-form {
        margin-bottom: 20px;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 5px;
        color: #00d4ff;
        font-weight: 600;
    }
    
    .form-control {
        width: 100%;
        padding: 10px;
        border-radius: 4px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        background: rgba(0, 0, 0, 0.3);
        color: #e0e0e0;
        box-sizing: border-box;
    }
    
    .btn {
        padding: 8px 16px;
        border-radius: 4px;
        border: none;
        cursor: pointer;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.3s ease;
        display: inline-block;
    }
    
    .btn-primary { background: #00d4ff; color: #0f0c29; }
    .btn-secondary { background: #6c757d; color: white; }
    .btn-warning { background: #ffc107; color: #0f0c29; }
    .btn-danger { background: #dc3545; color: white; }
    .btn-logout { background: #dc3545; color: white; }
    .btn-link { background: transparent; color: #00d4ff; text-decoration: underline; }
    
    .btn:hover {
        opacity: 0.8;
        text-decoration: none;
    }
    
    .table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }
    
    .table th,
    .table td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        color: #e0e0e0;
    }
    
    .table th {
        background: rgba(0, 212, 255, 0.2);
        color: #ffffff;
        font-weight: 700;
    }
    
    /* Voting Statistics specific styling */
    #stats-tab .table td {
        color: #ffffff;
        font-weight: 500;
    }
    
    #stats-tab .table td:nth-child(2),
    #stats-tab .table td:nth-child(3) {
        color: #00d4ff;
        font-weight: 600;
        font-size: 1.1em;
    }
    
    .status-badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 0.8em;
        font-weight: 600;
    }
    
    .status-badge.active {
        background: #28a745;
        color: white;
    }
    
    .status-badge.inactive {
        background: #6c757d;
        color: white;
    }
    
    .btn-sm {
        padding: 4px 8px;
        font-size: 0.8em;
        margin-right: 5px;
    }
    
    pre {
        background: rgba(0, 0, 0, 0.5);
        padding: 15px;
        border-radius: 5px;
        overflow-x: auto;
        font-size: 0.9em;
    }
    
    code {
        background: rgba(0, 0, 0, 0.5);
        padding: 2px 6px;
        border-radius: 3px;
        font-family: monospace;
    }
    
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
    }
    
    .modal-content {
        background: rgba(0, 0, 0, 0.9);
        margin: 5% auto;
        padding: 20px;
        border-radius: 10px;
        width: 80%;
        max-width: 500px;
        border: 1px solid rgba(0, 212, 255, 0.3);
    }
    
    .close {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }
    
    .close:hover {
        color: #00d4ff;
    }
    
    @media (max-width: 768px) {
        .admin-header {
            flex-direction: column;
            gap: 15px;
        }
        
        .admin-tabs {
            flex-direction: column;
        }
        
        .table {
            font-size: 0.9em;
        }
        
        .btn-sm {
            padding: 3px 6px;
            font-size: 0.7em;
        }
    }
</style>

<script>
    function showTab(tabName, event) {
        // Hide all tabs
        document.querySelectorAll('.tab-content').forEach(tab => {
            tab.classList.remove('active');
        });
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        
        // Show selected tab
        document.getElementById(tabName + '-tab').classList.add('active');
        if (event && event.target) {
            event.target.classList.add('active');
        }
    }
    
    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(() => {
            alert('URL copied to clipboard!');
        }).catch(err => {
            console.error('Failed to copy: ', err);
        });
    }
    
    function editReviewer(id, name, unique_url, weight, status) {
        // Populate the edit modal with reviewer data
        document.getElementById('edit_reviewer_id').value = id;
        document.getElementById('edit_name').value = name;
        document.getElementById('edit_unique_url').value = unique_url;
        document.getElementById('edit_weight').value = weight;
        document.getElementById('edit_status').value = status;
        
        // Show the modal
        document.getElementById('editModal').style.display = 'block';
    }
    
    function regenerateUrl(id) {
        if (confirm('Are you sure you want to regenerate the URL for this reviewer?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="regenerate_url">
                <input type="hidden" name="reviewer_id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    function deleteReviewer(id) {
        if (confirm('Are you sure you want to delete this reviewer? This action cannot be undone.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_reviewer">
                <input type="hidden" name="reviewer_id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }
</script>

<?php include 'includes/footer.php'; ?> 