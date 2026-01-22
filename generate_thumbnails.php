<?php
/**
 * Batch Thumbnail Generator
 * Run this script from command line to generate all player thumbnails
 * Usage: php generate_thumbnails.php
 * 
 * Options:
 *   --force    Regenerate all thumbnails (delete existing first)
 *   --player=N Generate only for specific player ID
 */

// Increase execution time for batch processing
set_time_limit(0);
ini_set('memory_limit', '256M');

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/player_thumbnail.php';

// Parse command line arguments
$force = in_array('--force', $argv ?? []);
$specificPlayer = null;
foreach ($argv ?? [] as $arg) {
    if (strpos($arg, '--player=') === 0) {
        $specificPlayer = (int) substr($arg, 9);
    }
}

echo "=== FSL Player Thumbnail Generator ===\n\n";

// Connect to database
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}

// Get players with intro URLs
$query = "SELECT Player_ID, Real_Name, Intro_Url FROM Players WHERE Intro_Url IS NOT NULL AND Intro_Url != ''";
if ($specificPlayer) {
    $query .= " AND Player_ID = " . $specificPlayer;
}
$query .= " ORDER BY Player_ID";

$stmt = $db->query($query);
$players = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($players) . " players with intro videos.\n\n";

$generated = 0;
$skipped = 0;
$failed = 0;

foreach ($players as $player) {
    $playerId = $player['Player_ID'];
    $name = $player['Real_Name'];
    $introUrl = $player['Intro_Url'];
    
    // Use sanitized player name for filename
    $safeFilename = sanitizeFilename($name);
    $thumbnailPath = THUMBNAIL_DIR . '/' . $safeFilename . '.png';
    
    // Also check for old ID-based files and delete them if force mode
    $oldPath = THUMBNAIL_DIR . '/' . $playerId . '.png';
    if ($force && file_exists($oldPath) && $oldPath !== $thumbnailPath) {
        unlink($oldPath);
    }
    
    // Check if thumbnail exists
    if (file_exists($thumbnailPath)) {
        if ($force) {
            echo "[$playerId] $name ($safeFilename.png) - Deleting existing...\n";
            unlink($thumbnailPath);
        } else {
            echo "[$playerId] $name ($safeFilename.png) - Already exists, skipping.\n";
            $skipped++;
            continue;
        }
    }
    
    echo "[$playerId] $name - Generating $safeFilename.png from: " . basename($introUrl) . "... ";
    
    $startTime = microtime(true);
    $success = generateThumbnail($introUrl, $thumbnailPath);
    $elapsed = round(microtime(true) - $startTime, 2);
    
    if ($success) {
        $size = filesize($thumbnailPath);
        echo "OK ({$elapsed}s, " . round($size/1024, 1) . "KB)\n";
        $generated++;
    } else {
        echo "FAILED\n";
        $failed++;
    }
    
    // Small delay to avoid hammering the server
    usleep(100000); // 0.1 second
}

echo "\n=== Summary ===\n";
echo "Generated: $generated\n";
echo "Skipped (existing): $skipped\n";
echo "Failed: $failed\n";
echo "\nThumbnails stored in: " . THUMBNAIL_DIR . "\n";
