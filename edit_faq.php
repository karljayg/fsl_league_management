<?php
/**
 * FAQ Management Page
 * Allows users with 'edit faq' permission to manage frequently asked questions
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Include database connection
require_once 'includes/db.php';

// Check permission
$required_permission = 'faq';
include 'includes/check_permission_updated.php';

// Connect to database
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Handle form submissions
$message = '';
$messageType = '';

// Delete FAQ
if (isset($_POST['delete_faq']) && isset($_POST['faq_id'])) {
    try {
        $stmt = $db->prepare("DELETE FROM FAQ WHERE FAQ_ID = ?");
        $stmt->execute([$_POST['faq_id']]);
        $message = "FAQ deleted successfully!";
        $messageType = "success";
    } catch (PDOException $e) {
        $message = "Error deleting FAQ: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Add or Update FAQ
if (isset($_POST['save_faq'])) {
    try {
        // Check if we're updating an existing FAQ or adding a new one
        if (!empty($_POST['faq_id'])) {
            // Update existing FAQ
            $stmt = $db->prepare("UPDATE FAQ SET Question = ?, Answer = ?, Order_Number = ? WHERE FAQ_ID = ?");
            $stmt->execute([
                $_POST['question'],
                $_POST['answer'],
                $_POST['order_number'],
                $_POST['faq_id']
            ]);
            $message = "FAQ updated successfully!";
        } else {
            // Add new FAQ
            $stmt = $db->prepare("INSERT INTO FAQ (Question, Answer, Order_Number) VALUES (?, ?, ?)");
            $stmt->execute([
                $_POST['question'],
                $_POST['answer'],
                $_POST['order_number']
            ]);
            $message = "FAQ added successfully!";
        }
        $messageType = "success";
    } catch (PDOException $e) {
        $message = "Error saving FAQ: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Reorder FAQs
if (isset($_POST['reorder_faqs'])) {
    try {
        $db->beginTransaction();
        
        foreach ($_POST['order'] as $faqId => $orderNumber) {
            $stmt = $db->prepare("UPDATE FAQ SET Order_Number = ? WHERE FAQ_ID = ?");
            $stmt->execute([$orderNumber, $faqId]);
        }
        
        $db->commit();
        $message = "FAQ order updated successfully!";
        $messageType = "success";
    } catch (PDOException $e) {
        $db->rollBack();
        $message = "Error reordering FAQs: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Get all FAQs
try {
    $stmt = $db->query("SELECT * FROM FAQ ORDER BY Order_Number ASC");
    $faqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = "Error fetching FAQs: " . $e->getMessage();
    $messageType = "danger";
    $faqs = [];
}

// Set page title
$pageTitle = "FAQ Management";

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <div class="admin-header">
        <h1><i class="fas fa-question-circle"></i> FAQ Management</h1>
        <div class="admin-user-info">
            <span>Logged in as: <?= htmlspecialchars($_SESSION['username'] ?? 'Unknown') ?></span>
            <a href="logout.php" class="btn btn-logout">Logout</a>
        </div>
    </div>
    
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    <?php endif; ?>
    
    <!-- Add/Edit FAQ Form -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0" id="form-title">Add New FAQ</h5>
        </div>
        <div class="card-body">
            <form id="faq-form" method="POST" action="">
                <input type="hidden" name="faq_id" id="faq_id" value="">
                
                <div class="form-group">
                    <label for="question">Question:</label>
                    <input type="text" class="form-control" id="question" name="question" required>
                </div>
                
                <div class="form-group">
                    <label for="answer">Answer:</label>
                    <div id="editor-container" style="height: 300px; background-color: rgba(0, 0, 0, 0.2); border-radius: 5px;"></div>
                    <textarea class="form-control" id="answer" name="answer" style="display: none;"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="order_number">Display Order:</label>
                    <input type="number" class="form-control" id="order_number" name="order_number" min="0" value="0">
                </div>
                
                <div class="form-group">
                    <button type="submit" name="save_faq" class="btn btn-primary">Save FAQ</button>
                    <button type="button" id="cancel-edit" class="btn btn-secondary" style="display: none;">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- FAQ List -->
    <div class="card">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0">Existing FAQs</h5>
        </div>
        <div class="card-body">
            <?php if (empty($faqs)): ?>
                <p class="text-muted">No FAQs found. Add your first FAQ using the form above.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-bordered">
                        <thead class="thead-dark">
                            <tr>
                                <th width="5%">Order</th>
                                <th width="30%">Question</th>
                                <th width="45%">Answer</th>
                                <th width="20%">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="faq-list">
                            <?php foreach ($faqs as $faq): ?>
                                <tr data-faq-id="<?php echo $faq['FAQ_ID']; ?>">
                                    <td>
                                        <input type="number" class="form-control form-control-sm order-input" 
                                               value="<?php echo $faq['Order_Number']; ?>" min="0">
                                    </td>
                                    <td><?php echo htmlspecialchars($faq['Question']); ?></td>
                                    <td>
                                        <div class="answer-preview">
                                            <?php echo substr(strip_tags($faq['Answer']), 0, 150); ?>
                                            <?php if (strlen(strip_tags($faq['Answer'])) > 150): ?>...<?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-info edit-faq" 
                                                data-faq-id="<?php echo $faq['FAQ_ID']; ?>"
                                                data-question="<?php echo htmlspecialchars($faq['Question']); ?>"
                                                data-answer="<?php echo htmlspecialchars($faq['Answer']); ?>"
                                                data-order="<?php echo $faq['Order_Number']; ?>">
                                            Edit
                                        </button>
                                        <form method="POST" action="" class="d-inline delete-form">
                                            <input type="hidden" name="faq_id" value="<?php echo $faq['FAQ_ID']; ?>">
                                            <button type="submit" name="delete_faq" class="btn btn-sm btn-danger delete-faq"
                                                    onclick="return confirm('Are you sure you want to delete this FAQ?')">
                                                Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <form method="POST" action="" id="reorder-form">
                    <div id="order-inputs-container"></div>
                    <button type="submit" name="reorder_faqs" class="btn btn-primary">Save Order</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Include Quill -->
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Quill
        var quill = new Quill('#editor-container', {
            modules: {
                toolbar: [
                    [{ 'header': [1, 2, 3, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ 'color': [] }, { 'background': [] }],
                    [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                    [{ 'align': [] }],
                    ['link', 'clean']
                ]
            },
            theme: 'snow'
        });
        
        // Custom theme for Quill to match dark theme
        const customStyles = document.createElement('style');
        customStyles.textContent = `
            .ql-toolbar.ql-snow {
                background-color: rgba(0, 0, 0, 0.3);
                border: 1px solid rgba(255, 255, 255, 0.1);
                border-bottom: none;
                border-top-left-radius: 5px;
                border-top-right-radius: 5px;
            }
            .ql-container.ql-snow {
                border: 1px solid rgba(255, 255, 255, 0.1);
                border-bottom-left-radius: 5px;
                border-bottom-right-radius: 5px;
                background-color: rgba(0, 0, 0, 0.2);
                color: #e0e0e0;
            }
            .ql-editor {
                color: #e0e0e0;
            }
            .ql-editor.ql-blank::before {
                color: rgba(255, 255, 255, 0.6);
            }
            .ql-snow .ql-stroke {
                stroke: #e0e0e0;
            }
            .ql-snow .ql-fill, .ql-snow .ql-stroke.ql-fill {
                fill: #e0e0e0;
            }
            .ql-snow .ql-picker {
                color: #e0e0e0;
            }
            .ql-snow .ql-picker-options {
                background-color: rgba(0, 0, 0, 0.9);
            }
        `;
        document.head.appendChild(customStyles);
        
        // Update hidden textarea before form submission
        document.getElementById('faq-form').addEventListener('submit', function() {
            document.getElementById('answer').value = quill.root.innerHTML;
        });
        
        // Edit FAQ
        const editButtons = document.querySelectorAll('.edit-faq');
        const faqForm = document.getElementById('faq-form');
        const formTitle = document.getElementById('form-title');
        const cancelButton = document.getElementById('cancel-edit');
        
        editButtons.forEach(button => {
            button.addEventListener('click', function() {
                const faqId = this.getAttribute('data-faq-id');
                const question = this.getAttribute('data-question');
                const answer = this.getAttribute('data-answer');
                const order = this.getAttribute('data-order');
                
                document.getElementById('faq_id').value = faqId;
                document.getElementById('question').value = question;
                document.getElementById('order_number').value = order;
                
                // Set Quill content
                quill.root.innerHTML = answer;
                
                formTitle.textContent = 'Edit FAQ';
                cancelButton.style.display = 'inline-block';
                
                // Scroll to form
                faqForm.scrollIntoView({ behavior: 'smooth' });
            });
        });
        
        // Cancel edit
        cancelButton.addEventListener('click', function() {
            document.getElementById('faq_id').value = '';
            document.getElementById('question').value = '';
            document.getElementById('order_number').value = '0';
            
            // Clear Quill content
            quill.root.innerHTML = '';
            
            formTitle.textContent = 'Add New FAQ';
            cancelButton.style.display = 'none';
        });
        
        // Handle reordering
        const reorderForm = document.getElementById('reorder-form');
        const orderInputsContainer = document.getElementById('order-inputs-container');
        
        reorderForm.addEventListener('submit', function(e) {
            // Clear previous inputs
            orderInputsContainer.innerHTML = '';
            
            // Get all order inputs
            const orderInputs = document.querySelectorAll('.order-input');
            
            // Create hidden inputs for each FAQ order
            orderInputs.forEach(input => {
                const faqId = input.closest('tr').getAttribute('data-faq-id');
                const orderValue = input.value;
                
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = `order[${faqId}]`;
                hiddenInput.value = orderValue;
                
                orderInputsContainer.appendChild(hiddenInput);
            });
        });
    });
</script>

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
        overflow-x: auto;
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
    
    .card {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        margin-bottom: 20px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.4);
        border: none;
    }
    
    .card-header {
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        padding: 15px 20px;
    }
    
    .card-body {
        padding: 20px;
    }
    
    .form-control {
        background-color: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        color: #fff;
        border-radius: 5px;
    }
    
    .form-control:focus {
        background-color: rgba(0, 0, 0, 0.3);
        border-color: #00d4ff;
        color: #fff;
        box-shadow: 0 0 0 0.2rem rgba(0, 212, 255, 0.25);
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #00d4ff, #0050ff);
        border: none;
        box-shadow: 0 4px 15px rgba(0, 212, 255, 0.3);
    }
    
    .btn-primary:hover {
        background: linear-gradient(135deg, #0050ff, #00d4ff);
        transform: translateY(-2px);
    }
    
    .btn-danger {
        background: linear-gradient(135deg, #ff5e62, #ff2c55);
        border: none;
    }
    
    .btn-danger:hover {
        background: linear-gradient(135deg, #ff2c55, #ff5e62);
        transform: translateY(-2px);
    }
    
    .btn-info {
        background: linear-gradient(135deg, #00d4ff, #00a2ff);
        border: none;
    }
    
    .btn-info:hover {
        background: linear-gradient(135deg, #00a2ff, #00d4ff);
        transform: translateY(-2px);
    }
    
    .btn-logout {
        background: #dc3545;
        color: white;
        border: none;
    }
    
    .btn-logout:hover {
        opacity: 0.8;
        transform: translateY(-1px);
    }
    
    .table {
        color: #e0e0e0;
    }
    
    .table thead th {
        border-bottom: 2px solid rgba(255, 255, 255, 0.1);
        background-color: rgba(0, 0, 0, 0.3);
        color: #00d4ff;
    }
    
    .table td, .table th {
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        padding: 12px;
        vertical-align: middle;
    }
    
    .answer-preview {
        max-height: 100px;
        overflow: hidden;
    }
    
    .order-input {
        width: 60px;
        text-align: center;
    }
    
    /* Alert styling */
    .alert {
        border-radius: 5px;
        border: none;
    }
    
    .alert-success {
        background-color: rgba(40, 167, 69, 0.2);
        border: 1px solid #28a745;
        color: #28a745;
    }
    
    .alert-danger {
        background-color: rgba(220, 53, 69, 0.2);
        border: 1px solid #dc3545;
        color: #dc3545;
    }
</style>

<?php include 'includes/footer.php'; ?> 