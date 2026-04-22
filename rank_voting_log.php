<?php
/**
 * Admin view: community rankings voting append-only log (rankings/voting_log.txt).
 * Same access as rankings voting controls (edit player, team, stats).
 */

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/db.php';

$required_permission = 'edit player, team, stats';
include __DIR__ . '/includes/check_permission_updated.php';

try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

require_once __DIR__ . '/rankings/voting_logic.php';

/**
 * Split log file into logical entries (multi-line APPLY blocks are one entry).
 *
 * @return array<int, string>
 */
function rank_voting_log_split_entries(string $content): array
{
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $lines = explode("\n", $content);
    $entries = [];
    $buf = '';
    foreach ($lines as $line) {
        if (preg_match('/^\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\]/', $line)) {
            if ($buf !== '') {
                $entries[] = $buf;
            }
            $buf = $line;
        } else {
            $buf .= ($buf === '' ? '' : "\n") . $line;
        }
    }
    if ($buf !== '') {
        $entries[] = $buf;
    }

    return $entries;
}

/**
 * @return array{ts: ?string, kind: string, rest: string, raw: string}
 */
function rank_voting_log_classify_entry(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return ['ts' => null, 'kind' => 'empty', 'rest' => '', 'raw' => $raw];
    }
    $nl = strpos($raw, "\n");
    $firstLine = $nl === false ? $raw : substr($raw, 0, $nl);
    $ts = null;
    $rest = $firstLine;
    if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $firstLine, $m)) {
        $ts = $m[1];
        $rest = $m[2];
    }

    $kind = 'other';
    if (strpos($rest, 'SESSION START') === 0) {
        $kind = 'session_start';
    } elseif (strpos($rest, 'SESSION CLOSED') === 0) {
        $kind = 'session_closed';
    } elseif (strpos($rest, 'BALLOT') === 0) {
        $kind = 'ballot';
    } elseif (strpos($rest, 'APPLY') === 0) {
        $kind = 'apply';
    } elseif (strpos($rest, 'DISCARD PREVIEW') === 0) {
        $kind = 'discard_preview';
    } elseif (strpos($rest, 'PUBLISH') === 0) {
        $kind = 'publish';
    } elseif (strpos($rest, 'CANCEL') === 0) {
        $kind = 'cancel';
    }

    return ['ts' => $ts, 'kind' => $kind, 'rest' => $rest, 'raw' => $raw];
}

/**
 * @return array{user_id: string, username: string, session: string, changes: array<string,int>}|null
 */
function rank_voting_log_parse_ballot(string $raw): ?array
{
    if (!preg_match('/BALLOT\s+user_id=([^\s]+)\s+username=(\S+)\s+session=([^\s]+)\s+changes=(.+)$/s', $raw, $m)) {
        return null;
    }
    $json = trim($m[4]);
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }
    $changes = [];
    foreach ($decoded as $k => $v) {
        $changes[(string) $k] = (int) $v;
    }

    return [
        'user_id' => (string) $m[1],
        'username' => (string) $m[2],
        'session' => (string) $m[3],
        'changes' => $changes,
    ];
}

$logPath = RANKINGS_VOTING_LOG_FILE;
$logReadable = is_readable($logPath);
$logContent = $logReadable ? (string) file_get_contents($logPath) : '';
$logBytes = $logReadable ? (int) (@filesize($logPath) ?: 0) : 0;

$entriesRaw = $logContent === '' ? [] : rank_voting_log_split_entries($logContent);

/** @var array<int, array{ts: ?string, kind: string, rest: string, raw: string}> */
$classified = [];
foreach ($entriesRaw as $er) {
    $classified[] = rank_voting_log_classify_entry($er);
}

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$kindFilter = isset($_GET['kind']) ? trim((string) $_GET['kind']) : '';
$voter = isset($_GET['voter']) ? trim((string) $_GET['voter']) : '';
$sessionFilter = isset($_GET['session']) ? trim((string) $_GET['session']) : '';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? min(200, max(10, (int) $_GET['per_page'])) : 50;

