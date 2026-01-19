<?php
/**
 * Public FAQ Page
 * Displays frequently asked questions to users
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Include database connection
require_once 'includes/db.php';

// Connect to database
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get all FAQs
try {
    $stmt = $db->query("SELECT * FROM FAQ ORDER BY Order_Number ASC");
    $faqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $faqs = [];
}

// Set page title
$pageTitle = "Frequently Asked Questions";

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <h1>Frequently Asked Questions</h1>
    
    <div class="faq-container">
        <?php if (empty($faqs)): ?>
            <div class="alert alert-info">
                No FAQs available at this time. Please check back later.
            </div>
        <?php else: ?>
            <div class="accordion" id="faqAccordion">
                <?php foreach ($faqs as $index => $faq): ?>
                    <div class="card">
                        <div class="card-header" id="heading<?php echo $faq['FAQ_ID']; ?>">
                            <h2 class="mb-0">
                                <button class="btn btn-link btn-block text-left" type="button" 
                                        data-toggle="collapse" 
                                        data-target="#collapse<?php echo $faq['FAQ_ID']; ?>" 
                                        aria-expanded="<?php echo ($index === 0) ? 'true' : 'false'; ?>" 
                                        aria-controls="collapse<?php echo $faq['FAQ_ID']; ?>">
                                    <?php echo htmlspecialchars($faq['Question']); ?>
                                </button>
                            </h2>
                        </div>

                        <div id="collapse<?php echo $faq['FAQ_ID']; ?>" 
                             class="collapse <?php echo ($index === 0) ? 'show' : ''; ?>" 
                             aria-labelledby="heading<?php echo $faq['FAQ_ID']; ?>" 
                             data-parent="#faqAccordion">
                            <div class="card-body">
                                <?php echo $faq['Answer']; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
        max-width: 100%;
        margin: 20px auto;
        padding: 20px;
        overflow-x: auto;
    }
    
    h1 {
        text-align: center;
        color: #00d4ff;
        text-shadow: 0 0 15px #00d4ff;
        font-size: 2.4em;
        margin-bottom: 30px;
    }
    
    .faq-container {
        max-width: 800px;
        margin: 0 auto;
    }
    
    .card {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        margin-bottom: 15px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.4);
        border: none;
        overflow: hidden;
    }
    
    .card-header {
        background: rgba(0, 0, 0, 0.2);
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        padding: 0;
    }
    
    .card-header h2 {
        margin: 0;
    }
    
    .card-header button {
        color: #00d4ff;
        font-weight: 600;
        text-decoration: none;
        padding: 15px;
        width: 100%;
        text-align: left;
        position: relative;
        transition: all 0.3s ease;
    }
    
    .card-header button:hover {
        color: #ffffff;
        text-decoration: none;
        background: rgba(0, 212, 255, 0.1);
    }
    
    .card-header button:focus {
        box-shadow: none;
    }
    
    .card-header button::after {
        content: '+';
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 1.5em;
        color: #00d4ff;
    }
    
    .card-header button[aria-expanded="true"]::after {
        content: '-';
    }
    
    .card-body {
        padding: 20px;
        color: #e0e0e0;
        background: rgba(0, 0, 0, 0.1);
    }
    
    /* Style for links in FAQ answers */
    .card-body a {
        color: #00d4ff;
        text-decoration: none;
        border-bottom: 1px dotted #00d4ff;
    }
    
    .card-body a:hover {
        color: #ffffff;
        border-bottom: 1px solid #ffffff;
    }
</style>

<?php include 'includes/footer.php'; ?> 