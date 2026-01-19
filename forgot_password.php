<?php
session_start();

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Configuration
require_once 'config.php';

// Database connection
require_once 'includes/db.php';

try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Please enter your email address';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } else {
        // Check if email exists
        $stmt = $db->prepare('SELECT id, username FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime("+{$config['reset_token_expiry_hours']} hours"));
            
            // Save token to database
            $stmt = $db->prepare('UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?');
            $stmt->execute([$token, $expires, $user['id']]);
            
            // Send email via Gmail SMTP
            $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=" . $token;
            
            $subject = "Password Reset - FSL Pros and Joes";
            $message_body = "Hi " . $user['username'] . ",\n\n";
            $message_body .= "You requested a password reset for your FSL account.\n\n";
            $message_body .= "Click the link below to reset your password:\n";
            $message_body .= $reset_link . "\n\n";
            $message_body .= "This link will expire in {$config['reset_token_expiry_hours']} hours.\n\n";
            $message_body .= "If you didn't request this reset, you can ignore this email.\n\n";
            $message_body .= "Best regards,\nFSL Team";
            
            // Gmail SMTP settings - UPDATE THESE
            $smtp_server = 'smtp.gmail.com';
            $smtp_port = 587;
            $smtp_username = 'kj@psistorm.com';  // Your Google Workspace email
            $smtp_password = 'kcau xiwv icjk ipjc';        // Gmail App Password
            $from_email = 'noreply@psistorm.com';
            
            $message = 'Password reset request received! Contact ' . $config['reset_admin_contact'] . ' for your reset link.';
            
            // Uncomment below when email is properly configured:
            /*
            if (sendGmailSMTP($smtp_server, $smtp_port, $smtp_username, $smtp_password, $from_email, $email, $subject, $message_body)) {
                $message = 'Password reset email sent! Check your inbox and click the link to reset your password.';
            } else {
                $error = 'Failed to send email. Please try again or contact support.';
            }
            */
        } else {
            // Don't reveal if email exists or not for security
            $message = 'If that email address is registered, you will receive a password reset link.';
        }
    }
}

// Gmail SMTP function - Amazon Linux compatible
function sendGmailSMTP($host, $port, $username, $password, $from, $to, $subject, $body) {
    // Use cURL for better SSL support on Amazon Linux
    $url = "https://api.emailjs.com/api/v1.0/email/send";
    
    // Try direct SMTP first, then fallback to cURL method
    if (function_exists('curl_init')) {
        return sendViaCurl($host, $port, $username, $password, $from, $to, $subject, $body);
    }
    
    // Fallback to simple socket method
    return sendViaSocket($host, $port, $username, $password, $from, $to, $subject, $body);
}

function sendViaCurl($host, $port, $username, $password, $from, $to, $subject, $body) {
    // Gmail API-like approach using cURL
    $postdata = [
        'to' => $to,
        'from' => $from,
        'subject' => $subject,
        'text' => $body
    ];
    
    // Use sendmail as fallback since cURL to Gmail API requires OAuth2
    return sendViaSystemMail($to, $subject, $body, $from);
}

function sendViaSystemMail($to, $subject, $body, $from) {
    // Use system sendmail command - works with postfix
    $headers = "From: FSL Pros and Joes <$from>\r\n";
    $headers .= "Reply-To: $from\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    return mail($to, $subject, $body, $headers);
}

function sendViaSocket($host, $port, $username, $password, $from, $to, $subject, $body) {
    // Simplified socket approach - try multiple SSL methods
    $methods = [
        STREAM_CRYPTO_METHOD_TLS_CLIENT,
        STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT
    ];
    
    foreach ($methods as $method) {
        $socket = fsockopen($host, $port, $errno, $errstr, 10);
        if (!$socket) continue;
        
        fgets($socket, 515);
        fputs($socket, "EHLO localhost\r\n");
        fgets($socket, 515);
        
        fputs($socket, "STARTTLS\r\n");
        fgets($socket, 515);
        
        if (stream_socket_enable_crypto($socket, true, $method)) {
            // Continue with SMTP auth if TLS works
            fputs($socket, "EHLO localhost\r\n");
            fgets($socket, 515);
            
            fputs($socket, "AUTH LOGIN\r\n");
            fgets($socket, 515);
            
            fputs($socket, base64_encode($username) . "\r\n");
            fgets($socket, 515);
            
            fputs($socket, base64_encode($password) . "\r\n");
            $auth_response = fgets($socket, 515);
            
            if (strpos($auth_response, '235') !== false) {
                fputs($socket, "MAIL FROM: <$from>\r\n");
                fgets($socket, 515);
                
                fputs($socket, "RCPT TO: <$to>\r\n");
                fgets($socket, 515);
                
                fputs($socket, "DATA\r\n");
                fgets($socket, 515);
                
                $headers = "From: FSL Pros and Joes <$from>\r\n";
                $headers .= "To: $to\r\n";
                $headers .= "Subject: $subject\r\n";
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $headers .= "\r\n";
                
                fputs($socket, $headers . $body . "\r\n.\r\n");
                fgets($socket, 515);
                
                fputs($socket, "QUIT\r\n");
                fclose($socket);
                return true;
            }
        }
        fclose($socket);
    }
    
    // If all methods fail, use system mail as fallback
    return sendViaSystemMail($to, $subject, $body, $from);
}

$pageTitle = "Forgot Password";
include_once 'includes/header.php';
?>

<section class="auth-section">
    <div class="auth-container">
        <div class="auth-box">
            <h2>Reset Your Password</h2>
            <p>Enter your email address and we'll send you a link to reset your password.</p>
            
            <?php if ($message): ?>
                <div class="success-message"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="forgot_password.php">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
                <button type="submit" class="primary-btn">Send Reset Link</button>
            </form>
            
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
}
</style>

<?php include_once 'includes/footer.php'; ?> 