// --- Aggregate stats (full log) ---
$stats = [
    'entries_total' => count($classified),
    'session_start' => 0,
    'session_closed' => 0,
    'ballot' => 0,
    'apply' => 0,
    'discard_preview' => 0,
    'publish' => 0,
    'cancel' => 0,
    'other' => 0,
    'first_ts' => null,
    'last_ts' => null,
];
$ballotsByUser = [];
$playerBallotMentions = [];
foreach ($classified as $row) {
    $k = $row['kind'];
    if (isset($stats[$k])) {
        $stats[$k]++;
    } else {
        $stats['other']++;
    }
    if ($row['ts'] !== null) {
        if ($stats['first_ts'] === null || $row['ts'] < $stats['first_ts']) {
            $stats['first_ts'] = $row['ts'];
        }
        if ($stats['last_ts'] === null || $row['ts'] > $stats['last_ts']) {
            $stats['last_ts'] = $row['ts'];
        }
    }
    if ($k === 'ballot') {
        $parsed = rank_voting_log_parse_ballot($row['raw']);
        if ($parsed !== null) {
            $uid = $parsed['user_id'];
            $un = $parsed['username'];
            if (!isset($ballotsByUser[$uid])) {
                $ballotsByUser[$uid] = ['username' => $un, 'count' => 0, 'last_ts' => $row['ts']];
            }
            $ballotsByUser[$uid]['count']++;
            $ballotsByUser[$uid]['username'] = $un;
            if ($row['ts'] !== null) {
                if ($ballotsByUser[$uid]['last_ts'] === null || $row['ts'] >= $ballotsByUser[$uid]['last_ts']) {
                    $ballotsByUser[$uid]['last_ts'] = $row['ts'];
                }
            }
            foreach ($parsed['changes'] as $pname => $delta) {
                if ((int) $delta === 0) {
                    continue;
                }
                if (!isset($playerBallotMentions[$pname])) {
                    $playerBallotMentions[$pname] = ['ballots' => 0, 'sum_abs_delta' => 0];
                }
                $playerBallotMentions[$pname]['ballots']++;
                $playerBallotMentions[$pname]['sum_abs_delta'] += abs((int) $delta);
            }
        }
    }
}

uasort($ballotsByUser, static function (array $a, array $b): int {
    return ($b['count'] <=> $a['count']) ?: strcmp($a['username'], $b['username']);
});

uasort($playerBallotMentions, static function (array $a, array $b): int {
    return ($b['ballots'] <=> $a['ballots']) ?: 0;
});

// --- Filter for table ---
$filtered = $classified;
if ($kindFilter !== '' && $kindFilter !== 'all') {
    $filtered = array_values(array_filter($filtered, static function (array $r) use ($kindFilter): bool {
        return $r['kind'] === $kindFilter;
    }));
}
if ($q !== '') {
    $lq = strtolower($q);
    $filtered = array_values(array_filter($filtered, static function (array $r) use ($lq): bool {
        return strpos(strtolower($r['raw']), $lq) !== false;
    }));
}
if ($voter !== '') {
    $lv = strtolower($voter);
    $filtered = array_values(array_filter($filtered, static function (array $r) use ($lv): bool {
        if ($r['kind'] !== 'ballot') {
            return strpos(strtolower($r['raw']), $lv) !== false;
        }
        $p = rank_voting_log_parse_ballot($r['raw']);
        if ($p === null) {
            return strpos(strtolower($r['raw']), $lv) !== false;
        }
        if (strpos(strtolower($p['user_id']), $lv) !== false) {
            return true;
        }
        if (strpos(strtolower($p['username']), $lv) !== false) {
            return true;
        }
        return false;
    }));
}
if ($sessionFilter !== '') {
    $ls = strtolower($sessionFilter);
    $filtered = array_values(array_filter($filtered, static function (array $r) use ($ls): bool {
        return strpos(strtolower($r['raw']), $ls) !== false;
    }));
}

