<?php
/**
 * Player Thumbnail Generator
 * Generates thumbnail images from player intro videos using ffmpeg
 * Thumbnails are cached - delete the file to regenerate
 */

define('THUMBNAIL_DIR', __DIR__ . '/../images/player_thumbnails');
define('THUMBNAIL_SIZE', 128); // Square thumbnail size in pixels
define('FFMPEG_PATH', '/opt/homebrew/bin/ffmpeg');

/**
 * Sanitize player name for use as a filename
 * 
 * @param string $name Player name
 * @return string Safe filename
 */
function sanitizeFilename($name) {
    // Replace spaces with underscores, remove special chars except dash/underscore
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', str_replace(' ', '_', $name));
    return $safe ?: 'unknown';
}

/**
 * Get the thumbnail path for a player
 * If thumbnail doesn't exist and intro_url is available, generates it
 * 
 * @param int $playerId Player ID
 * @param string|null $introUrl Optional intro URL (if already known)
 * @param PDO|null $db Database connection (if intro URL not provided)
 * @param string|null $playerName Optional player name for filename
 * @return string|null Path to thumbnail relative to web root, or null if unavailable
 */
function getPlayerThumbnail($playerId, $introUrl = null, $db = null, $playerName = null) {
    // If we have player name, use it; otherwise fall back to ID
    $filename = $playerName ? sanitizeFilename($playerName) : $playerId;
    
    // Check for PNG first (new format with transparency)
    $pngPath = THUMBNAIL_DIR . '/' . $filename . '.png';
    $pngWebPath = 'images/player_thumbnails/' . $filename . '.png';
    if (file_exists($pngPath)) {
        return $pngWebPath;
    }
    
    // Also check for old JPG format
    $jpgPath = THUMBNAIL_DIR . '/' . $filename . '.jpg';
    $jpgWebPath = 'images/player_thumbnails/' . $filename . '.jpg';
    if (file_exists($jpgPath)) {
        return $jpgWebPath;
    }
    
    // If no intro URL provided, try to get it from database
    if ($introUrl === null && $db !== null) {
        try {
            $stmt = $db->prepare("SELECT Intro_Url, Real_Name FROM Players WHERE Player_ID = ?");
            $stmt->execute([$playerId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $introUrl = $result['Intro_Url'] ?? null;
            if (!$playerName && !empty($result['Real_Name'])) {
                $playerName = $result['Real_Name'];
                $filename = sanitizeFilename($playerName);
            }
        } catch (PDOException $e) {
            error_log("Failed to get intro URL for player $playerId: " . $e->getMessage());
            return null;
        }
    }
    
    // If no intro URL available, return null
    if (empty($introUrl)) {
        return null;
    }
    
    // Generate thumbnail using ffmpeg (will output as PNG)
    $outputPath = THUMBNAIL_DIR . '/' . $filename . '.jpg'; // Will be converted to .png in generateThumbnail
    if (generateThumbnail($introUrl, $outputPath)) {
        return 'images/player_thumbnails/' . $filename . '.png';
    }
    
    return null;
}

/**
 * Get video duration using ffprobe
 * 
 * @param string $videoUrl URL of the video
 * @return float|null Duration in seconds, or null on failure
 */
function getVideoDuration($videoUrl) {
    $ffprobe = str_replace('ffmpeg', 'ffprobe', FFMPEG_PATH);
    $cmd = sprintf(
        '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>&1',
        escapeshellcmd($ffprobe),
        escapeshellarg($videoUrl)
    );
    
    $output = [];
    $returnCode = 0;
    exec($cmd, $output, $returnCode);
    
    if ($returnCode === 0 && !empty($output[0]) && is_numeric($output[0])) {
        return (float) $output[0];
    }
    return null;
}

/**
 * Generate a thumbnail from a video URL using ffmpeg
 * 
 * @param string $videoUrl URL of the video
 * @param string $outputPath Full path where thumbnail should be saved
 * @return bool True on success, false on failure
 */
function generateThumbnail($videoUrl, $outputPath) {
    // Ensure output directory exists
    $dir = dirname($outputPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    // Get video duration and seek to halfway point
    $duration = getVideoDuration($videoUrl);
    $seekTime = 1; // Default fallback
    if ($duration !== null && $duration > 0) {
        $seekTime = $duration / 2;
        // Cap at 3 seconds for longer videos (player should be visible by then)
        if ($seekTime > 3) {
            $seekTime = 3;
        }
    }
    
    // Build ffmpeg command with chromakey filter to remove green screen
    // -ss: seek to halfway point (dynamic) or 3s max
    // -i: input URL
    // -frames:v 1: extract 1 frame
    // -vf: scale, crop, then apply chromakey to remove green
    // Output as PNG for transparency support
    $size = THUMBNAIL_SIZE;
    
    // Change output to PNG for transparency
    $outputPath = preg_replace('/\.jpg$/', '.png', $outputPath);
    
    // Use colorkey (better for green screens) with higher similarity for FSL videos
    // 0x00FF00 = pure green, 0.4 similarity (40%), 0.2 blend for soft edges
    $cmd = sprintf(
        '%s -ss %.2f -i %s -frames:v 1 -vf "scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,colorkey=0x00FF00:0.4:0.2" -update 1 -y %s 2>&1',
        escapeshellcmd(FFMPEG_PATH),
        $seekTime,
        escapeshellarg($videoUrl),
        $size, $size,
        $size, $size,
        escapeshellarg($outputPath)
    );
    
    // Execute ffmpeg
    $output = [];
    $returnCode = 0;
    exec($cmd, $output, $returnCode);
    
    if ($returnCode !== 0) {
        error_log("ffmpeg thumbnail generation failed for $videoUrl: " . implode("\n", $output));
        return false;
    }
    
    // Verify file was created
    if (!file_exists($outputPath) || filesize($outputPath) < 100) {
        error_log("Thumbnail file not created or too small for $videoUrl");
        return false;
    }
    
    return true;
}

/**
 * Get thumbnails for multiple players efficiently
 * Returns array of playerId => thumbnailPath (or null)
 * 
 * @param array $playerIds Array of player IDs
 * @param PDO $db Database connection
 * @return array Associative array of player_id => thumbnail_path
 */
function getPlayerThumbnails($playerIds, $db) {
    $thumbnails = [];
    
    // First, check which thumbnails already exist
    $needGeneration = [];
    foreach ($playerIds as $playerId) {
        $thumbnailPath = THUMBNAIL_DIR . '/' . $playerId . '.jpg';
        if (file_exists($thumbnailPath)) {
            $thumbnails[$playerId] = 'images/player_thumbnails/' . $playerId . '.jpg';
        } else {
            $needGeneration[] = $playerId;
            $thumbnails[$playerId] = null;
        }
    }
    
    // If any need generation, get their intro URLs
    if (!empty($needGeneration)) {
        $placeholders = implode(',', array_fill(0, count($needGeneration), '?'));
        try {
            $stmt = $db->prepare("SELECT Player_ID, Intro_Url FROM Players WHERE Player_ID IN ($placeholders)");
            $stmt->execute($needGeneration);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($results as $row) {
                if (!empty($row['Intro_Url'])) {
                    $playerId = $row['Player_ID'];
                    $thumbnailPath = THUMBNAIL_DIR . '/' . $playerId . '.jpg';
                    
                    // Generate thumbnail (this may take time for first load)
                    if (generateThumbnail($row['Intro_Url'], $thumbnailPath)) {
                        $thumbnails[$playerId] = 'images/player_thumbnails/' . $playerId . '.jpg';
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Failed to get intro URLs for thumbnail generation: " . $e->getMessage());
        }
    }
    
    return $thumbnails;
}

/**
 * Check if a player has an intro video
 * 
 * @param int $playerId
 * @param PDO $db
 * @return bool
 */
function playerHasIntro($playerId, $db) {
    try {
        $stmt = $db->prepare("SELECT Intro_Url FROM Players WHERE Player_ID = ? AND Intro_Url IS NOT NULL AND Intro_Url != ''");
        $stmt->execute([$playerId]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}
