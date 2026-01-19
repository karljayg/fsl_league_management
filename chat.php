<?php
// Start session at the beginning
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user has permission to delete messages
$canDeleteMessages = false;
if (isset($_SESSION['user_id'])) {
    // Include database connection
    require_once 'includes/db.php';
    
    try {
        $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Check if user has 'chat admin' permission
        $stmt = $db->prepare("
            SELECT COUNT(*) AS cnt
            FROM ws_user_roles ur
            JOIN ws_role_permissions rp ON ur.role_id = rp.role_id
            JOIN ws_permissions p ON rp.permission_id = p.permission_id
            WHERE ur.user_id = ? AND p.permission_name = 'chat admin'
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $canDeleteMessages = ($result['cnt'] > 0);
    } catch (PDOException $e) {
        // Silently fail, user won't have delete permissions
    }
}

// Function to get a simplified location identifier without using cURL
function getSimpleLocationIdentifier($ip) {
    if ($ip == '127.0.0.1' || $ip == '::1') {
        return 'localhost';
    }

    $url = "http://ip-api.com/json/" . $ip;

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 2
        ]
    ]);

    try {
        $response = @file_get_contents($url, false, $ctx);
        if ($response !== false) {
            $data = json_decode($response, true);
            error_log("Geolocation response for IP {$ip}: " . print_r($data, true));
            if ($data && $data['status'] === 'success') {
                $city = strtolower(str_replace(' ', '', $data['city']));
                return $city;
            }
        } else {
            error_log("Geolocation request failed for IP {$ip}");
        }
    } catch (Exception $e) {
        error_log("Geolocation error for IP {$ip}: " . $e->getMessage());
    }

    return substr(md5($ip), 0, 6);
}

// Get user display name - either username if logged in or "anonymous" with a location identifier
if (!function_exists('getUserDisplayName')) {
    function getUserDisplayName() {
        if (isLoggedIn()) {
            return getUsername();
        } else {
            // Get the user's IP address
            $ip = $_SERVER['REMOTE_ADDR'];
            
            // Get a simple location identifier
            $location = getSimpleLocationIdentifier($ip);
            
            // Return anonymous with location
            return "anonymous-" . $location;
        }
    }
}

// Include navigation file which contains the login functions
require_once 'includes/nav.php';

$isLoggedIn = isLoggedIn();
$userDisplayName = getUserDisplayName();
$pageTitle = "Chat Room";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - FSL Pros and Joes' : 'FSL Pros and Joes'; ?></title>
    <link rel="icon" href="images/favicon.png" type="image/png">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <style>
      .chat-wrapper {
        display: flex;
        flex-direction: column;
        background: white;
        padding: 20px;
        border-radius: 15px;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        margin: 20px auto;
        width: 100%;
        max-width: 800px;
        height: calc(100vh - 300px);
        min-height: 500px;
      }
      
      .chat-container {
        display: flex;
        flex-direction: column;
        width: 100%;
        height: 100%;
        border: 2px solid #007bff;
        border-radius: 10px;
        padding: 15px;
        background: #fff;
      }
      
      .messages {
        flex: 1;
        overflow-y: auto;
        padding: 10px;
        background: white;
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-bottom: 15px;
        border-bottom: 2px solid #007bff;
      }
      
      .message {
        max-width: 80%;
        padding: 10px 15px;
        border-radius: 18px;
        word-wrap: break-word;
        font-size: 14px;
        line-height: 1.4;
        box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        position: relative;
      }
      
      .message-content {
        margin-bottom: 4px;
      }
      
      .message-timestamp {
        font-size: 10px;
        opacity: 0.7;
        margin-top: 2px;
        text-align: right;
      }
      
      .sent .message-timestamp {
        color: rgba(255, 255, 255, 0.8);
      }
      
      .received .message-timestamp {
        color: rgba(0, 0, 0, 0.6);
      }
      
      .message-delete {
        position: absolute;
        top: 5px;
        right: 5px;
        width: 16px;
        height: 16px;
        background-color: rgba(255, 0, 0, 0.6);
        color: white;
        border-radius: 50%;
        font-size: 10px;
        line-height: 16px;
        text-align: center;
        cursor: pointer;
        opacity: 0;
        transition: opacity 0.2s;
      }
      
      .message:hover .message-delete {
        opacity: 1;
      }
      
      .loading-indicator {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background-color: rgba(0, 0, 0, 0.7);
        color: white;
        padding: 10px 20px;
        border-radius: 5px;
        z-index: 1000;
      }
      
      .sent {
        align-self: flex-end;
        background: #007bff;
        color: white;
        border-bottom-right-radius: 4px;
      }
      
      .received {
        align-self: flex-start;
        background: #e9ecef;
        color: #212529;
        border-bottom-left-radius: 4px;
      }
      
      .input-area {
        display: flex;
        gap: 10px;
        width: 100%;
      }
      
      input {
        flex: 1;
        padding: 12px 15px;
        border: 1px solid #ced4da;
        border-radius: 25px;
        font-size: 14px;
        outline: none;
        transition: border-color 0.2s;
      }
      
      input:focus {
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0,123,255,0.25);
      }
      
      button {
        padding: 12px 20px;
        background: #007bff;
        color: white;
        border: none;
        border-radius: 25px;
        cursor: pointer;
        transition: background-color 0.3s;
        font-weight: 600;
      }
      
      button:hover {
        background: #0056b3;
      }
      
      .user-info {
        text-align: center;
        margin-bottom: 15px;
        font-weight: bold;
        color: #007bff;
      }
      
      @media (max-width: 768px) {
        .chat-wrapper {
          margin: 10px;
          padding: 15px;
          height: calc(100vh - 200px);
        }
        
        .message {
          max-width: 90%;
        }
      }
    </style>
