<?php
// Start session at the beginning
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include navigation file which contains the login functions
require_once 'includes/nav.php';

$isLoggedIn = isLoggedIn();
$pageTitle = "Discord Community";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - FSL - Fun StarCraft League' : 'FSL - Fun StarCraft League'; ?></title>
    <link rel="icon" href="images/favicon.png" type="image/png">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            overflow: hidden;
        }
        
        main.container {
            margin: 0;
            padding: 0;
            max-width: 100%;
            width: 100%;
            height: calc(100vh - 60px); /* Full height minus nav bar */
            display: flex;
            flex-direction: column;
        }
        
        .discord-header {
            text-align: center;
            padding: 10px 20px;
            color: #7289da;
            flex-shrink: 0;
        }
        
        .discord-header h1 {
            color: #7289da;
            font-weight: 600;
            margin: 10px 0 5px 0;
            font-size: 1.5em;
        }
        
        .discord-header p {
            color: #ccc;
            font-size: 14px;
            margin: 0 0 10px 0;
        }
        
        .discord-container {
            width: 100%;
            height: 100%;
            flex: 1;
            overflow: hidden;
            margin: 0;
            padding: 0;
        }
        
        .discord-embed {
            width: 100%;
            height: 100%;
            overflow: hidden;
        }
        
        widgetbot {
            display: block;
            width: 100% !important;
            height: 100% !important;
        }
        
        @media (max-width: 768px) {
            main.container {
                height: calc(100vh - 60px);
            }
            
            .discord-header {
                padding: 8px 15px;
            }
            
            .discord-header h1 {
                font-size: 1.2em;
            }
            
            .discord-header p {
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <?php include_once 'includes/nav.php'; ?>
    
    <main class="container">
        <div class="discord-header">
            <h1><i class="fab fa-discord"></i> FSL Discord Community</h1>
            <p>Join our Discord server to chat with other players, discuss matches, and stay updated!</p>
        </div>
        
        <div class="discord-container">
            <div class="discord-embed">
                <widgetbot
                    server="176947904634814466"
                    channel="730131128010145802"
                    width="100%"
                    height="100%">
                </widgetbot>
                <script src="https://cdn.jsdelivr.net/npm/@widgetbot/html-embed"></script>
            </div>
        </div>
    </main>

    <?php include_once 'includes/footer.php'; ?>

    <script>
        // Ensure Discord widget fills available space
        window.addEventListener('resize', function() {
            const container = document.querySelector('.discord-container');
            const main = document.querySelector('main.container');
            if (container && main) {
                const navHeight = document.querySelector('.nav-menu')?.offsetHeight || 60;
                main.style.height = `calc(100vh - ${navHeight}px)`;
            }
        });
        
        // Set initial height on load
        document.addEventListener('DOMContentLoaded', function() {
            const navHeight = document.querySelector('.nav-menu')?.offsetHeight || 60;
            const main = document.querySelector('main.container');
            if (main) {
                main.style.height = `calc(100vh - ${navHeight}px)`;
            }
        });
    </script>
</body>
</html>
