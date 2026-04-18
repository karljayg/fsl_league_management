<?php
/**
 * Map veto admin — JSON-backed sessions, token URLs (FSL managers).
 */

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/db.php';

$required_permission = 'manage fsl schedule';
require_once __DIR__ . '/includes/check_permission_updated.php';

require_once __DIR__ . '/includes/map_veto.php';
require_once __DIR__ . '/includes/map_veto_upload.php';

/** @var array<int, string> Player_ID => Real_Name */
$mv_db_players = [];
try {
    $mvPs = $db->query('SELECT Player_ID, Real_Name FROM Players ORDER BY Real_Name ASC');
    foreach ($mvPs->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (int) ($row['Player_ID'] ?? 0);
        $rn = trim((string) ($row['Real_Name'] ?? ''));
        if ($pid > 0 && $rn !== '') {
            $mv_db_players[$pid] = $rn;
        }
    }
} catch (Throwable $e) {
    $mv_db_players = [];
}

/** @var array<string, array{rank: int, group: int|null}|null> For session form hints (JSON key = Player_ID string) */
$mv_player_rank_hints = [];
foreach ($mv_db_players as $pid => $rn) {
    $full = map_veto_lookup_ranking_full_by_name($rn);
    $mv_player_rank_hints[(string) $pid] = $full === null ? null : [
        'rank' => $full['rank'],
        'group' => $full['group'],
    ];
}

$mv_player_name_counts = [];
foreach ($mv_db_players as $rn) {
    $mv_player_name_counts[$rn] = ($mv_player_name_counts[$rn] ?? 0) + 1;
}
/** @var array<int, string> Display label for autocomplete (disambiguate duplicate Real_Name) */
$mv_player_id_to_label = [];
foreach ($mv_db_players as $pid => $rn) {
    $mv_player_id_to_label[$pid] = (($mv_player_name_counts[$rn] ?? 0) > 1)
        ? $rn . ' (#' . $pid . ')'
        : $rn;
}
/** @var array<string, int> Exact label => Player_ID for JS */
$mv_player_label_to_id = [];
foreach ($mv_player_id_to_label as $pid => $lbl) {
    $mv_player_label_to_id[$lbl] = $pid;
}

$mv_autocomplete_labels = array_keys($mv_player_label_to_id);
natcasesort($mv_autocomplete_labels);
$mv_autocomplete_labels = array_values($mv_autocomplete_labels);

