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
// Function to generate URL-friendly anchor from question
function generateAnchor($question) {
    // Convert to lowercase and remove special characters
    $text = preg_replace('/[^\w\s-]/', '', strtolower($question));
    // Replace spaces with hyphens
    $text = preg_replace('/\s+/', '-', $text);
    // Get first three words
    $words = array_slice(explode('-', $text), 0, 3);
    return implode('-', $words);
}

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
                    <?php $anchor = generateAnchor($faq['Question']); ?>
                    <div class="faq-item" id="<?php echo $anchor; ?>">
                        <div class="faq-question" id="heading<?php echo $faq['FAQ_ID']; ?>">
                            <button class="btn-toggle <?php echo ($index === 0) ? 'active' : ''; ?>" type="button" 
                                    data-target="#collapse<?php echo $faq['FAQ_ID']; ?>" 
                                    aria-expanded="<?php echo ($index === 0) ? 'true' : 'false'; ?>" 
                                    aria-controls="collapse<?php echo $faq['FAQ_ID']; ?>">
                                <a href="#<?php echo $anchor; ?>" class="faq-anchor">
                                    <?php echo htmlspecialchars($faq['Question']); ?>
                                </a>
                            </button>
                        </div>

                        <div id="collapse<?php echo $faq['FAQ_ID']; ?>" 
                             class="faq-answer collapse <?php echo ($index === 0) ? 'show' : ''; ?>" 
                             aria-labelledby="heading<?php echo $faq['FAQ_ID']; ?>">
                            <div class="faq-answer-content">
                                <?php echo $faq['Answer']; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Function to open FAQ item by ID
        function openFaqItem(itemId) {
            const faqItem = document.getElementById(itemId);
            if (faqItem) {
                // Find the button and collapse element
                const button = faqItem.querySelector('.btn-toggle');
                const collapseId = button.getAttribute('data-target').substring(1);
                const collapseElement = document.getElementById(collapseId);

                // Close all other FAQ items
                document.querySelectorAll('.btn-toggle.active').forEach(btn => {
                    if (btn !== button) {
                        btn.classList.remove('active');
                        btn.setAttribute('aria-expanded', 'false');
                        const target = document.querySelector(btn.getAttribute('data-target'));
                        if (target) target.classList.remove('show');
                    }
                });

                // Toggle the target FAQ item
                const isExpanded = button.getAttribute('aria-expanded') === 'true';
                if (!isExpanded) {
                    button.classList.add('active');
                    button.setAttribute('aria-expanded', 'true');
                    if (collapseElement) {
                        collapseElement.classList.add('show');
                    }
                }

                // Scroll into view with a small delay to ensure smooth transition
                setTimeout(() => {
                    faqItem.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 100);
            }
        }

        // Function to toggle FAQ item
        function toggleFaqItem(button) {
            // Close all other FAQ items first
            document.querySelectorAll('.btn-toggle.active').forEach(btn => {
                if (btn !== button) {
                    btn.classList.remove('active');
                    btn.setAttribute('aria-expanded', 'false');
                    const target = document.querySelector(btn.getAttribute('data-target'));
                    if (target) target.classList.remove('show');
                }
            });
            
            // Get current state
            const expanded = button.getAttribute('aria-expanded') === 'true';
            
            // Toggle aria-expanded attribute
            button.setAttribute('aria-expanded', !expanded);
            
            // Toggle active class for styling
            button.classList.toggle('active');
            
            // Find and toggle the target element
            const targetId = button.getAttribute('data-target');
            const targetElement = document.querySelector(targetId);
            
            if (targetElement) {
                targetElement.classList.toggle('show');
            }
        }

        // Handle initial hash in URL
        if (window.location.hash) {
            const itemId = window.location.hash.substring(1);
            openFaqItem(itemId);
        }

        // Handle clicks on FAQ items
        document.querySelectorAll('.btn-toggle').forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggleFaqItem(this);
            });

            // Handle anchor link clicks separately
            const anchor = button.querySelector('.faq-anchor');
            if (anchor) {
                anchor.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const itemId = this.getAttribute('href').substring(1);
                    openFaqItem(itemId);
                    // Update URL without triggering scroll
                    history.pushState(null, null, `#${itemId}`);
                });
            }
        });

        // Handle browser back/forward navigation
        window.addEventListener('hashchange', function() {
            const itemId = window.location.hash.substring(1);
            if (itemId) {
                openFaqItem(itemId);
            }
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
    
    .faq-item {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        margin-bottom: 15px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.4);
        overflow: hidden;
    }
    
    .faq-question {
        position: relative;
    }
    
    .btn-toggle {
        display: block;
        width: 100%;
        text-align: left;
        background: rgba(0, 0, 0, 0.2);
        color: #00d4ff;
        font-weight: 600;
        padding: 15px 50px 15px 20px;
        border: none;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        cursor: pointer;
        position: relative;
        transition: all 0.3s ease;
    }
    
    .btn-toggle:hover {
        background: rgba(0, 212, 255, 0.1);
        color: #ffffff;
    }
    
    .btn-toggle:focus {
        outline: none;
    }
    
    .faq-anchor {
        color: inherit;
        text-decoration: none;
        display: block;
        width: 100%;
    }
    
    .faq-anchor:hover {
        color: inherit;
        text-decoration: none;
    }
    
    .btn-toggle::after {
        content: '+';
        position: absolute;
        right: 20px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 1.5em;
        color: #00d4ff;
        transition: all 0.3s ease;
    }
    
    .btn-toggle.active::after {
        content: '-';
    }
    
    .faq-answer {
        background: rgba(0, 0, 0, 0.1);
    }
    
    .faq-answer-content {
        padding: 20px;
        color: #e0e0e0;
    }
    
    /* Style for links in FAQ answers */
    .faq-answer-content a {
        color: #00d4ff;
        text-decoration: none;
        border-bottom: 1px dotted #00d4ff;
    }
    
    .faq-answer-content a:hover {
        color: #ffffff;
        border-bottom: 1px solid #ffffff;
    }
    
    /* Bootstrap collapse functionality */
    .collapse:not(.show) {
        display: none;
    }
    
    .collapse.show {
        display: block;
    }
    
    /* Alert styling */
    .alert {
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
    }
    
    .alert-info {
        background-color: rgba(23, 162, 184, 0.2);
        border: 1px solid #17a2b8;
        color: #17a2b8;
    }
</style>

<?php include 'includes/footer.php'; ?> 