</head>
<body>
    <?php include_once 'includes/nav.php'; ?>
    
    <main class="container py-4">
        <h1 class="text-center mb-4">Chat Room</h1>
        
        <div class="user-info">
            You are chatting as: <span id="currentUser"><?php echo htmlspecialchars($userDisplayName); ?></span>
        </div>
        
        <div class="chat-wrapper">
            <div class="chat-container">
                <div class="messages" id="chat1"></div>
                <div class="input-area">
                    <input
                        type="text"
                        id="message1"
                        placeholder="Type a message"
                        onkeypress="handleKeyPress(event, '<?php echo htmlspecialchars($userDisplayName); ?>', 'message1')"
                    />
                    <button onclick="sendMessage('<?php echo htmlspecialchars($userDisplayName); ?>', 'message1')">Send</button>
                </div>
            </div>
        </div>
    </main>

    <?php include_once 'includes/footer.php'; ?>

    <script>
      // Avoid using localStorage or sessionStorage which might conflict with browser extensions
      let chatRefreshInterval = null;
      
      function formatTimestamp(timestamp) {
        if (!timestamp) return '';
        
        const date = new Date(timestamp * 1000);
        const now = new Date();
        const isToday = date.toDateString() === now.toDateString();
        
        // Format: HH:MM for today, MM/DD HH:MM for other days
        const hours = date.getHours().toString().padStart(2, '0');
        const minutes = date.getMinutes().toString().padStart(2, '0');
        const month = (date.getMonth() + 1).toString().padStart(2, '0');
        const day = date.getDate().toString().padStart(2, '0');
        
        return isToday 
          ? `${hours}:${minutes}` 
          : `${month}/${day} ${hours}:${minutes}`;
      }

      function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
      }

      function sendMessage(user, inputId) {
        let message = document.getElementById(inputId).value.trim();
        if (message === "") return;

        // Get current timestamp
        const timestamp = Math.floor(Date.now() / 1000);
        
        // Show a temporary message immediately for better UX
        let tempMsgDiv = document.createElement("div");
        tempMsgDiv.classList.add("message", "sent", "temp-message");
        tempMsgDiv.innerHTML = `
          <div class="message-content">${user}: ${message}</div>
          <div class="message-timestamp">Just now</div>
        `;
        document.getElementById("chat1").appendChild(tempMsgDiv);
        
        // Scroll to the bottom
        document.getElementById("chat1").scrollTop = document.getElementById("chat1").scrollHeight;
        
        // Clear the input field immediately
        document.getElementById(inputId).value = "";
        document.getElementById(inputId).focus();

        // Send the message to the server
        fetch("chat_save.php", {
          method: "POST",
          headers: { 
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest"
          },
          body: JSON.stringify({ 
            user: user, 
            message: message,
            timestamp: timestamp
          }),
          credentials: 'same-origin'
        })
          .then(response => {
            if (!response.ok) {
              throw new Error(`HTTP error! Status: ${response.status}`);
            }
            return response.json();
          })
          .then(data => {
            if (data.status === "success") {
              // Remove the temporary message and load all messages
              const tempMessages = document.querySelectorAll(".temp-message");
              tempMessages.forEach(msg => msg.remove());
              loadMessages();
            } else {
              console.error("Error:", data.message);
              alert("Failed to send message. Please try again.");
            }
          })
          .catch(error => {
            console.error("Fetch Error:", error);
            alert("Failed to send message. Please try again.");
          });
      }

      function loadMessages() {
        // Add a timestamp to prevent caching
        const timestamp = new Date().getTime();
        
        fetch(`chat_data.json?t=${timestamp}`, {
          method: 'GET',
          headers: {
            'Cache-Control': 'no-cache, no-store, must-revalidate',
            'Pragma': 'no-cache',
            'Expires': '0',
            "X-Requested-With": "XMLHttpRequest"
          },
          credentials: 'same-origin'
        })
          .then(response => {
            if (!response.ok) {
              throw new Error(`HTTP error! Status: ${response.status}`);
            }
            return response.text(); // Get as text first to check for valid JSON
          })
          .then(text => {
            // Check if the response is empty
            if (!text || text.trim() === '') {
              return []; // Return empty array if no data
            }
            
            try {
              const data = JSON.parse(text);
              if (!Array.isArray(data)) {
                console.error("Invalid data format, expected array:", data);
                return [];
              }
              return data;
            } catch (e) {
              console.error("JSON parse error:", e);
              return [];
            }
          })
          .then(data => {
            let chat1 = document.getElementById("chat1");
            
            // Don't clear if we have temporary messages
            if (!document.querySelector(".temp-message")) {
              chat1.innerHTML = "";
            }

            data.forEach(msg => {
              // Skip rendering if we already have a temporary message with the same content
              const tempMessages = document.querySelectorAll(".temp-message");
              let isDuplicate = false;
              
              tempMessages.forEach(tempMsg => {
                if (tempMsg.textContent === `${msg.user}: ${msg.message}`) {
                  isDuplicate = true;
                }
              });
              
              if (isDuplicate) return;
              
              let msgDiv = document.createElement("div");
              msgDiv.classList.add("message");
              
              // Get current user from the page
              let currentUser = document.getElementById("currentUser").textContent;
              
              // Set message class based on whether it's from the current user
              if (msg.user === currentUser) {
                msgDiv.classList.add("sent");
              } else {
                msgDiv.classList.add("received");
              }
              
              // Create message content - use DOM methods to prevent XSS
              const messageContent = document.createElement('div');
              messageContent.className = 'message-content';
              messageContent.textContent = `${msg.user}: ${msg.message}`;
              
              const messageTimestamp = document.createElement('div');
              messageTimestamp.className = 'message-timestamp';
              messageTimestamp.textContent = formatTimestamp(msg.timestamp);
              
              msgDiv.appendChild(messageContent);
              msgDiv.appendChild(messageTimestamp);
              
              // Add delete button if user has permission
              <?php if ($canDeleteMessages): ?>
              const deleteBtn = document.createElement('div');
              deleteBtn.className = 'message-delete';
              deleteBtn.title = 'Delete message';
              deleteBtn.textContent = '×';
              const messageId = (msg.id || msg.timestamp).toString();
              deleteBtn.onclick = () => deleteMessage(messageId);
              msgDiv.appendChild(deleteBtn);
              <?php endif; ?>
              chat1.appendChild(msgDiv);
            });
            
            // Scroll to the bottom of the chat
            chat1.scrollTop = chat1.scrollHeight;
          })
          .catch(error => {
            console.error("Error loading messages:", error);
          });
      }

      function handleKeyPress(event, user, inputId) {
        if (event.key === "Enter") {
          sendMessage(user, inputId);
        }
      }

      function deleteMessage(messageId) {
        if (!confirm('Are you sure you want to delete this message?')) {
          return;
        }
        
        // Show loading indicator
        const loadingIndicator = document.createElement('div');
        loadingIndicator.className = 'loading-indicator';
        loadingIndicator.textContent = 'Deleting message...';
        document.body.appendChild(loadingIndicator);
        
        fetch('chat_delete.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify({ messageId: messageId }),
          credentials: 'same-origin'
        })
        .then(response => {
          if (!response.ok) {
            throw new Error(`HTTP error! Status: ${response.status}`);
          }
          return response.json();
        })
        .then(data => {
          // Remove loading indicator
          loadingIndicator.remove();
          
          if (data.status === 'success') {
            loadMessages();
          } else {
            alert('Failed to delete message: ' + data.message);
          }
        })
        .catch(error => {
          // Remove loading indicator
          loadingIndicator.remove();
          
          console.error('Error deleting message:', error);
          alert('Failed to delete message. Please try again.');
        });
      }

      // Initialize the chat when the page loads
      document.addEventListener('DOMContentLoaded', function() {
        // Focus the input field
        document.getElementById("message1").focus();
        
        // Initial load of messages
        loadMessages();
        
        // Clear any existing interval
        if (chatRefreshInterval) {
          clearInterval(chatRefreshInterval);
        }
        
        // Set up interval for refreshing messages (every 3 seconds)
        chatRefreshInterval = setInterval(loadMessages, 3000);
      });
    </script>
</body>
</html> 