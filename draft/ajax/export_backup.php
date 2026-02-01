<?php
/**
 * Export Draft Data as ZIP Backup
 * Creates a complete backup of all draft JSON files
 */

require_once __DIR__ . '/../includes/data.php';

$session = get_session();
if (!$session) {
    http_response_code(404);
    die('No active draft session');
}

// Check if ZipArchive is available
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    die('ZIP extension not available. Please download the CSV export instead.');
}

$dataDir = __DIR__ . '/../data/';
$files = ['session.json', 'teams.json', 'players.json', 'events.json', 'audit.json'];

// Sanitize draft name for filename
$draftName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $session['name']);
$timestamp = date('Y-m-d_His');
$zipFilename = "{$draftName}_backup_{$timestamp}.zip";

// Create temp file for ZIP
$tempFile = tempnam(sys_get_temp_dir(), 'draft_backup_');

$zip = new ZipArchive();
if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    die('Failed to create ZIP file');
}

// Add all JSON files to ZIP
foreach ($files as $file) {
    $path = $dataDir . $file;
    if (file_exists($path)) {
        $zip->addFile($path, $file);
    }
}

// Add a README with restore instructions
$readme = "# Draft Backup: {$session['name']}
Created: " . date('Y-m-d H:i:s') . "
Status: {$session['status']}
Current Pick: {$session['current_pick_number']}

## To Restore This Draft:

1. Stop any running draft
2. Copy all JSON files to: /draft/data/
3. Refresh the admin page

## Files Included:
- session.json: Draft settings, status, admin token
- teams.json: Team names, tokens, logos
- players.json: All players with status
- events.json: Pick history
- audit.json: Audit log

## Important URLs (may need to update host):
- Admin: {your-host}/draft/admin/?token={$session['admin_token']}
- Public: {your-host}/draft/public/
";

// Add team tokens to readme
$teams = get_teams();
foreach ($teams as $team) {
    $readme .= "- {$team['name']}: ?token={$team['token']}\n";
}

$zip->addFromString('README.txt', $readme);

$zip->close();

// Send ZIP file
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
header('Content-Length: ' . filesize($tempFile));
header('Cache-Control: no-cache, must-revalidate');

readfile($tempFile);

// Clean up temp file
unlink($tempFile);
