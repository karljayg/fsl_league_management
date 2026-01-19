<?php
/**
 * Player Intro Video Display
 * Shows a transparent MP4 video for player intros using canvas-based green screen removal
 */

// Get player name from parent file or default to TEST
$playerName = isset($introPlayerName) ? $introPlayerName : 'TEST';

// Generate unique IDs for this instance
$uniqueId = isset($uniqueId) ? $uniqueId : uniqid();
$videoId = "originalVideo_" . $uniqueId;
$canvasId = "canvas_" . $uniqueId;

// Default test video URL
$defaultVideoUrl = 'https://psistorm.com/stream_production/production_files/video/FSL-logo_FINAL_compressed.mp4';

// If not test mode, get video URL from database
$videoUrl = $defaultVideoUrl;
if ($playerName !== 'TEST') {
    try {
        // Use the existing database connection if available
        if (!isset($db)) {
            require_once 'includes/db.php';
            $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        
        // Query to get the Intro_Url field from the Players table
        $query = "SELECT Intro_Url FROM Players WHERE Real_Name = :playerName";
        $stmt = $db->prepare($query);
        $stmt->execute(['playerName' => $playerName]);
        
        if ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($result['Intro_Url'])) {
                $videoUrl = $result['Intro_Url'];
            }
        }
    } catch (PDOException $e) {
        // On error, fall back to default video
        $videoUrl = $defaultVideoUrl;
    }
}
?>

<style>
.player-intro-container {
    width: 100%;
    height: 100%;
    position: relative;
    overflow: hidden;
    display: flex;
    justify-content: center;
    align-items: center;
}

#<?= $videoId ?> {
    display: none; /* Hidden original video */
}

#<?= $canvasId ?> {
    width: 100%;
    height: 100%;
    object-fit: contain;
}
</style>

<div class="player-intro-container">
    <video id="<?= $videoId ?>" autoplay loop muted playsinline webkit-playsinline>
        <source src="<?= htmlspecialchars($videoUrl) ?>" type="video/mp4">
    </video>
    <canvas id="<?= $canvasId ?>"></canvas>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Elements with unique IDs
    const video = document.getElementById('<?= $videoId ?>');
    const canvas = document.getElementById('<?= $canvasId ?>');
    
    if (!video || !canvas) {
        console.warn('Player intro elements not found for <?= $playerName ?>');
        return;
    }
    
    const ctx = canvas.getContext('2d');
    
    // Variables
    let threshold = 100; // Green screen threshold
    let smoothing = 10;  // Edge smoothing
    let videoLoaded = false;
    
    // Set canvas size
    function setCanvasSize() {
        canvas.width = video.videoWidth || 640;
        canvas.height = video.videoHeight || 360;
    }
    
    // Process video frames
    function processFrame() {
        if (video.paused || video.ended || !videoLoaded) {
            requestAnimationFrame(processFrame);
            return;
        }
        
        // Draw the current frame to the canvas
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        
        // Get the image data to manipulate pixels
        const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const data = imageData.data;
        
        // Process each pixel
        for (let i = 0; i < data.length; i += 4) {
            const r = data[i];
            const g = data[i + 1];
            const b = data[i + 2];
            
            // Check if the pixel is green (focusing on high green values)
            if (g > r + threshold && g > b + threshold) {
                data[i + 3] = 0; // Make it transparent
            } else {
                // Calculate how "green dominant" the pixel is
                const greenDominance = g - Math.max(r, b);
                
                if (greenDominance > 0) {
                    // Partial transparency for edges based on green dominance
                    const alpha = Math.max(0, 255 - (greenDominance * (255 / smoothing)));
                    data[i + 3] = alpha;
                }
            }
        }
        
        // Put the modified image data back on the canvas
        ctx.putImageData(imageData, 0, 0);
        
        // Continue processing
        requestAnimationFrame(processFrame);
    }
    
    // Event listeners
    video.addEventListener('loadedmetadata', function() {
        setCanvasSize();
        videoLoaded = true;
        video.play();
    });
    
    // Start processing
    processFrame();
    
    // Force video to start loading
    video.load();
    
    // Fallback - if video doesn't start loading
    setTimeout(() => {
        if (!videoLoaded) {
            video.src = "<?= htmlspecialchars($videoUrl) ?>";
            video.load();
        }
    }, 3000);
});
</script> 