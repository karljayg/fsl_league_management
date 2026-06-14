<?php
/**
 * Sync player "name" fields in rankings/rankings.json to Players.Real_Name
 * when they match case-insensitively (e.g. NukLeo -> NuKLeO after a DB case fix).
 *
 * CLI:
 *   php rankings/sync_names_from_db.php [--dry-run]
 *
 * Web (admin only):
 *   /fsl/rankings/sync_names_from_db.php?dry_run=1
 *   /fsl/rankings/sync_names_from_db.php          (apply)
 */
function syncRankingsNamesFromDb(PDO $db, string $rankingsPath, bool $dryRun): array
{
    $lines = [];

    if (!is_readable($rankingsPath)) {
        throw new RuntimeException("Cannot read: {$rankingsPath}");
    }

    $raw = file_get_contents($rankingsPath);
    $rankings = json_decode($raw, true);
    if (!is_array($rankings)) {
        throw new RuntimeException('Invalid JSON in rankings.json');
    }

    /** @var array<string, string> */
    $realByLower = [];
    $stmt = $db->query('SELECT Real_Name FROM Players');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $name = trim((string) $row['Real_Name']);
        if ($name === '') {
            continue;
        }
        $realByLower[strtolower($name)] = $name;
    }

    $updated = 0;
    $unchanged = 0;
    $unmatched = [];

    foreach ($rankings as $i => &$entry) {
        $jsonName = trim((string) ($entry['name'] ?? ''));
        if ($jsonName === '') {
            $unmatched[] = ['rank' => $entry['rank'] ?? ($i + 1), 'name' => '(empty)'];
            continue;
        }

        $key = strtolower($jsonName);
        if (!isset($realByLower[$key])) {
            $unmatched[] = ['rank' => $entry['rank'] ?? ($i + 1), 'name' => $jsonName];
            continue;
        }

        $dbName = $realByLower[$key];
        if ($jsonName === $dbName) {
            $unchanged++;
            continue;
        }

        $lines[] = "  [{$entry['rank']}] {$jsonName} -> {$dbName}";
        $entry['name'] = $dbName;
        $updated++;
    }
    unset($entry);

    $lines[] = '';
    $lines[] = "Summary: {$updated} updated, {$unchanged} already matched, " . count($unmatched) . ' unmatched';

    if ($unmatched !== []) {
        $lines[] = '';
        $lines[] = 'Unmatched (no Players.Real_Name, case-insensitive):';
        foreach ($unmatched as $row) {
            $lines[] = "  rank {$row['rank']}: {$row['name']}";
        }
    }

    $backupPath = null;
    if ($updated > 0 && !$dryRun) {
        $encoded = json_encode($rankings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode JSON');
        }
        $encoded .= "\n";

        $backupPath = $rankingsPath . '.bak.' . date('Ymd_His');
        if (!copy($rankingsPath, $backupPath)) {
            throw new RuntimeException("Failed to create backup: {$backupPath}");
        }
        if (file_put_contents($rankingsPath, $encoded) === false) {
            throw new RuntimeException('Failed to write rankings.json');
        }

        $lines[] = '';
        $lines[] = "Wrote {$rankingsPath}";
        $lines[] = "Backup: {$backupPath}";
    } elseif ($updated > 0 && $dryRun) {
        $lines[] = '';
        $lines[] = 'Dry run: rankings.json not modified.';
    } elseif ($updated === 0) {
        $lines[] = $dryRun ? 'Dry run: nothing to write.' : 'No changes needed.';
    }

    return [
        'lines' => $lines,
        'updated' => $updated,
        'unmatched' => count($unmatched),
        'backup_path' => $backupPath,
    ];
}

function syncRankingsNamesUserHasAdminRole(PDO $db, $userId): bool
{
    if ($userId === null || $userId === '' || $userId === 0 || $userId === '0') {
        return false;
    }

    $stmt = $db->prepare("
        SELECT COUNT(*) AS c
        FROM ws_user_roles ur
        JOIN ws_roles r ON ur.role_id = r.role_id
        WHERE ur.user_id = ? AND (r.role_id = 1 OR r.role_name = 'admin')
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row && (int) $row['c'] > 0;
}

$isCli = php_sapi_name() === 'cli';
$rankingsPath = __DIR__ . '/rankings.json';

if ($isCli) {
    $dryRun = in_array('--dry-run', $argv ?? [], true);
    require_once dirname(__DIR__) . '/includes/db.php';

    try {
        $result = syncRankingsNamesFromDb($db, $rankingsPath, $dryRun);
        echo implode("\n", $result['lines']) . "\n";
        if ($result['updated'] === 0 && $result['unmatched'] > 0) {
            exit(2);
        }
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

session_start();
require_once dirname(__DIR__) . '/includes/db.php';

if (!isset($_SESSION['user_id']) || !syncRankingsNamesUserHasAdminRole($db, $_SESSION['user_id'])) {
    http_response_code(403);
    die('Permission denied: admin only.');
}

$dryRun = isset($_GET['dry_run']) || isset($_POST['dry_run']);
header('Content-Type: text/plain; charset=utf-8');

try {
    $result = syncRankingsNamesFromDb($db, $rankingsPath, $dryRun);
    echo implode("\n", $result['lines']) . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo $e->getMessage() . "\n";
}