$mv_form_player_a_id = 0;
$mv_form_player_b_id = 0;
/** @var ''|'a'|'b' */
$mv_form_seed_override = '';
$mv_form_match_title = '';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_session') {
            $seasonId = trim((string) ($_POST['season_id'] ?? ''));
            $mv_form_match_title = trim((string) ($_POST['match_title'] ?? ''));
            $mv_form_player_a_id = (int) ($_POST['player_a_id'] ?? 0);
            $mv_form_player_b_id = (int) ($_POST['player_b_id'] ?? 0);
            $mv_form_seed_override = trim((string) ($_POST['seed_higher_override'] ?? ''));
            if ($mv_form_seed_override !== '' && !in_array($mv_form_seed_override, ['a', 'b'], true)) {
                $mv_form_seed_override = '';
            }
            $pa = '';
            $pb = '';
            if ($mv_form_match_title === '') {
                $error = 'Match title is required.';
            } elseif ($mv_form_player_a_id <= 0 || $mv_form_player_b_id <= 0) {
                $error = 'Select both players.';
            } elseif ($mv_form_player_a_id === $mv_form_player_b_id) {
                $error = 'Choose two different players.';
            } elseif (!isset($mv_db_players[$mv_form_player_a_id], $mv_db_players[$mv_form_player_b_id])) {
                $error = 'Invalid player selection.';
            } else {
                $pa = $mv_db_players[$mv_form_player_a_id];
                $pb = $mv_db_players[$mv_form_player_b_id];
            }
            $bo = (int) ($_POST['best_of'] ?? 3);
            $timer = (int) ($_POST['timer_seconds'] ?? 60);
            $tie = trim((string) ($_POST['tie_break'] ?? 'random'));
            if (!in_array($tie, ['random', 'a', 'b'], true)) {
                $tie = 'random';
            }
            if ($error === '' && $pa !== '' && $pb !== '') {
                $seedHigherArg = $mv_form_seed_override === '' ? null : $mv_form_seed_override;
                $res = map_veto_create_session($seasonId, $mv_form_match_title, $pa, $pb, $bo, $timer, $tie, null, null, $seedHigherArg);
                if (isset($res['success']) && $res['success'] === false) {
                    $error = (string) ($res['message'] ?? 'Create failed');
                } else {
                    $message = 'Session created. Copy links below and start when ready.';
                    $mv_form_player_a_id = 0;
                    $mv_form_player_b_id = 0;
                    $mv_form_seed_override = '';
                    $mv_form_match_title = '';
                }
            }
        } elseif ($action === 'delete_session') {
            $id = trim((string) ($_POST['session_id'] ?? ''));
            if (!map_veto_delete_session($id)) {
                $error = 'Could not delete session.';
            } else {
                $message = 'Session deleted.';
            }
        } elseif ($action === 'start_session') {
            $id = trim((string) ($_POST['session_id'] ?? ''));
            $out = map_veto_start_session($id);
            if ($out === null) {
                $error = 'Could not start session.';
            } else {
                $message = 'Session started.';
            }
        } elseif ($action === 'cancel_session') {
            $id = trim((string) ($_POST['session_id'] ?? ''));
            if (map_veto_cancel_session($id) === null) {
                $error = 'Could not cancel.';
            } else {
                $message = 'Session cancelled.';
            }
        } elseif ($action === 'pause_session') {
            $id = trim((string) ($_POST['session_id'] ?? ''));
            if (map_veto_pause_session($id) === null) {
                $error = 'Could not pause session.';
            } else {
                $message = 'Session paused — timer frozen until you resume.';
            }
        } elseif ($action === 'resume_session') {
            $id = trim((string) ($_POST['session_id'] ?? ''));
            if (map_veto_resume_session($id) === null) {
                $error = 'Could not resume session.';
            } else {
                $message = 'Session resumed.';
            }
        } elseif ($action === 'regenerate_tokens') {
            $id = trim((string) ($_POST['session_id'] ?? ''));
            if (map_veto_regenerate_tokens($id) === null) {
                $error = 'Could not regenerate tokens.';
            } else {
                $message = 'Tokens regenerated — old links no longer work.';
            }
        } elseif ($action === 'reset_session') {
            $id = trim((string) ($_POST['session_id'] ?? ''));
            $res = map_veto_reset_session_to_start($id);
            if (empty($res['success'])) {
                $error = (string) ($res['message'] ?? 'Could not reset session.');
            } else {
                $message = 'Session reset to pending (fresh pool, no actions). Same player and watch URLs — click Start when ready.';
            }
        } elseif ($action === 'save_map') {
            $mapId = trim((string) ($_POST['map_id'] ?? ''));
            $name = trim((string) ($_POST['name'] ?? ''));
            $desc = trim((string) ($_POST['description'] ?? ''));
            $active = isset($_POST['is_active']);
            $overflow = isset($_POST['is_overflow_eligible']);
            $img = trim((string) ($_POST['image_url'] ?? ''));
            $maps = map_veto_load_maps();
            $foundIdx = null;
            foreach ($maps as $i => $row) {
                if ((string) ($row['id'] ?? '') === $mapId) {
                    $foundIdx = $i;
                    break;
                }
            }
            if ($foundIdx === null) {
                $error = 'Map not found.';
            } else {
                $uploadErr = (int) ($_FILES['map_image']['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($uploadErr !== UPLOAD_ERR_NO_FILE && isset($_FILES['map_image']) && is_array($_FILES['map_image'])) {
                    $up = map_veto_process_map_image_upload($mapId, $_FILES['map_image']);
                    if (!$up['ok']) {
                        $error = (string) ($up['message'] ?? 'Image upload failed.');
                    } elseif (!empty($up['url'])) {
                        $img = (string) $up['url'];
                    }
                }
                if ($error === '') {
                    $maps[$foundIdx]['name'] = $name;
                    $maps[$foundIdx]['description'] = $desc;
                    $maps[$foundIdx]['is_active'] = $active;
                    $maps[$foundIdx]['is_overflow_eligible'] = $overflow;
                    $maps[$foundIdx]['image_url'] = $img;
                    $maps[$foundIdx]['updated_at'] = gmdate('c');
                    map_veto_save_maps($maps);
                    $message = 'Map saved.';
                }
            }
        } elseif ($action === 'create_map') {
            $newId = trim((string) ($_POST['new_map_id'] ?? ''));
            $newName = trim((string) ($_POST['new_map_name'] ?? ''));
            $newDesc = trim((string) ($_POST['new_description'] ?? ''));
            $newImgUrl = trim((string) ($_POST['new_image_url'] ?? ''));
            $newActive = isset($_POST['new_is_active']);
            $newOverflow = isset($_POST['new_is_overflow_eligible']);
            if (!preg_match('/^mv_lotv_[a-z0-9_]{2,120}$/', $newId)) {
                $error = 'Map ID must be like mv_lotv_my_map (mv_lotv_, then lowercase letters, digits, underscores only; 2–120 chars after prefix).';
            } elseif ($newName === '') {
                $error = 'Map name is required.';
            } else {
                $maps = map_veto_load_maps();
                foreach ($maps as $ex) {
                    if ((string) ($ex['id'] ?? '') === $newId) {
                        $error = 'That map ID already exists.';
                        break;
                    }
                }
                if ($error === '') {
                    $img = $newImgUrl;
                    $uploadErr = (int) ($_FILES['new_map_image']['error'] ?? UPLOAD_ERR_NO_FILE);
                    if ($uploadErr !== UPLOAD_ERR_NO_FILE && isset($_FILES['new_map_image']) && is_array($_FILES['new_map_image'])) {
                        $up = map_veto_process_map_image_upload($newId, $_FILES['new_map_image']);
                        if (!$up['ok']) {
                            $error = (string) ($up['message'] ?? 'Image upload failed.');
                        } elseif (!empty($up['url'])) {
                            $img = (string) $up['url'];
                        }
                    }
                }
                if ($error === '') {
                    $maps[] = [
                        'id' => $newId,
                        'name' => $newName,
                        'description' => $newDesc,
                        'image_url' => $img,
                        'is_active' => $newActive,
                        'is_overflow_eligible' => $newOverflow,
                        'created_at' => gmdate('c'),
                        'updated_at' => gmdate('c'),
                    ];
                    map_veto_save_maps($maps);
                    $message = 'Map added.';
                }
            }
        } elseif ($action === 'create_season') {
            $newId = trim((string) ($_POST['new_season_id'] ?? ''));
            $newName = trim((string) ($_POST['new_season_name'] ?? ''));
            $newDesc = trim((string) ($_POST['new_season_description'] ?? ''));
            $copyFrom = trim((string) ($_POST['copy_maps_from'] ?? 'season_fsl_default'));
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $newId)) {
                $error = 'Season ID must start with a letter and contain only letters, numbers, underscores (max 64 chars).';
            } elseif ($newName === '') {
                $error = 'Season display name is required.';
            } else {
                $seasons = map_veto_load_seasons();
                foreach ($seasons as $ex) {
                    if ((string) ($ex['id'] ?? '') === $newId) {
                        $error = 'That season ID already exists.';
                        break;
                    }
                }
                if ($error === '') {
                    $enabled = [];
                    if ($copyFrom !== '__empty__') {
                        foreach ($seasons as $ex) {
                            if ((string) ($ex['id'] ?? '') === $copyFrom) {
                                $src = $ex['enabled_map_ids'] ?? [];
                                $enabled = is_array($src) ? $src : [];
                                break;
                            }
                        }
                    }
                    $enabled = array_values(array_filter(array_map('strval', $enabled)));
                    $seasons[] = [
                        'id' => $newId,
                        'name' => $newName,
                        'description' => $newDesc,
                        'is_active' => true,
                        'minimum_required_maps' => 7,
                        'enabled_map_ids' => $enabled,
                        'created_at' => gmdate('c'),
                        'updated_at' => gmdate('c'),
                    ];
                    map_veto_save_seasons($seasons);
                    $message = 'Season created. Enable maps below (≥7 active maps required to start sessions).';
                }
            }
        } elseif ($action === 'save_season') {
            $seasonId = trim((string) ($_POST['season_id'] ?? ''));
            $enabled = $_POST['enabled_map_ids'] ?? [];
            if (!is_array($enabled)) {
                $enabled = [];
            }
            $enabled = array_values(array_filter(array_map('strval', $enabled)));
            $seasons = map_veto_load_seasons();
            $ok = false;
            foreach ($seasons as &$s) {
                if ((string) ($s['id'] ?? '') === $seasonId) {
                    $s['enabled_map_ids'] = $enabled;
                    $nm = trim((string) ($_POST['season_name'] ?? ''));
                    $s['name'] = $nm !== '' ? $nm : (string) ($s['name'] ?? $seasonId);
                    $s['description'] = trim((string) ($_POST['season_description'] ?? ''));
                    $s['is_active'] = isset($_POST['season_is_active']);
                    $s['updated_at'] = gmdate('c');
                    $ok = true;
                    break;
                }
            }
            unset($s);
            if ($ok) {
                map_veto_save_seasons($seasons);
                $message = 'Season saved.';
            } else {
                $error = 'Season not found.';
            }
        }
    } catch (Throwable $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

$pageTitle = 'Map Veto (FSL Manager)';
$additionalCss = [];
require_once __DIR__ . '/includes/header.php';

$maps = map_veto_load_maps();
$seasons = map_veto_load_seasons();
$sessions = map_veto_list_sessions();

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$mvBasePath = map_veto_url_base_path();
$absoluteMvBase = $scheme . '://' . $host . $mvBasePath;

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Lowercase blob for client-side map search (name, id, spaced id, optional description prefix). */
function mv_map_search_blob(string $name, string $mid, string $descExtra = ''): string
{
    $midLower = mb_strtolower($mid, 'UTF-8');
    $spaced = mb_strtolower(str_replace(['_', '-'], ' ', $mid), 'UTF-8');
    $parts = [mb_strtolower($name, 'UTF-8'), $midLower, $spaced];
    if ($descExtra !== '') {
        $parts[] = mb_strtolower(mb_substr($descExtra, 0, 220, 'UTF-8'), 'UTF-8');
    }
    $out = trim(implode(' ', $parts));

    return preg_replace('/\s+/u', ' ', $out);
}

?>

<div class="map-veto-manager">

<?php if ($message): ?>
    <div class="alert alert-success py-2"><?= h($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger py-2"><?= h($error) ?></div>
<?php endif; ?>

<h1 class="mv-title">Map veto</h1>
<p class="small text-center text-muted mb-3" style="max-width: 52rem; margin-left: auto; margin-right: auto;">
    Copy <strong style="color:#aadfff;">player</strong> / <strong style="color:#aadfff;">watch</strong> URLs. Players come from the database; type to filter the list, use <strong>↑</strong>/<strong>↓</strong> to move, <strong>Tab</strong> or <strong>Enter</strong> to select, <strong>Esc</strong> to close. Rank / group hints use <code>rankings/rankings.json</code> when <code>Real_Name</code> matches. By default the <strong>higher seed</strong> for the veto flow follows those ladder ranks; if ranks tie (or both missing), <strong>Tie</strong> applies. Use <strong>Higher seed</strong> below only when you must override who is treated as higher for this veto.
</p>

<ul class="nav nav-tabs mb-2" role="tablist">
    <li class="nav-item">
        <a class="nav-link active py-2 px-3" data-toggle="tab" href="#tab-sessions" role="tab">Sessions</a>
    </li>
    <li class="nav-item">
        <a class="nav-link py-2 px-3" data-toggle="tab" href="#tab-maps" role="tab">Maps</a>
    </li>
    <li class="nav-item">
        <a class="nav-link py-2 px-3" data-toggle="tab" href="#tab-seasons" role="tab">Seasons</a>
    </li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="tab-sessions" role="tabpanel">
        <div class="stats-container">
            <form method="post" class="mb-0" id="mv-form-create-session" autocomplete="off">
                <input type="hidden" name="action" value="create_session">
                <div class="form-row align-items-end">
                    <div class="form-group col-12 mb-2">
                        <label class="small mb-1 d-block" for="mv-match-title" style="color:#00d4ff;">Match title</label>
                        <input type="text"
                               name="match_title"
                               id="mv-match-title"
                               class="form-control"
                               required
                               maxlength="200"
                               placeholder="e.g. Regular season — Week 4"
                               value="<?= h($mv_form_match_title) ?>">
                    </div>
                </div>
                <div class="form-row align-items-end">
                    <div class="form-group col-lg-2 col-md-4 mb-2 mb-lg-2">
                        <label class="small mb-1 d-block" style="color:#00d4ff;">Season</label>
                        <select name="season_id" class="form-control" required>
                            <?php foreach ($seasons as $s): ?>
                                <?php if (isset($s['is_active']) && $s['is_active'] === false) {
                                    continue;
                                } ?>
                                <option value="<?= h((string) ($s['id'] ?? '')) ?>"><?= h((string) ($s['name'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-lg-3 col-md-4 mb-2">
                        <label class="small mb-1 d-block" for="mv-player-a-q" style="color:#00d4ff;">Player A</label>
                        <input type="hidden" name="player_a_id" id="mv-player-a-id" value="<?= $mv_form_player_a_id > 0 ? (int) $mv_form_player_a_id : '' ?>">
                        <div class="position-relative">
                            <input type="text"
                                   id="mv-player-a-q"
                                   class="form-control"
                                   autocomplete="off"
                                   placeholder="<?= count($mv_db_players) ? 'Start typing a name…' : 'No players in DB' ?>"
                                   <?= count($mv_db_players) ? '' : 'disabled' ?>
                                   value="<?= $mv_form_player_a_id > 0 && isset($mv_player_id_to_label[$mv_form_player_a_id]) ? h($mv_player_id_to_label[$mv_form_player_a_id]) : '' ?>"
                                   aria-autocomplete="list"
                                   aria-controls="mv-player-a-ac-menu">
                            <div id="mv-player-a-ac-menu" class="mv-player-ac-menu" role="listbox" hidden></div>
                        </div>
                        <div id="mv-player-a-hint" class="small text-muted mt-1" style="min-height: 1.25rem;"></div>
                    </div>
                    <div class="form-group col-lg-3 col-md-4 mb-2">
                        <label class="small mb-1 d-block" for="mv-player-b-q" style="color:#00d4ff;">Player B</label>
                        <input type="hidden" name="player_b_id" id="mv-player-b-id" value="<?= $mv_form_player_b_id > 0 ? (int) $mv_form_player_b_id : '' ?>">
                        <div class="position-relative">
                            <input type="text"
                                   id="mv-player-b-q"
                                   class="form-control"
                                   autocomplete="off"
                                   placeholder="<?= count($mv_db_players) ? 'Start typing a name…' : 'No players in DB' ?>"
                                   <?= count($mv_db_players) ? '' : 'disabled' ?>
                                   value="<?= $mv_form_player_b_id > 0 && isset($mv_player_id_to_label[$mv_form_player_b_id]) ? h($mv_player_id_to_label[$mv_form_player_b_id]) : '' ?>"
                                   aria-autocomplete="list"
                                   aria-controls="mv-player-b-ac-menu">
                            <div id="mv-player-b-ac-menu" class="mv-player-ac-menu" role="listbox" hidden></div>
                        </div>
                        <div id="mv-player-b-hint" class="small text-muted mt-1" style="min-height: 1.25rem;"></div>
                    </div>
                    <div class="form-group col-6 col-lg-1 mb-2">
                        <label class="small mb-1 d-block" style="color:#00d4ff;">BO</label>
                        <select name="best_of" class="form-control">
                            <?php foreach ([1, 3, 5, 7, 9] as $bo): ?>
                                <option value="<?= $bo ?>" <?= $bo === 3 ? 'selected' : '' ?>><?= $bo ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-6 col-lg-1 mb-2">
                        <label class="small mb-1 d-block" style="color:#00d4ff;">Timer</label>
                        <input type="number" name="timer_seconds" class="form-control" value="60" min="15" max="600" title="Seconds">
                    </div>
                    <div class="form-group col-lg-2 col-md-6 mb-2">
                        <label class="small mb-1 d-block" style="color:#00d4ff;">Tie</label>
                        <select name="tie_break" class="form-control" title="When ladder ranks tie (including both unranked in JSON), choose random or who is seeded higher.">
                            <option value="random">Random</option>
                            <option value="a">A seeded</option>
                            <option value="b">B seeded</option>
                        </select>
                    </div>
                    <div class="form-group col-lg-auto mb-2">
                        <button type="submit" class="btn btn-primary">Create</button>
                    </div>
                </div>
                <div class="form-row align-items-end">
                    <div class="form-group col-lg-8 col-md-10 mb-0">
                        <label class="small mb-1 d-block" for="mv-seed-higher-override" style="color:#00d4ff;">Higher seed for this veto (optional)</label>
                        <select name="seed_higher_override" id="mv-seed-higher-override" class="form-control">
                            <option value="" <?= $mv_form_seed_override === '' ? 'selected' : '' ?>>Auto — higher seed follows ladder ranks from JSON (default)</option>
                            <option value="a" <?= $mv_form_seed_override === 'a' ? 'selected' : '' ?>>Override: treat Player A as higher seed than B</option>
                            <option value="b" <?= $mv_form_seed_override === 'b' ? 'selected' : '' ?>>Override: treat Player B as higher seed than A</option>
                        </select>
                        <small class="form-text text-muted mb-0">Overrides only who is treated as the higher ladder seed for <strong>this</strong> veto session (veto order / first turn). Does not change displayed ranks or the JSON file. When set, ladder comparison for seeding is skipped for this session.</small>
                    </div>
                </div>
            </form>
        </div>

        <h2>Sessions</h2>
        <?php if (count($sessions) === 0): ?>
            <p class="small text-muted mb-0">None yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table-mv">
                    <thead>
                        <tr>
                            <th>Match</th>
                            <th>Pool / BO</th>
                            <th>Status</th>
                            <th style="min-width:320px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $mvClipIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M4 1.5H3a2 2 0 0 0-2 2V14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3.5a2 2 0 0 0-2-2h-1v1h1a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1h1v-1z"/><path d="M9.5 1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-1a.5.5 0 0 1 .5-.5h3zm-3-1A1.5 1.5 0 0 0 5 1.5v1A1.5 1.5 0 0 0 6.5 4h3A1.5 1.5 0 0 0 11 2.5v-1A1.5 1.5 0 0 0 9.5 0h-3z"/></svg>';
                        ?>
                        <?php foreach ($sessions as $sess): ?>
                            <?php
                            $sid = (string) ($sess['id'] ?? '');
                            $tok = $sess['tokens'] ?? [];
                            $ta = (string) ($tok['player_a'] ?? '');
                            $tb = (string) ($tok['player_b'] ?? '');
                            $tp = (string) ($tok['public'] ?? '');
                            $urlA = $absoluteMvBase . '/player.php?t=' . rawurlencode($ta);
                            $urlB = $absoluteMvBase . '/player.php?t=' . rawurlencode($tb);
                            $urlW = $absoluteMvBase . '/watch.php?t=' . rawurlencode($tp);
                            $pa = $sess['player_a']['display_name'] ?? '';
                            $pb = $sess['player_b']['display_name'] ?? '';
                            $mTitle = trim((string) ($sess['match_title'] ?? ''));
                            $st = (string) ($sess['status'] ?? '');
                            $mvPaused = !empty($sess['paused']);
                            $sidHtmlId = 's' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $sid);
                            ?>
                            <tr>
                                <td>
                                    <?php if ($mTitle !== ''): ?>
                                        <div class="mb-1 font-weight-bold" style="color:#aadfff;"><?= h($mTitle) ?></div>
                                    <?php else: ?>
                                        <div class="mb-1 small text-muted font-italic">Untitled session</div>
                                    <?php endif; ?>
                                    <strong><?= h($pa) ?></strong> vs <strong><?= h($pb) ?></strong><br>
                                    <code class="small"><?= h($sid) ?></code>
                                </td>
                                <td><?= h((string) ($sess['season_name'] ?? '')) ?> · BO<?= (int) ($sess['best_of'] ?? 1) ?></td>
                                <td><?= h($st) ?><?= $mvPaused ? ' <span class="badge badge-warning">paused</span>' : '' ?></td>
                                <td>
                                    <?php if ($st === 'pending'): ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="start_session">
                                            <input type="hidden" name="session_id" value="<?= h($sid) ?>">
                                            <button type="submit" class="btn btn-sm btn-success mb-1">Start</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (($st === 'live_veto' || $st === 'live_order') && !$mvPaused): ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="pause_session">
                                            <input type="hidden" name="session_id" value="<?= h($sid) ?>">
                                            <button type="submit" class="btn btn-sm btn-warning mb-1">Pause</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (($st === 'live_veto' || $st === 'live_order') && $mvPaused): ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="resume_session">
                                            <input type="hidden" name="session_id" value="<?= h($sid) ?>">
                                            <button type="submit" class="btn btn-sm btn-info mb-1">Resume</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Reset this session to the beginning? All vetoes and map picks are cleared; the map pool is rebuilt from the current season. Player and watch links stay the same.');">
                                        <input type="hidden" name="action" value="reset_session">
                                        <input type="hidden" name="session_id" value="<?= h($sid) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-warning mb-1">Reset to start</button>
                                    </form>
                                    <?php if ($st !== 'completed' && $st !== 'cancelled'): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Cancel session?');">
                                            <input type="hidden" name="action" value="cancel_session">
                                            <input type="hidden" name="session_id" value="<?= h($sid) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger mb-1">Cancel</button>
                                        </form>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Regenerate tokens?');">
                                            <input type="hidden" name="action" value="regenerate_tokens">
                                            <input type="hidden" name="session_id" value="<?= h($sid) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary mb-1">New tokens</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this session permanently? Player and watch links will stop working.');">
                                        <input type="hidden" name="action" value="delete_session">
                                        <input type="hidden" name="session_id" value="<?= h($sid) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger mb-1">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <tr class="mv-sess-urls">
                                <td colspan="4" class="py-2">
                                    <div class="form-row">
                                        <div class="col-md-4 mb-1">
                                            <label class="small mb-0 d-block" style="color:#00d4ff;">Player A URL</label>
                                            <div class="input-group input-group-sm">
                                                <input type="text" class="form-control" id="mv-url-a-<?= h($sidHtmlId) ?>" readonly value="<?= h($urlA) ?>" onclick="this.select()">
                                                <div class="input-group-append">
                                                    <button type="button" class="btn btn-outline-secondary mv-copy-url-btn" data-copy-for="mv-url-a-<?= h($sidHtmlId) ?>" title="Copy to clipboard" aria-label="Copy Player A URL"><?= $mvClipIcon ?></button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4 mb-1">
                                            <label class="small mb-0 d-block" style="color:#00d4ff;">Player B URL</label>
                                            <div class="input-group input-group-sm">
                                                <input type="text" class="form-control" id="mv-url-b-<?= h($sidHtmlId) ?>" readonly value="<?= h($urlB) ?>" onclick="this.select()">
                                                <div class="input-group-append">
                                                    <button type="button" class="btn btn-outline-secondary mv-copy-url-btn" data-copy-for="mv-url-b-<?= h($sidHtmlId) ?>" title="Copy to clipboard" aria-label="Copy Player B URL"><?= $mvClipIcon ?></button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4 mb-1">
                                            <label class="small mb-0 d-block" style="color:#00d4ff;">Watch URL</label>
                                            <div class="input-group input-group-sm">
                                                <input type="text" class="form-control" id="mv-url-w-<?= h($sidHtmlId) ?>" readonly value="<?= h($urlW) ?>" onclick="this.select()">
                                                <div class="input-group-append">
                                                    <button type="button" class="btn btn-outline-secondary mv-copy-url-btn" data-copy-for="mv-url-w-<?= h($sidHtmlId) ?>" title="Copy to clipboard" aria-label="Copy watch URL"><?= $mvClipIcon ?></button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="tab-pane fade" id="tab-maps" role="tabpanel">
        <datalist id="mv-map-search-datalist">
            <?php foreach ($maps as $dm): ?>
                <option value="<?= h((string) ($dm['name'] ?? '')) ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <p class="small text-muted mb-2">Browse maps in a grid; <strong>Edit</strong> opens all fields. Use <strong>Add map</strong> for new entries (upload image or paste URL).</p>
        <div class="form-row mb-3 align-items-end">
            <div class="form-group col-md-8 col-lg-6 mb-0">
                <label for="mv-maps-tab-search" class="small mb-1 d-block" style="color:#00d4ff;">Search maps</label>
                <input type="search" id="mv-maps-tab-search" class="form-control" placeholder="Filter by name, id, or text in description…" autocomplete="off" data-mv-datalist="mv-map-search-datalist">
                <small class="form-text text-muted mb-0">Narrows the grid as you type; clear the box to show every map. Name autocomplete appears after <strong>3</strong> characters.</small>
            </div>
            <div class="form-group col-md-4 col-lg-6 mb-0 text-md-right">
                <button type="button" class="btn btn-success btn-sm mt-2 mt-md-0" data-toggle="modal" data-target="#mapCreateModal">Add map</button>
            </div>
        </div>
        <div class="row">
            <?php foreach ($maps as $m): ?>
                <?php
                $mid = (string) ($m['id'] ?? '');
                $fullDesc = (string) ($m['description'] ?? '');
                $snippet = $fullDesc;
                if (strlen($snippet) > 140) {
                    $snippet = substr($snippet, 0, 140) . '…';
                }
                $mvSearch = mv_map_search_blob((string) ($m['name'] ?? ''), $mid, $fullDesc);
                ?>
                <textarea id="mv-map-desc-bin-<?= h($mid) ?>" class="d-none" aria-hidden="true" data-map-desc-store="1"><?= htmlspecialchars($fullDesc, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></textarea>
                <div class="col-sm-6 col-lg-4 col-xl-3 mb-3 mv-map-grid-item" data-mv-search="<?= h($mvSearch) ?>">
                    <div class="card mv-map-card h-100 shadow-sm">
                        <div class="mv-map-card-img-wrap">
                            <?php if (!empty($m['image_url'])): ?>
                                <img src="<?= h((string) $m['image_url']) ?>" alt="" class="mv-map-card-img" loading="lazy">
                            <?php else: ?>
                                <div class="mv-map-card-img mv-map-card-img--empty d-flex align-items-center justify-content-center small text-muted">No image</div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body d-flex flex-column py-2 px-3">
                            <h6 class="card-title mb-1 text-white mv-map-card-title" title="<?= h((string) ($m['name'] ?? '')) ?>"><?= h((string) ($m['name'] ?? '')) ?></h6>
                            <?php if ($snippet !== ''): ?>
                                <p class="card-text small text-muted mb-2 flex-grow-1 mv-map-card-desc"><?= h($snippet) ?></p>
                            <?php else: ?>
                                <p class="card-text small text-muted mb-2 flex-grow-1 mv-map-card-desc">&nbsp;</p>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between align-items-center mt-auto pt-1 border-top border-secondary">
                                <div class="small">
                                    <?php if (!empty($m['is_active'])): ?>
                                        <span class="badge badge-success mr-1">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary mr-1">Off</span>
                                    <?php endif; ?>
                                    <?php if (!empty($m['is_overflow_eligible'])): ?>
                                        <span class="badge badge-info">Overflow</span>
                                    <?php endif; ?>
                                </div>
                                <button type="button"
                                    class="btn btn-sm btn-outline-info mv-map-edit-btn"
                                    data-map-id="<?= h($mid) ?>"
                                    data-map-name="<?= h((string) ($m['name'] ?? '')) ?>"
                                    data-map-image="<?= h((string) ($m['image_url'] ?? '')) ?>"
                                    data-map-active="<?= !empty($m['is_active']) ? '1' : '0' ?>"
                                    data-map-overflow="<?= !empty($m['is_overflow_eligible']) ? '1' : '0' ?>">
                                    Edit
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="modal fade" id="mapCreateModal" tabindex="-1" role="dialog" aria-labelledby="mapCreateModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
                <div class="modal-content mv-modal-content">
                    <form method="post" id="mapCreateForm" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="create_map">
                        <div class="modal-header border-secondary">
                            <h5 class="modal-title" id="mapCreateModalLabel">Add map</h5>
                            <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="form-group mb-2">
                                <label class="small mb-1 d-block" style="color:#00d4ff;">Map ID</label>
                                <input type="text" name="new_map_id" id="create_map_id" class="form-control font-monospace small" required maxlength="130" placeholder="mv_lotv_example_le" pattern="mv_lotv_[a-z0-9_]{2,120}" title="mv_lotv_ plus lowercase letters, digits, underscores">
                                <p class="small text-muted mb-0 mt-1">Permanent slug (e.g. <code class="text-muted">mv_lotv_foo_le</code>). Cannot be changed later.</p>
                            </div>
                            <div class="form-group mb-2">
                                <label class="small mb-1 d-block" style="color:#00d4ff;">Display name</label>
                                <input type="text" name="new_map_name" id="create_map_name" class="form-control" required maxlength="500" placeholder="Map name">
                            </div>
                            <div class="form-group mb-2">
                                <label class="small mb-1 d-block" style="color:#00d4ff;">Description</label>
                                <textarea name="new_description" id="create_map_description" class="form-control" rows="4" placeholder="Optional"></textarea>
                            </div>
                            <div class="form-group mb-2">
                                <label class="small mb-1 d-block" style="color:#00d4ff;">Image URL <span class="text-muted font-weight-normal">(optional if you upload)</span></label>
                                <input type="text" name="new_image_url" id="create_map_image_url" class="form-control font-monospace small" placeholder="/fsl/map-veto/data/images/…" autocomplete="off">
                            </div>
                            <div class="form-group mb-2">
                                <label class="small mb-1 d-block" style="color:#00d4ff;">Upload image</label>
                                <input type="file" name="new_map_image" id="create_map_image_file" class="form-control-file text-light" accept="image/jpeg,image/png,image/webp,image/gif">
                                <small class="form-text text-muted">JPEG, PNG, WebP, or GIF · max 10 MB. Upload overrides Image URL when both are set.</small>
                            </div>
                            <div class="form-row">
                                <div class="col-md-6">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="new_is_active" id="create_map_active" value="1" checked>
                                        <label class="form-check-label small" for="create_map_active">Active</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="new_is_overflow_eligible" id="create_map_overflow" value="1">
                                        <label class="form-check-label small" for="create_map_overflow">Overflow-eligible</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer border-secondary">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success">Create map</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="mapEditModal" tabindex="-1" role="dialog" aria-labelledby="mapEditModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
                <div class="modal-content mv-modal-content">
                    <form method="post" id="mapEditForm" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_map">
                        <input type="hidden" name="map_id" id="edit_map_id" value="">
                        <div class="modal-header border-secondary">
                            <h5 class="modal-title" id="mapEditModalLabel">Edit map</h5>
                            <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="form-group mb-2">
                                <label class="small mb-1 d-block" style="color:#00d4ff;">Name</label>
                                <input type="text" name="name" id="edit_map_name" class="form-control" required maxlength="500">
                            </div>
                            <div class="form-group mb-2">
                                <label class="small mb-1 d-block" style="color:#00d4ff;">Image URL</label>
                                <input type="text" name="image_url" id="edit_map_image" class="form-control font-monospace small" placeholder="/fsl/map-veto/data/images/…" autocomplete="off">
                                <p class="small text-muted mb-0 mt-1">Host-relative path or full URL. Upload below replaces the file and updates this path.</p>
                            </div>
                            <div class="form-group mb-2">
                                <label class="small mb-1 d-block" style="color:#00d4ff;">Replace image (upload)</label>
                                <input type="file" name="map_image" id="edit_map_image_file" class="form-control-file text-light" accept="image/jpeg,image/png,image/webp,image/gif">
                                <small class="form-text text-muted">JPEG, PNG, WebP, or GIF · max 10 MB · overwrites previous file for this map ID</small>
                            </div>
                            <div class="form-group mb-2">
                                <label class="small mb-1 d-block" style="color:#00d4ff;">Description</label>
                                <textarea name="description" id="edit_map_description" class="form-control" rows="6" placeholder="Shown in tooling / references"></textarea>
                            </div>
                            <div class="form-row">
                                <div class="col-md-6">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="edit_map_active" value="1">
                                        <label class="form-check-label small" for="edit_map_active">Active (available to seasons)</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="is_overflow_eligible" id="edit_map_overflow" value="1">
                                        <label class="form-check-label small" for="edit_map_overflow">Overflow-eligible (large BO pools)</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer border-secondary">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save map</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="tab-seasons" role="tabpanel">
        <div class="stats-container mb-4">
            <h2 class="mb-2">New season pool</h2>
            <p class="small text-muted mb-3">Creates a separate map list for veto sessions (e.g. Season 11). ID is permanent (slug); copy maps from an existing pool or start empty.</p>
            <form method="post">
                <input type="hidden" name="action" value="create_season">
                <div class="form-row">
                    <div class="form-group col-lg-3 col-md-6 mb-2">
                        <label class="small mb-1 d-block" style="color:#00d4ff;">Season ID</label>
                        <input type="text" name="new_season_id" class="form-control" required placeholder="e.g. season_fsl_11" pattern="[a-zA-Z][a-zA-Z0-9_]*" maxlength="64" title="Letter first; letters, digits, underscores">
                    </div>
                    <div class="form-group col-lg-4 col-md-6 mb-2">
                        <label class="small mb-1 d-block" style="color:#00d4ff;">Display name</label>
                        <input type="text" name="new_season_name" class="form-control" required placeholder="e.g. FSL Season 11">
                    </div>
                    <div class="form-group col-lg-5 col-md-12 mb-2">
                        <label class="small mb-1 d-block" style="color:#00d4ff;">Copy maps from</label>
                        <select name="copy_maps_from" class="form-control">
                            <option value="season_fsl_default">FSL Default Pool</option>
                            <?php foreach ($seasons as $opt): ?>
                                <?php $oid = (string) ($opt['id'] ?? ''); ?>
                                <?php if ($oid === '' || $oid === 'season_fsl_default') {
                                    continue;
                                } ?>
                                <option value="<?= h($oid) ?>"><?= h((string) ($opt['name'] ?? $oid)) ?> (<?= h($oid) ?>)</option>
                            <?php endforeach; ?>
                            <option value="__empty__">Empty — choose maps after create</option>
                        </select>
                    </div>
                </div>
                <div class="form-group mb-2">
                    <label class="small mb-1 d-block" style="color:#00d4ff;">Description (optional)</label>
                    <input type="text" name="new_season_description" class="form-control" placeholder="Notes for admins / broadcast">
                </div>
                <button type="submit" class="btn btn-sm btn-success">Create season</button>
            </form>
        </div>

        <?php foreach ($seasons as $s): ?>
            <?php
            $sid = (string) ($s['id'] ?? '');
            $enabled = $s['enabled_map_ids'] ?? [];
            if (!is_array($enabled)) {
                $enabled = [];
            }
            $enabled = array_flip(array_map('strval', $enabled));
            $isActive = !isset($s['is_active']) || $s['is_active'] !== false;
            $collapseId = 'season_pool_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $sid);
            $poolMaps = [];
            foreach ($maps as $m) {
                $pmid = (string) ($m['id'] ?? '');
                if ($pmid !== '' && isset($enabled[$pmid])) {
                    $poolMaps[] = $m;
                }
            }
            $nPool = count($poolMaps);
            ?>
            <div class="stats-container mb-3">
                <form method="post">
                    <input type="hidden" name="action" value="save_season">
                    <input type="hidden" name="season_id" value="<?= h($sid) ?>">
                    <div class="form-row align-items-end mb-2">
                        <div class="form-group col-md-5 mb-2 mb-md-0">
                            <label class="small mb-1 d-block" style="color:#00d4ff;">Display name</label>
                            <input type="text" name="season_name" class="form-control" value="<?= h((string) ($s['name'] ?? '')) ?>" required>
                        </div>
                        <div class="form-group col-md-5 mb-2 mb-md-0">
                            <label class="small mb-1 d-block" style="color:#00d4ff;">Season ID</label>
                            <input type="text" class="form-control" value="<?= h($sid) ?>" readonly title="Immutable">
                        </div>
                        <div class="form-group col-md-2 mb-2 mb-md-0">
                            <label class="small mb-1 d-block" style="color:#00d4ff;">Active</label>
                            <div class="form-check pt-1">
                                <input class="form-check-input" type="checkbox" name="season_is_active" id="sea_act_<?= h(preg_replace('/[^a-zA-Z0-9_-]/', '_', $sid)) ?>" <?= $isActive ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="sea_act_<?= h(preg_replace('/[^a-zA-Z0-9_-]/', '_', $sid)) ?>">Show in session form</label>
                            </div>
                        </div>
                    </div>
                    <div class="form-group mb-3">
                        <label class="small mb-1 d-block" style="color:#00d4ff;">Description</label>
                        <input type="text" name="season_description" class="form-control" value="<?= h((string) ($s['description'] ?? '')) ?>" placeholder="Optional">
                    </div>

                    <div class="d-flex justify-content-between align-items-start flex-wrap mb-2 border-top border-secondary pt-3">
                        <div class="mb-2 pr-3">
                            <span class="small text-muted d-block mb-1">Maps in this pool</span>
                            <span class="text-white font-weight-bold"><?= (int) $nPool ?></span>
                            <span class="small text-muted"> selected · need ≥7 enabled active maps on the server for veto sessions</span>
                        </div>
                        <div class="d-flex align-items-center flex-wrap mb-2">
                            <button type="button" class="btn btn-sm btn-outline-info mr-2 mb-1" data-toggle="collapse" data-target="#<?= h($collapseId) ?>" aria-expanded="false" aria-controls="<?= h($collapseId) ?>">
                                Edit map pool
                            </button>
                            <button type="submit" class="btn btn-sm btn-primary mb-1">Save season</button>
                        </div>
                    </div>

                    <?php if ($nPool === 0): ?>
                        <p class="small text-warning mb-3">No maps in this pool yet. Use <strong>Edit map pool</strong> to choose maps.</p>
                    <?php else: ?>
                        <div class="row mv-season-pool-summary mb-3">
                            <?php foreach ($poolMaps as $m): ?>
                                <?php $mid = (string) ($m['id'] ?? ''); ?>
                                <div class="col-lg-4 col-md-6 mb-2">
                                    <div class="d-flex align-items-center px-2 py-2 rounded mv-season-pool-chip h-100">
                                        <?php if (!empty($m['image_url'])): ?>
                                            <img src="<?= h((string) $m['image_url']) ?>" alt="" class="mv-map-preview mv-map-preview-sm mr-2 flex-shrink-0" loading="lazy" width="36" height="36">
                                        <?php endif; ?>
                                        <span class="small mb-0 text-white"><?= h((string) ($m['name'] ?? $mid)) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="collapse season-pool-editor border-top border-secondary pt-3 mt-1" id="<?= h($collapseId) ?>">
                        <p class="small text-muted mb-2">
                            Check maps to include in this pool; uncheck to remove. Close this panel when done, then click <strong>Save season</strong>.
                        </p>
                        <div class="form-group mb-2">
                            <label class="small mb-1 d-block" style="color:#00d4ff;">Search maps</label>
                            <input type="search" class="form-control form-control-sm mv-season-pool-search" placeholder="Filter by name, id, or description…" autocomplete="off" aria-label="Filter maps in this pool" data-mv-datalist="mv-map-search-datalist">
                            <small class="form-text text-muted mb-0">Narrows this list only; hidden rows stay checked/unchecked for save. Name autocomplete after <strong>3</strong> characters.</small>
                        </div>
                        <div class="row">
                            <?php foreach ($maps as $m): ?>
                                <?php
                                $mid = (string) ($m['id'] ?? '');
                                $poolDesc = (string) ($m['description'] ?? '');
                                $mvSearchPool = mv_map_search_blob((string) ($m['name'] ?? ''), $mid, $poolDesc);
                                ?>
                                <div class="col-lg-4 col-md-6 mb-1 mv-season-pool-row" data-mv-search="<?= h($mvSearchPool) ?>">
                                    <label class="d-flex align-items-center mb-0 py-1 small" style="cursor:pointer;">
                                        <input class="form-check-input m-0 mr-2" type="checkbox" name="enabled_map_ids[]" value="<?= h($mid) ?>" <?= isset($enabled[$mid]) ? 'checked' : '' ?>>
                                        <?php if (!empty($m['image_url'])): ?>
                                            <img src="<?= h((string) $m['image_url']) ?>" alt="" class="mv-map-preview mv-map-preview-sm mr-2 flex-shrink-0" loading="lazy" width="32" height="32">
                                        <?php endif; ?>
                                        <span><?= h((string) ($m['name'] ?? $mid)) ?></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</div>

</div>

<style>
/* Map veto admin only — same visual language as fsl_matches.php (gradient + cyan + glass panels) */
body { background: linear-gradient(135deg, #0f0c29, #302b63, #24243e) !important; color: #e0e0e0 !important; }
.map-veto-manager .mv-title { text-align: center; color: #00d4ff; text-shadow: 0 0 15px rgba(0, 212, 255, 0.45); font-size: 1.75rem; margin-bottom: 0.5rem; }
.map-veto-manager h2 { color: #00d4ff; font-size: 1.1rem; margin: 0.5rem 0 0.75rem; }
.map-veto-manager .nav-tabs { border-color: rgba(255, 255, 255, 0.2); }
.map-veto-manager .nav-tabs .nav-link { color: #b0b0b0; border: 1px solid transparent; padding: 0.4rem 0.75rem; font-size: 0.9rem; }
.map-veto-manager .nav-tabs .nav-link:hover { color: #e0e0e0; border-color: rgba(0, 212, 255, 0.3); }
.map-veto-manager .nav-tabs .nav-link.active { color: #00d4ff; background: rgba(0, 0, 0, 0.25); border-color: rgba(0, 212, 255, 0.4); }
.map-veto-manager .form-control, .map-veto-manager .custom-select { background: rgba(0, 0, 0, 0.3); border: 1px solid rgba(255, 255, 255, 0.2); color: #e0e0e0; font-size: 0.875rem; padding: 0.35rem 0.5rem; height: auto; }
.map-veto-manager .form-control:focus { background: rgba(0, 0, 0, 0.4); color: #fff; border-color: #00d4ff; box-shadow: 0 0 0 0.1rem rgba(0, 212, 255, 0.25); }
.map-veto-manager .btn { font-size: 0.85rem; padding: 0.35rem 0.65rem; }
.map-veto-manager .btn-sm { font-size: 0.8rem; padding: 0.25rem 0.5rem; }
.map-veto-manager .stats-container { background: rgba(255, 255, 255, 0.1); border-radius: 10px; padding: 0.75rem 1rem; margin-bottom: 0.75rem; box-shadow: 0 5px 20px rgba(0, 0, 0, 0.4); }
.map-veto-manager .table-mv { width: 100%; border-collapse: collapse; background: rgba(255, 255, 255, 0.08); border-radius: 8px; overflow: hidden; font-size: 0.85rem; margin-bottom: 0.5rem; }
.map-veto-manager .table-mv th, .map-veto-manager .table-mv td { padding: 0.4rem 0.5rem; border-bottom: 1px solid rgba(255, 255, 255, 0.1); vertical-align: middle; }
.map-veto-manager .table-mv th { color: #00d4ff; font-weight: 600; background: rgba(0, 0, 0, 0.2); font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.03em; }
.map-veto-manager .table-mv tr:hover td { background: rgba(0, 212, 255, 0.06); }
.map-veto-manager .table-mv .mv-sess-urls input { font-size: 0.75rem; padding: 0.2rem 0.4rem; }
.map-veto-manager .mv-sess-urls .input-group-sm .mv-copy-url-btn { padding: 0.15rem 0.45rem; line-height: 1.2; }
.map-veto-manager .mv-sess-urls .input-group-sm .mv-copy-url-btn svg { display: block; }
.map-veto-manager .mv-map-preview { width: 48px; height: 48px; object-fit: cover; border-radius: 4px; border: 1px solid rgba(255, 255, 255, 0.15); }
.map-veto-manager .mv-map-preview-sm { width: 32px; height: 32px; }
.map-veto-manager .mv-map-card { background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 10px; overflow: hidden; }
.map-veto-manager .mv-map-card-img-wrap { background: rgba(0, 0, 0, 0.35); }
.map-veto-manager .mv-map-card-img { width: 100%; height: 120px; object-fit: cover; display: block; }
.map-veto-manager .mv-map-card-img--empty { height: 120px; background: rgba(0, 0, 0, 0.25); }
.map-veto-manager .mv-map-card-title { font-size: 0.95rem; line-height: 1.25; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; word-break: break-word; }
.map-veto-manager .mv-map-card-desc { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; min-height: 3.75rem; line-height: 1.35; }
.map-veto-manager .mv-modal-content { background: linear-gradient(145deg, rgba(30, 28, 60, 0.98), rgba(20, 18, 45, 0.98)); color: #e8e8e8; border: 1px solid rgba(0, 212, 255, 0.25); border-radius: 10px; }
.map-veto-manager .mv-modal-content .close { text-shadow: none; opacity: 0.85; }
.map-veto-manager .mv-modal-content textarea.form-control { min-height: 8rem; }
.map-veto-manager #edit_map_image { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 0.8rem; }
.map-veto-manager .mv-season-pool-chip { background: rgba(0, 0, 0, 0.22); border: 1px solid rgba(255, 255, 255, 0.15); }
.map-veto-manager .season-pool-editor { background: rgba(0, 0, 0, 0.15); border-radius: 8px; padding-left: 0.5rem; padding-right: 0.5rem; }
.map-veto-manager .mv-player-ac-menu { position: absolute; left: 0; right: 0; top: 100%; z-index: 1060; margin-top: 2px; max-height: 220px; overflow-y: auto; background: rgba(22, 20, 48, 0.98); border: 1px solid rgba(0, 212, 255, 0.45); border-radius: 6px; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.45); }
.map-veto-manager .mv-player-ac-item { padding: 0.4rem 0.55rem; font-size: 0.875rem; cursor: pointer; color: #e8e8e8; border-bottom: 1px solid rgba(255, 255, 255, 0.06); }
.map-veto-manager .mv-player-ac-item:last-child { border-bottom: 0; }
.map-veto-manager .mv-player-ac-item:hover, .map-veto-manager .mv-player-ac-item.mv-player-ac-item--active { background: rgba(0, 212, 255, 0.18); color: #fff; }
</style>

<script>
window.MV_PLAYER_RANK_HINTS = <?= json_encode($mv_player_rank_hints, JSON_UNESCAPED_UNICODE) ?: '{}' ?>;
window.MV_PLAYER_LABEL_TO_ID = <?= json_encode((object) $mv_player_label_to_id, JSON_UNESCAPED_UNICODE) ?: '{}' ?>;
window.MV_PLAYER_AUTOCOMPLETE_LABELS = <?= json_encode($mv_autocomplete_labels, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
</script>

<script>
(function () {
    /**
     * This script runs before footer's jQuery tag, so jQuery is undefined here.
     * Defer init to DOMContentLoaded so footer scripts (jQuery + Bootstrap) have run first.
     */
    function initMapVetoMapModal() {
        if (typeof jQuery === 'undefined') {
            return;
        }
        function fillMapEditModal(trigger) {
            var btn = jQuery(trigger);
            var id = btn.attr('data-map-id') || '';
            var name = btn.attr('data-map-name') || '';
            var img = btn.attr('data-map-image') || '';
            jQuery('#edit_map_id').val(id);
            jQuery('#edit_map_name').val(name);
            jQuery('#edit_map_image').val(img);
            var ta = document.getElementById('mv-map-desc-bin-' + id);
            jQuery('#edit_map_description').val(ta ? ta.value : '');
            var a = btn.attr('data-map-active');
            var o = btn.attr('data-map-overflow');
            jQuery('#edit_map_active').prop('checked', a === '1');
            jQuery('#edit_map_overflow').prop('checked', o === '1');
            var label = document.getElementById('mapEditModalLabel');
            if (label) {
                label.textContent = name ? ('Edit map — ' + name) : 'Edit map';
            }
            var fi = document.getElementById('edit_map_image_file');
            if (fi) {
                fi.value = '';
            }
        }

        jQuery('#mapCreateModal').on('show.bs.modal', function () {
            var cf = document.getElementById('create_map_image_file');
            if (cf) {
                cf.value = '';
            }
        });

        jQuery(document).off('click.mvMapEdit', '.mv-map-edit-btn').on('click.mvMapEdit', '.mv-map-edit-btn', function (e) {
            e.preventDefault();
            fillMapEditModal(this);
            jQuery('#mapEditModal').modal('show');
        });
    }

    function mvNorm(s) {
        return (s === undefined || s === null) ? '' : String(s).toLowerCase();
    }

    /** Datalist has no native min-length; set the list attribute only after this many characters so autocomplete stays short. */
    var MV_DATALIST_MIN_CHARS = 3;

    function mvToggleSearchDatalist(input) {
        if (!input) {
            return;
        }
        var listId = input.getAttribute('data-mv-datalist');
        if (!listId) {
            return;
        }
        var minAttr = input.getAttribute('data-mv-datalist-min');
        var minChars = minAttr !== null && minAttr !== '' ? parseInt(minAttr, 10) : NaN;
        if (isNaN(minChars)) {
            minChars = MV_DATALIST_MIN_CHARS;
        }
        var v = (input.value || '').trim();
        if (v.length >= minChars) {
            input.setAttribute('list', listId);
        } else {
            input.removeAttribute('list');
        }
    }

    function mvFilterPlayerLabels(query) {
        var all = window.MV_PLAYER_AUTOCOMPLETE_LABELS || [];
        var nq = mvNorm(query).trim();
        if (nq.length < 1) {
            return [];
        }
        var out = [];
        for (var i = 0; i < all.length; i++) {
            if (mvNorm(all[i]).indexOf(nq) !== -1) {
                out.push(all[i]);
            }
        }
        return out;
    }

    function mvFocusNextInForm(fromInput) {
        var form = fromInput.form;
        if (!form) {
            return;
        }
        var els = [];
        for (var i = 0; i < form.elements.length; i++) {
            var el = form.elements[i];
            if (!el || el.disabled || el.type === 'hidden') {
                continue;
            }
            var tag = el.tagName;
            if (tag !== 'INPUT' && tag !== 'SELECT' && tag !== 'TEXTAREA' && tag !== 'BUTTON') {
                continue;
            }
            if (el.tabIndex < 0) {
                continue;
            }
            els.push(el);
        }
        var ix = els.indexOf(fromInput);
        if (ix >= 0 && ix < els.length - 1) {
            els[ix + 1].focus();
        }
    }

    function initMapVetoSessionPlayerAutocomplete() {
        var qa = document.getElementById('mv-player-a-q');
        var qb = document.getElementById('mv-player-b-q');
        if (!qa || !qb) {
            return;
        }

        function wire(input, menu, side) {
            var state = { filtered: [], active: -1 };

            function closeMenu() {
                menu.innerHTML = '';
                menu.hidden = true;
                input.removeAttribute('aria-expanded');
                input.removeAttribute('aria-activedescendant');
                state.active = -1;
            }

            function renderMenu() {
                menu.innerHTML = '';
                if (!state.filtered.length) {
                    menu.hidden = true;
                    input.removeAttribute('aria-expanded');
                    return;
                }
                menu.hidden = false;
                input.setAttribute('aria-expanded', 'true');
                for (var i = 0; i < state.filtered.length; i++) {
                    (function (idx, lbl) {
                        var div = document.createElement('div');
                        div.className = 'mv-player-ac-item' + (idx === state.active ? ' mv-player-ac-item--active' : '');
                        div.textContent = lbl;
                        div.setAttribute('role', 'option');
                        div.id = 'mv-ac-' + side + '-' + idx;
                        div.setAttribute('aria-selected', idx === state.active ? 'true' : 'false');
                        div.addEventListener('mousedown', function (e) {
                            e.preventDefault();
                            input.value = lbl;
                            closeMenu();
                            mvResolveSessionPlayer(side);
                        });
                        menu.appendChild(div);
                    })(i, state.filtered[i]);
                }
                if (state.active >= 0 && state.active < state.filtered.length) {
                    input.setAttribute('aria-activedescendant', 'mv-ac-' + side + '-' + state.active);
                    var hi = menu.querySelector('.mv-player-ac-item.mv-player-ac-item--active');
                    if (hi && hi.scrollIntoView) {
                        hi.scrollIntoView({ block: 'nearest' });
                    }
                } else {
                    input.removeAttribute('aria-activedescendant');
                }
            }

            function applyFilterFromInput() {
                state.filtered = mvFilterPlayerLabels(input.value);
                state.active = state.filtered.length ? 0 : -1;
                renderMenu();
            }

            menu.addEventListener('mousedown', function (e) {
                e.preventDefault();
            });

            input.addEventListener('input', function () {
                applyFilterFromInput();
                mvResolveSessionPlayer(side);
            });

            input.addEventListener('change', function () {
                mvResolveSessionPlayer(side);
            });

            input.addEventListener('blur', function () {
                setTimeout(function () {
                    closeMenu();
                    mvResolveSessionPlayer(side);
                }, 120);
            });

            input.addEventListener('keydown', function (e) {
                var key = e.key;
                if (key === 'ArrowDown') {
                    if (!state.filtered.length) {
                        state.filtered = mvFilterPlayerLabels(input.value);
                    }
                    if (!state.filtered.length) {
                        return;
                    }
                    e.preventDefault();
                    state.active = (state.active + 1) % state.filtered.length;
                    renderMenu();
                    return;
                }
                if (key === 'ArrowUp') {
                    if (!state.filtered.length) {
                        state.filtered = mvFilterPlayerLabels(input.value);
                    }
                    if (!state.filtered.length) {
                        return;
                    }
                    e.preventDefault();
                    var n = state.filtered.length;
                    state.active = ((state.active < 0 ? 0 : state.active) - 1 + n) % n;
                    renderMenu();
                    return;
                }
                if (key === 'Escape') {
                    if (!menu.hidden) {
                        e.preventDefault();
                        closeMenu();
                    }
                    return;
                }
                var pickIdx = state.active;
                if (pickIdx < 0 || pickIdx >= state.filtered.length) {
                    return;
                }
                var picked = state.filtered[pickIdx];
                if ((key === 'Tab' || key === 'Enter') && !menu.hidden && picked) {
                    if (key === 'Tab' && e.shiftKey) {
                        return;
                    }
                    e.preventDefault();
                    input.value = picked;
                    closeMenu();
                    mvResolveSessionPlayer(side);
                    if (key === 'Tab') {
                        mvFocusNextInForm(input);
                    }
                }
            });
        }

        var ma = document.getElementById('mv-player-a-ac-menu');
        var mb = document.getElementById('mv-player-b-ac-menu');
        if (ma) {
            wire(qa, ma, 'a');
        }
        if (mb) {
            wire(qb, mb, 'b');
        }
        mvResolveSessionPlayer('a');
        mvResolveSessionPlayer('b');
        mvUpdateSessionPlayerHints();
    }

    function mvResolveSessionPlayer(side) {
        var map = window.MV_PLAYER_LABEL_TO_ID || {};
        var q = document.getElementById('mv-player-' + side + '-q');
        var hid = document.getElementById('mv-player-' + side + '-id');
        if (!q || !hid) {
            return;
        }
        var v = (q.value || '').trim();
        if (!v) {
            hid.value = '';
            mvUpdateSessionPlayerHints();
            return;
        }
        var id = map[v];
        if (id !== undefined && id !== null && id !== '') {
            hid.value = String(id);
        } else {
            hid.value = '';
        }
        mvUpdateSessionPlayerHints();
    }

    function mvFormatRankHint(info) {
        if (!info) {
            return '';
        }
        var parts = [];
        if (info.rank != null && info.rank !== '') {
            parts.push('Rank ' + info.rank);
        }
        if (info.group != null && info.group !== '') {
            parts.push('Group ' + info.group);
        }
        return parts.join(' · ');
    }

    function mvUpdateSessionPlayerHints() {
        var hints = window.MV_PLAYER_RANK_HINTS || {};
        var a = document.getElementById('mv-player-a-id');
        var b = document.getElementById('mv-player-b-id');
        var ha = document.getElementById('mv-player-a-hint');
        var hb = document.getElementById('mv-player-b-hint');
        if (ha && a) {
            var ia = a.value;
            ha.textContent = ia ? mvFormatRankHint(hints[ia]) : '';
        }
        if (hb && b) {
            var ib = b.value;
            hb.textContent = ib ? mvFormatRankHint(hints[ib]) : '';
        }
    }

    function initMapVetoSessionPlayerHints() {
        initMapVetoSessionPlayerAutocomplete();
    }

    function initMapVetoSessionFormValidate() {
        var f = document.getElementById('mv-form-create-session');
        if (!f) {
            return;
        }
        f.addEventListener('submit', function (e) {
            mvResolveSessionPlayer('a');
            mvResolveSessionPlayer('b');
            var ha = document.getElementById('mv-player-a-id');
            var hb = document.getElementById('mv-player-b-id');
            var va = ha && ha.value && String(ha.value) !== '0';
            var vb = hb && hb.value && String(hb.value) !== '0';
            if (!va || !vb) {
                e.preventDefault();
                alert('Pick each player from the autocomplete suggestions (exact match).');
                return false;
            }
            if (String(ha.value) === String(hb.value)) {
                e.preventDefault();
                alert('Choose two different players.');
                return false;
            }
            return true;
        });
    }

    function initMapVetoUrlCopyButtons() {
        document.querySelectorAll('.mv-copy-url-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.getAttribute('data-copy-for');
                var inp = id ? document.getElementById(id) : null;
                if (!inp || !inp.value) {
                    return;
                }
                var v = inp.value;
                var done = function () {
                    var prev = btn.getAttribute('title') || 'Copy to clipboard';
                    btn.setAttribute('title', 'Copied!');
                    setTimeout(function () {
                        btn.setAttribute('title', prev);
                    }, 1400);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(v).then(done).catch(function () {
                        inp.select();
                        try {
                            document.execCommand('copy');
                        } catch (e2) {}
                        done();
                    });
                } else {
                    inp.removeAttribute('readonly');
                    inp.select();
                    inp.setSelectionRange(0, 99999);
                    try {
                        document.execCommand('copy');
                    } catch (e3) {}
                    inp.setAttribute('readonly', 'readonly');
                    done();
                }
            });
        });
    }

    function initMapVetoSearchFilters() {
        var mapsInput = document.getElementById('mv-maps-tab-search');
        if (mapsInput) {
            mapsInput.addEventListener('input', function () {
                mvToggleSearchDatalist(mapsInput);
                var q = mvNorm(mapsInput.value).trim();
                document.querySelectorAll('#tab-maps .mv-map-grid-item').forEach(function (el) {
                    var blob = mvNorm(el.getAttribute('data-mv-search'));
                    var ok = !q || blob.indexOf(q) !== -1;
                    el.classList.toggle('d-none', !ok);
                });
            });
            mvToggleSearchDatalist(mapsInput);
        }

        document.querySelectorAll('.mv-season-pool-search').forEach(function (input) {
            input.addEventListener('input', function () {
                mvToggleSearchDatalist(input);
                var q = mvNorm(input.value).trim();
                var scope = input.closest('.season-pool-editor');
                if (!scope) {
                    return;
                }
                scope.querySelectorAll('.mv-season-pool-row').forEach(function (row) {
                    var blob = mvNorm(row.getAttribute('data-mv-search'));
                    var ok = !q || blob.indexOf(q) !== -1;
                    row.classList.toggle('d-none', !ok);
                });
            });
            mvToggleSearchDatalist(input);
        });
    }

    function initMapVetoAdminUi() {
        initMapVetoMapModal();
        initMapVetoSessionPlayerHints();
        initMapVetoSessionFormValidate();
        initMapVetoUrlCopyButtons();
        initMapVetoSearchFilters();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMapVetoAdminUi);
    } else {
        initMapVetoAdminUi();
    }
})();
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