$filteredCount = count($filtered);
$totalPages = max(1, (int) ceil($filteredCount / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$pageRows = array_slice($filtered, $offset, $perPage);

$queryBase = $_GET;
unset($queryBase['page']);
$buildPageLink = static function (int $p) use ($queryBase): string {
    $queryBase['page'] = (string) $p;

    return '?' . http_build_query($queryBase);
};

$pageTitle = 'Rankings voting log';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="images/favicon.png" type="image/png">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> - FSL</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&family=Exo+2:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <style>
        body { background: linear-gradient(135deg, #0a0a0f 0%, #1a1a2e 50%, #0a0a0f 100%); min-height: 100vh; color: #e8e8f0; }
        .rvl-wrap { max-width: 1200px; margin: 0 auto; padding: 2rem 1rem 4rem; }
        .rvl-title { font-family: 'Rajdhani', sans-serif; font-weight: 700; border-bottom: 2px solid rgba(108, 92, 231, 0.35); padding-bottom: .5rem; margin-bottom: 1rem; }
        .stat-card { background: rgba(22, 22, 40, 0.85); border: 1px solid rgba(108, 92, 231, 0.25); border-radius: 8px; padding: 1rem; height: 100%; }
        .stat-card .label { font-size: .75rem; text-transform: uppercase; letter-spacing: .06em; color: #a8a8c8; margin-bottom: .25rem; }
        .stat-card .value { font-size: 1.35rem; font-weight: 600; color: #fff; }
        .log-pre { background: #0f0f18; color: #d6d6e8; border: 1px solid #2a2a44; border-radius: 6px; padding: .75rem; font-size: .8rem; white-space: pre-wrap; word-break: break-word; max-height: 280px; overflow: auto; margin: 0; }
        .badge-kind { font-size: .75rem; }
        table.table-dark { background: rgba(18, 18, 32, 0.92); }
        .table-dark td, .table-dark th { border-color: #333352; }
        .rvl-muted { color: #9898b8; font-size: .9rem; }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>

<div class="rvl-wrap container-fluid">
    <h1 class="rvl-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="rvl-muted mb-4">
        Source: <code><?= htmlspecialchars(basename($logPath), ENT_QUOTES, 'UTF-8') ?></code>
        <?php if ($logReadable): ?>
            · <?= number_format($logBytes) ?> bytes
        <?php else: ?>
            · <span class="text-warning">not readable</span>
        <?php endif; ?>
        <?php if ($stats['first_ts'] && $stats['last_ts']): ?>
            · span <?= htmlspecialchars($stats['first_ts'], ENT_QUOTES, 'UTF-8') ?> → <?= htmlspecialchars($stats['last_ts'], ENT_QUOTES, 'UTF-8') ?>
        <?php endif; ?>
    </p>

    <div class="row mb-3">
        <div class="col-md-3 mb-3">
            <div class="stat-card">
                <div class="label">Log entries</div>
                <div class="value"><?= (int) $stats['entries_total'] ?></div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="stat-card">
                <div class="label">Ballot events</div>
                <div class="value"><?= (int) $stats['ballot'] ?></div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="stat-card">
                <div class="label">Sessions started</div>
                <div class="value"><?= (int) $stats['session_start'] ?></div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="stat-card">
                <div class="label">Publish / cancel</div>
                <div class="value"><?= (int) $stats['publish'] ?> / <?= (int) $stats['cancel'] ?></div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-lg-6 mb-3">
            <h2 class="h5 mb-3">Voters (from ballot lines)</h2>
            <?php if ($ballotsByUser === []): ?>
                <p class="rvl-muted mb-0">No ballot lines parsed yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-dark table-striped mb-0">
                        <thead><tr><th>Username</th><th>User id</th><th>Ballots</th><th>Last (UTC)</th></tr></thead>
                        <tbody>
                        <?php foreach ($ballotsByUser as $uid => $info): ?>
                            <tr>
                                <td><?= htmlspecialchars($info['username'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><code><?= htmlspecialchars($uid, ENT_QUOTES, 'UTF-8') ?></code></td>
                                <td><?= (int) $info['count'] ?></td>
                                <td><?= $info['last_ts'] !== null ? htmlspecialchars($info['last_ts'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <div class="col-lg-6 mb-3">
            <h2 class="h5 mb-3">Players adjusted on ballots</h2>
            <p class="rvl-muted small">Counts non-zero deltas per ballot line (not net rank points).</p>
            <?php if ($playerBallotMentions === []): ?>
                <p class="rvl-muted mb-0">No player deltas recorded.</p>
            <?php else: ?>
                <div class="table-responsive" style="max-height: 320px; overflow: auto;">
                    <table class="table table-sm table-dark table-striped mb-0">
                        <thead><tr><th>Player</th><th>Ballots w/ move</th><th>Σ |Δ|</th></tr></thead>
                        <tbody>
                        <?php foreach ($playerBallotMentions as $pname => $agg): ?>
                            <tr>
                                <td><?= htmlspecialchars($pname, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int) $agg['ballots'] ?></td>
                                <td><?= (int) $agg['sum_abs_delta'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card bg-dark border-secondary mb-4">
        <div class="card-body">
            <form method="get" class="form-row align-items-end">
                <div class="form-group col-md-4">
                    <label for="q" class="small text-muted mb-1">Search text</label>
                    <input type="text" name="q" id="q" class="form-control form-control-sm" placeholder="Substring in any entry…" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group col-md-2">
                    <label for="kind" class="small text-muted mb-1">Event type</label>
                    <select name="kind" id="kind" class="form-control form-control-sm">
                        <?php
                        $kinds = [
                            'all' => 'All',
                            'session_start' => 'Session start',
                            'session_closed' => 'Session closed',
                            'ballot' => 'Ballot',
                            'apply' => 'Apply',
                            'discard_preview' => 'Discard preview',
                            'publish' => 'Publish',
                            'cancel' => 'Cancel',
                            'other' => 'Other',
                        ];
                        foreach ($kinds as $kv => $label):
                        ?>
                            <option value="<?= htmlspecialchars($kv, ENT_QUOTES, 'UTF-8') ?>"<?= $kindFilter === $kv || ($kv === 'all' && $kindFilter === '') ? ' selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-3">
                    <label for="voter" class="small text-muted mb-1">Voter (user id or name)</label>
                    <input type="text" name="voter" id="voter" class="form-control form-control-sm" placeholder="e.g. KJ2 or usr_…" value="<?= htmlspecialchars($voter, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group col-md-3">
                    <label for="session" class="small text-muted mb-1">Session id substring</label>
                    <input type="text" name="session" id="session" class="form-control form-control-sm" value="<?= htmlspecialchars($sessionFilter, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group col-md-2">
                    <label for="per_page" class="small text-muted mb-1">Per page</label>
                    <select name="per_page" id="per_page" class="form-control form-control-sm">
                        <?php foreach ([25, 50, 100, 200] as $n): ?>
                            <option value="<?= $n ?>"<?= $perPage === $n ? ' selected' : '' ?>><?= $n ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm btn-block">Apply</button>
                </div>
                <div class="form-group col-md-2">
                    <a href="<?= htmlspecialchars(basename($_SERVER['PHP_SELF'] ?? 'rank_voting_log.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-light btn-sm btn-block">Reset</a>
                </div>
            </form>
            <p class="mb-0 mt-2 small text-muted">
                Showing <?= number_format(min($filteredCount, $offset + count($pageRows))) ?> of <?= number_format($filteredCount) ?> matching entries (page <?= (int) $page ?> / <?= (int) $totalPages ?>).
            </p>
        </div>
    </div>

    <div class="table-responsive mb-3">
        <table class="table table-sm table-dark table-bordered">
            <thead class="thead-light">
            <tr>
                <th style="width:13rem;">Time (UTC)</th>
                <th style="width:9rem;">Type</th>
                <th>Entry</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($pageRows as $row): ?>
                <?php
                $kind = $row['kind'];
                $badge = 'badge-secondary';
                if ($kind === 'ballot') {
                    $badge = 'badge-info';
                } elseif (in_array($kind, ['session_start', 'session_closed'], true)) {
                    $badge = 'badge-warning';
                } elseif ($kind === 'apply') {
                    $badge = 'badge-primary';
                } elseif ($kind === 'publish') {
                    $badge = 'badge-success';
                } elseif ($kind === 'cancel' || $kind === 'discard_preview') {
                    $badge = 'badge-danger';
                }
                ?>
                <tr>
                    <td class="text-nowrap small"><?= $row['ts'] !== null ? htmlspecialchars($row['ts'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
                    <td><span class="badge <?= $badge ?> badge-kind"><?= htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><pre class="log-pre"><?= htmlspecialchars($row['raw'], ENT_QUOTES, 'UTF-8') ?></pre></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($pageRows === []): ?>
                <tr><td colspan="3" class="text-center text-muted py-4">No entries match the current filters.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav aria-label="Log pagination">
            <ul class="pagination pagination-sm justify-content-center">
                <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= $page <= 1 ? '#' : htmlspecialchars($buildPageLink($page - 1), ENT_QUOTES, 'UTF-8') ?>">Prev</a>
                </li>
                <li class="page-item disabled"><span class="page-link"><?= (int) $page ?> / <?= (int) $totalPages ?></span></li>
                <li class="page-item<?= $page >= $totalPages ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= $page >= $totalPages ? '#' : htmlspecialchars($buildPageLink($page + 1), ENT_QUOTES, 'UTF-8') ?>">Next</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>

    <div class="mt-5 pt-3 border-top border-secondary">
        <h2 class="h6 text-muted">Event counts (full file)</h2>
        <ul class="small text-muted mb-0">
            <li>session_start: <?= (int) $stats['session_start'] ?></li>
            <li>session_closed: <?= (int) $stats['session_closed'] ?></li>
            <li>ballot: <?= (int) $stats['ballot'] ?></li>
            <li>apply: <?= (int) $stats['apply'] ?></li>
            <li>discard_preview: <?= (int) $stats['discard_preview'] ?></li>
            <li>publish: <?= (int) $stats['publish'] ?></li>
            <li>cancel: <?= (int) $stats['cancel'] ?></li>
            <li>other / unrecognized: <?= (int) $stats['other'] ?></li>
        </ul>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
