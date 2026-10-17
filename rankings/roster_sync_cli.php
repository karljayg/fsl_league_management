<?php
/**
 * CLI: php rankings/roster_sync_cli.php [--dry-run]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/rankings_roster.php';

echo json_encode(rankings_roster_sync($db, in_array('--dry-run', $argv, true)), JSON_PRETTY_PRINT), "\n";
