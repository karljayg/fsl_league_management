<?php
/**
 * Broadcast casters and the managed production roster for fsl_schedule.
 * Casters: any Players row linked to a site user (Players.User_ID = users.id).
 * Production: active rows in fsl_production_staff. A null caster or production
 * id is TBD: nobody is assigned yet, and it does not fall back to the roster default.
 */

const FSL_SCHEDULE_CACHE_VERSION = 3;

function fsl_schedule_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function fsl_schedule_staff_schema_ready(PDO $db): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $col = $db->query("SHOW COLUMNS FROM fsl_schedule LIKE 'production_player_id'")->fetch(PDO::FETCH_ASSOC);
    $tbl = $db->query("SHOW TABLES LIKE 'fsl_production_staff'")->fetch(PDO::FETCH_NUM);
    $ready = (bool) $col && (bool) $tbl;
    return $ready;
}

function fsl_schedule_bust_cache(int $season): void
{
    $dir = dirname(__DIR__) . '/cache';
    $files = [
        $dir . '/fsl_schedule_latest.json',
        $dir . '/fsl_schedule_s' . $season . '.json',
    ];
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

function fsl_schedule_edit_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['fsl_schedule_token'])) {
        $_SESSION['fsl_schedule_token'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['fsl_schedule_token'];
}

function fsl_schedule_token_ok(): bool
{
    $posted = (string) ($_POST['token'] ?? '');
    $expected = (string) ($_SESSION['fsl_schedule_token'] ?? '');
    return $expected !== '' && $posted !== '' && hash_equals($expected, $posted);
}

function fsl_schedule_flash(string $type, string $text): void
{
    $_SESSION['fsl_schedule_flash'] = ['type' => $type, 'text' => $text];
}

function fsl_schedule_layout_value($layout): string
{
    return ((string) $layout) === 'list' ? 'list' : 'cards';
}

function fsl_schedule_href(int $season, string $layout, bool $edit): string
{
    $q = 'season=' . $season . '&layout=' . fsl_schedule_layout_value($layout);
    if ($edit) {
        $q .= '&edit=1';
    }
    return 'fsl_schedule.php?' . $q;
}

function fsl_schedule_redirect_edit(int $season, string $layout, int $week = 0): void
{
    $url = fsl_schedule_href($season, $layout, true);
    if ($week > 0) {
        $url .= '#week' . $week;
    }
    header('Location: ' . $url);
    exit;
}

function fsl_schedule_datetime_local(?string $mysql): string
{
    if ($mysql === null || trim($mysql) === '') {
        return '';
    }
    $dt = date_create($mysql);
    if (!$dt) {
        return '';
    }
    return $dt->format('Y-m-d\TH:i');
}

function fsl_player_thumb_web_path(string $playerName): string
{
    $playerName = trim($playerName);
    $fallback = 'images/default-avatar-silhouette.svg';
    if ($playerName === '') {
        return $fallback;
    }
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '', str_replace(' ', '_', $playerName));
    if ($safeName === '') {
        return $fallback;
    }
    $dir = dirname(__DIR__) . '/images/player_thumbnails';
    foreach (['.png', '.jpg', '.jpeg'] as $ext) {
        if (is_file($dir . '/' . $safeName . $ext)) {
            return 'images/player_thumbnails/' . $safeName . $ext;
        }
    }
    static $thumbMap = null;
    if ($thumbMap === null) {
        $thumbMap = [];
        $files = glob($dir . '/*.{png,jpg,jpeg}', GLOB_BRACE) ?: [];
        foreach ($files as $file) {
            $thumbMap[strtolower(pathinfo($file, PATHINFO_FILENAME))] = 'images/player_thumbnails/' . basename($file);
        }
    }
    return $thumbMap[strtolower($safeName)] ?? $fallback;
}

function fsl_schedule_registered_players(PDO $db): array
{
    $stmt = $db->query("
        SELECT p.Player_ID AS player_id, p.Real_Name AS name
        FROM Players p
        INNER JOIN users u ON u.id = p.User_ID
        WHERE p.User_ID IS NOT NULL AND TRIM(p.User_ID) <> ''
        ORDER BY p.Real_Name
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['player_id'] = (int) $row['player_id'];
    }
    unset($row);
    return $rows;
}

function fsl_schedule_production_roster(PDO $db): array
{
    if (!fsl_schedule_staff_schema_ready($db)) {
        return [];
    }
    $stmt = $db->query("
        SELECT ps.player_id, ps.is_default, ps.sort_order, p.Real_Name AS name, p.Intro_Url AS intro
        FROM fsl_production_staff ps
        JOIN Players p ON p.Player_ID = ps.player_id
        WHERE ps.active = 1
        ORDER BY ps.is_default DESC, ps.sort_order, p.Real_Name
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['player_id'] = (int) $row['player_id'];
        $row['is_default'] = (int) $row['is_default'] === 1;
        $row['sort_order'] = (int) $row['sort_order'];
        $row['intro'] = (string) ($row['intro'] ?? '');
    }
    unset($row);
    return $rows;
}

function fsl_schedule_default_production(PDO $db): ?array
{
    if (!fsl_schedule_staff_schema_ready($db)) {
        return null;
    }
    $stmt = $db->query("
        SELECT ps.player_id, ps.is_default, p.Real_Name AS name, p.Intro_Url AS intro
        FROM fsl_production_staff ps
        JOIN Players p ON p.Player_ID = ps.player_id
        WHERE ps.active = 1 AND ps.is_default = 1
        ORDER BY ps.sort_order
        LIMIT 1
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'player_id' => (int) $row['player_id'],
        'name' => (string) $row['name'],
        'intro' => (string) ($row['intro'] ?? ''),
        'is_default' => true,
    ];
}

function fsl_schedule_staff_select_sql(PDO $db): string
{
    if (!fsl_schedule_staff_schema_ready($db)) {
        return '';
    }
    return ",
            s.caster1_player_id,
            s.caster2_player_id,
            s.caster_backup_player_id,
            s.production_player_id,
            c1.Real_Name AS caster1_name,
            c1.Intro_Url AS caster1_intro,
            c2.Real_Name AS caster2_name,
            c2.Intro_Url AS caster2_intro,
            cb.Real_Name AS caster_backup_name,
            cb.Intro_Url AS caster_backup_intro,
            prod.Real_Name AS production_name,
            prod.Intro_Url AS production_intro";
}

function fsl_schedule_staff_join_sql(PDO $db): string
{
    if (!fsl_schedule_staff_schema_ready($db)) {
        return '';
    }
    return "
        LEFT JOIN Players c1 ON s.caster1_player_id = c1.Player_ID
        LEFT JOIN Players c2 ON s.caster2_player_id = c2.Player_ID
        LEFT JOIN Players cb ON s.caster_backup_player_id = cb.Player_ID
        LEFT JOIN Players prod ON s.production_player_id = prod.Player_ID";
}

/**
 * @return array<int, array{role:string,name:string,intro:string,default:bool}>
 */
function fsl_schedule_is_default_caster(string $name): bool
{
    return in_array(strtolower(trim($name)), ['hyperturtle', 'jmpz'], true);
}

function fsl_schedule_broadcast_crew(array $match, ?array $defaultProduction, bool $markDefaults = false): array
{
    $slots = [
        ['role' => 'Caster', 'name' => $match['caster1_name'] ?? '', 'intro' => $match['caster1_intro'] ?? '', 'show_tbd' => true],
        ['role' => 'Caster', 'name' => $match['caster2_name'] ?? '', 'intro' => $match['caster2_intro'] ?? '', 'show_tbd' => true],
        ['role' => 'Backup', 'name' => $match['caster_backup_name'] ?? '', 'intro' => $match['caster_backup_intro'] ?? '', 'show_tbd' => false],
    ];
    $crew = [];
    foreach ($slots as $slot) {
        $name = trim((string) $slot['name']);
        if ($name === '') {
            if ($slot['show_tbd']) {
                $crew[] = ['role' => $slot['role'], 'name' => 'TBD', 'intro' => '', 'default' => false, 'tbd' => true];
            }
            continue;
        }
        $crew[] = [
            'role' => $slot['role'],
            'name' => $name,
            'intro' => (string) $slot['intro'],
            'default' => $markDefaults && $slot['role'] === 'Caster' && fsl_schedule_is_default_caster($name),
            'tbd' => false,
        ];
    }
    $prodName = trim((string) ($match['production_name'] ?? ''));
    if ($prodName === '') {
        $crew[] = ['role' => 'Production', 'name' => 'TBD', 'intro' => '', 'default' => false, 'tbd' => true];
        return $crew;
    }
    $isDefault = $markDefaults
        && $defaultProduction
        && (int) ($match['production_player_id'] ?? 0) === (int) ($defaultProduction['player_id'] ?? 0);
    $crew[] = [
        'role' => 'Production',
        'name' => $prodName,
        'intro' => (string) ($match['production_intro'] ?? ''),
        'default' => $isDefault,
        'tbd' => false,
    ];
    return $crew;
}

function fsl_schedule_staff_chip_html(string $role, string $name, string $intro, bool $isDefault = false, bool $isTbd = false): string
{
    if ($isTbd) {
        return '<div class="staff-chip staff-chip-tbd">'
            . '<span class="staff-portrait staff-tbd-mark" aria-hidden="true">?</span>'
            . '<div class="staff-chip-text"><span class="staff-role">' . fsl_schedule_h($role) . '</span>'
            . '<span class="staff-name">TBD</span></div></div>';
    }
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    $thumb = fsl_player_thumb_web_path($name);
    $playerUrl = 'view_player.php?name=' . rawurlencode($name);
    $img = '<img src="' . fsl_schedule_h($thumb) . '" alt="" class="staff-portrait" width="48" height="48">';
    if (trim($intro) !== '') {
        $portrait = '<button type="button" class="staff-portrait-btn" data-intro-url="' . fsl_schedule_h($intro)
            . '" data-intro-name="' . fsl_schedule_h($name) . '" title="Play intro">' . $img . '</button>';
    } else {
        $portrait = '<a href="' . fsl_schedule_h($playerUrl) . '">' . $img . '</a>';
    }
    $defaultMark = $isDefault ? ' <span class="staff-default">default</span>' : '';
    return '<div class="staff-chip">' . $portrait
        . '<div class="staff-chip-text"><span class="staff-role">' . fsl_schedule_h($role) . $defaultMark . '</span>'
        . '<a class="staff-name" href="' . fsl_schedule_h($playerUrl) . '">' . fsl_schedule_h($name) . '</a></div></div>';
}

function fsl_schedule_crew_html(array $match, ?array $defaultProduction, bool $markDefaults = false): string
{
    $html = '';
    foreach (fsl_schedule_broadcast_crew($match, $defaultProduction, $markDefaults) as $person) {
        $html .= fsl_schedule_staff_chip_html($person['role'], $person['name'], $person['intro'], !empty($person['default']), !empty($person['tbd']));
    }
    return $html;
}

function fsl_schedule_edit_hiddens(string $token, int $season, string $layout, string $action, int $week = 0): string
{
    $html = '<input type="hidden" name="token" value="' . fsl_schedule_h($token) . '">'
        . '<input type="hidden" name="action" value="' . fsl_schedule_h($action) . '">'
        . '<input type="hidden" name="season" value="' . $season . '">'
        . '<input type="hidden" name="layout" value="' . fsl_schedule_h(fsl_schedule_layout_value($layout)) . '">';
    if ($week > 0) {
        $html .= '<input type="hidden" name="week_number" value="' . $week . '">';
    }
    return $html;
}

function fsl_schedule_team_select(string $name, array $teams, $selectedId): string
{
    $selected = (int) $selectedId;
    $html = '<select name="' . fsl_schedule_h($name) . '" class="form-control form-control-sm">';
    $html .= '<option value="">TBD</option>';
    foreach ($teams as $team) {
        $id = (int) $team['Team_ID'];
        $sel = ($id === $selected && $selected > 0) ? ' selected' : '';
        $html .= '<option value="' . $id . '"' . $sel . '>' . fsl_schedule_h($team['Team_Name']) . '</option>';
    }
    return $html . '</select>';
}

function fsl_schedule_player_select(string $name, array $choices, $selectedId, string $blankLabel, array $defaultIds = []): string
{
    $selected = (int) $selectedId;
    $defaultIds = array_map('intval', $defaultIds);
    $html = '<select name="' . fsl_schedule_h($name) . '" class="form-control form-control-sm">';
    $html .= '<option value="">' . fsl_schedule_h($blankLabel) . '</option>';
    foreach ($choices as $id => $label) {
        $id = (int) $id;
        $sel = ($id === $selected && $selected > 0) ? ' selected' : '';
        $text = (string) $label;
        if (in_array($id, $defaultIds, true)) {
            $text .= ' (default)';
        }
        $html .= '<option value="' . $id . '"' . $sel . '>' . fsl_schedule_h($text) . '</option>';
    }
    return $html . '</select>';
}

function fsl_schedule_caster_choices(array $players, array $match): array
{
    $byId = [];
    foreach ($players as $player) {
        $byId[(int) $player['player_id']] = (string) $player['name'];
    }
    $slots = [
        'caster1' => 'caster1',
        'caster2' => 'caster2',
        'caster_backup' => 'caster_backup',
    ];
    foreach ($slots as $prefix) {
        $id = (int) ($match[$prefix . '_player_id'] ?? 0);
        $label = trim((string) ($match[$prefix . '_name'] ?? ''));
        if ($id > 0 && !isset($byId[$id])) {
            $byId[$id] = $label !== '' ? $label : ('Player ' . $id);
        }
    }
    asort($byId, SORT_NATURAL | SORT_FLAG_CASE);
    return $byId;
}

function fsl_schedule_render_broadcast_form(
    array $match,
    array $teams,
    array $players,
    array $roster,
    ?array $defaultProduction,
    string $token,
    string $layout
): void {
    $season = (int) ($match['season'] ?? 0);
    $week = (int) ($match['week_number'] ?? 0);
    $casterChoices = fsl_schedule_caster_choices($players, $match);
    $prodChoices = [];
    foreach ($roster as $member) {
        $prodChoices[(int) $member['player_id']] = (string) $member['name'];
    }
    $currentProd = (int) ($match['production_player_id'] ?? 0);
    if ($currentProd > 0 && !isset($prodChoices[$currentProd])) {
        $prodName = trim((string) ($match['production_name'] ?? ''));
        $prodChoices[$currentProd] = $prodName !== '' ? $prodName : ('Player ' . $currentProd);
    }
    $casterDefaultIds = [];
    foreach ($casterChoices as $casterId => $casterName) {
        if (fsl_schedule_is_default_caster((string) $casterName)) {
            $casterDefaultIds[] = (int) $casterId;
        }
    }
    $productionDefaultIds = $defaultProduction ? [(int) $defaultProduction['player_id']] : [];
    $statuses = [
        'scheduled' => 'Scheduled',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];
    $status = (string) ($match['status'] ?? 'scheduled');
    if (!isset($statuses[$status])) {
        $status = 'scheduled';
    }
    $prefix = 'broadcasts[' . (int) $match['schedule_id'] . ']';
    echo '<fieldset class="broadcast-edit-form">';
    echo '<legend class="sr-only">Week ' . $week . '</legend>';
    echo '<div class="form-row">';
    echo '<div class="form-group col-md-4"><label>Broadcast time</label>';
    echo '<input type="datetime-local" name="' . $prefix . '[match_date]" class="form-control form-control-sm" value="'
        . fsl_schedule_h(fsl_schedule_datetime_local($match['match_date'] ?? '')) . '"></div>';
    echo '<div class="form-group col-md-3"><label>Team 1</label>' . fsl_schedule_team_select($prefix . '[team1_id]', $teams, $match['team1_id'] ?? 0) . '</div>';
    echo '<div class="form-group col-md-3"><label>Team 2</label>' . fsl_schedule_team_select($prefix . '[team2_id]', $teams, $match['team2_id'] ?? 0) . '</div>';
    echo '<div class="form-group col-md-2"><label>Status</label><select name="' . $prefix . '[status]" class="form-control form-control-sm">';
    foreach ($statuses as $value => $label) {
        $sel = $value === $status ? ' selected' : '';
        echo '<option value="' . fsl_schedule_h($value) . '"' . $sel . '>' . fsl_schedule_h($label) . '</option>';
    }
    echo '</select></div></div>';
    echo '<div class="form-row">';
    echo '<div class="form-group col-md-3"><label>Caster</label>' . fsl_schedule_player_select($prefix . '[caster1_player_id]', $casterChoices, $match['caster1_player_id'] ?? 0, 'TBD', $casterDefaultIds) . '</div>';
    echo '<div class="form-group col-md-3"><label>Caster</label>' . fsl_schedule_player_select($prefix . '[caster2_player_id]', $casterChoices, $match['caster2_player_id'] ?? 0, 'TBD', $casterDefaultIds) . '</div>';
    echo '<div class="form-group col-md-3"><label>Backup caster</label>' . fsl_schedule_player_select($prefix . '[caster_backup_player_id]', $casterChoices, $match['caster_backup_player_id'] ?? 0, 'TBD') . '</div>';
    echo '<div class="form-group col-md-3"><label>Production</label>' . fsl_schedule_player_select($prefix . '[production_player_id]', $prodChoices, $match['production_player_id'] ?? 0, 'TBD', $productionDefaultIds) . '</div>';
    echo '</div>';
    echo '<div class="form-group mb-0"><label>Notes</label>';
    echo '<input type="text" name="' . $prefix . '[notes]" class="form-control form-control-sm" maxlength="4000" value="'
        . fsl_schedule_h($match['notes'] ?? '') . '"></div>';
    echo '</fieldset>';
}

function fsl_schedule_render_production_manager(
    array $roster,
    array $players,
    string $token,
    int $season,
    string $layout
): void {
    $onRoster = [];
    foreach ($roster as $member) {
        $onRoster[(int) $member['player_id']] = true;
    }
    echo '<div class="production-roster team-match-card">';
    echo '<h3>Production staff</h3>';
    echo '<p class="production-roster-note">Pick someone per broadcast, or leave TBD when they are not available. Casters are any registered player. This list is only who can run production.</p>';
    if ($roster === []) {
        echo '<p class="production-roster-note">No production staff yet.</p>';
    } else {
        echo '<div class="production-roster-list">';
        foreach ($roster as $member) {
            $playerId = (int) $member['player_id'];
            echo '<div class="production-roster-row">';
            echo fsl_schedule_staff_chip_html('Production', (string) $member['name'], (string) ($member['intro'] ?? ''), !empty($member['is_default']));
            echo '<div class="production-roster-actions">';
            if (empty($member['is_default'])) {
                echo '<form method="post" action="' . fsl_schedule_h(fsl_schedule_href($season, $layout, true)) . '" class="d-inline">';
                echo fsl_schedule_edit_hiddens($token, $season, $layout, 'set_default_production');
                echo '<input type="hidden" name="player_id" value="' . $playerId . '">';
                echo '<button type="submit" class="btn btn-sm btn-outline-info">Set default</button></form>';
            }
            echo '<form method="post" action="' . fsl_schedule_h(fsl_schedule_href($season, $layout, true)) . '" class="d-inline">';
            echo fsl_schedule_edit_hiddens($token, $season, $layout, 'remove_production');
            echo '<input type="hidden" name="player_id" value="' . $playerId . '">';
            echo '<button type="submit" class="btn btn-sm btn-outline-light">Remove</button></form>';
            echo '</div></div>';
        }
        echo '</div>';
    }
    echo '<form method="post" action="' . fsl_schedule_h(fsl_schedule_href($season, $layout, true)) . '" class="form-inline production-add-form">';
    echo fsl_schedule_edit_hiddens($token, $season, $layout, 'add_production');
    echo '<label class="sr-only" for="addProductionPlayer">Add production</label>';
    echo '<select id="addProductionPlayer" name="player_id" class="form-control form-control-sm mr-2">';
    echo '<option value="">Add a registered player</option>';
    $available = 0;
    foreach ($players as $player) {
        $playerId = (int) $player['player_id'];
        if (isset($onRoster[$playerId])) {
            continue;
        }
        $available++;
        echo '<option value="' . $playerId . '">' . fsl_schedule_h($player['name']) . '</option>';
    }
    echo '</select>';
    echo '<button type="submit" class="btn btn-sm btn-info"' . ($available === 0 ? ' disabled' : '') . '>Add</button>';
    echo '</form></div>';
}

function fsl_schedule_registered_player_id(PDO $db, $raw): ?int
{
    $id = (int) $raw;
    if ($id <= 0) {
        return null;
    }
    $stmt = $db->prepare("
        SELECT p.Player_ID
        FROM Players p
        INNER JOIN users u ON u.id = p.User_ID
        WHERE p.Player_ID = ? AND p.User_ID IS NOT NULL AND TRIM(p.User_ID) <> ''
    ");
    $stmt->execute([$id]);
    $found = $stmt->fetchColumn();
    return $found ? (int) $found : null;
}

function fsl_schedule_team_id(PDO $db, $raw): ?int
{
    if ($raw === null || $raw === '') {
        return null;
    }
    $id = (int) $raw;
    if ($id <= 0) {
        return null;
    }
    $stmt = $db->prepare('SELECT Team_ID FROM Teams WHERE Team_ID = ?');
    $stmt->execute([$id]);
    $found = $stmt->fetchColumn();
    return $found ? (int) $found : null;
}

function fsl_schedule_handle_post(PDO $db): void
{
    $layout = fsl_schedule_layout_value($_POST['layout'] ?? 'cards');
    $season = (int) ($_POST['season'] ?? 0);
    $week = (int) ($_POST['week_number'] ?? 0);
    if (!fsl_schedule_token_ok()) {
        fsl_schedule_flash('danger', 'That form expired. Reload the page and try again.');
        fsl_schedule_redirect_edit($season, $layout, $week);
    }
    if (!fsl_schedule_staff_schema_ready($db)) {
        fsl_schedule_flash('danger', 'Run the broadcast staff section in schema_changes.sql before saving casters or production.');
        fsl_schedule_redirect_edit($season, $layout, $week);
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_all_broadcasts') {
            fsl_schedule_save_all_broadcasts($db);
        } elseif ($action === 'add_production') {
            fsl_schedule_add_production($db);
        } elseif ($action === 'remove_production') {
            fsl_schedule_remove_production($db);
        } elseif ($action === 'set_default_production') {
            fsl_schedule_set_default_production($db);
        } else {
            fsl_schedule_flash('danger', 'Unknown schedule action.');
            fsl_schedule_redirect_edit($season, $layout, $week);
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('fsl schedule staff: ' . $e->getMessage());
        fsl_schedule_flash('danger', 'Could not save that change.');
        fsl_schedule_redirect_edit($season, $layout, $week);
    }
}

function fsl_schedule_save_all_broadcasts(PDO $db): void
{
    $layout = fsl_schedule_layout_value($_POST['layout'] ?? 'cards');
    $season = (int) ($_POST['season'] ?? 0);
    $posted = $_POST['broadcasts'] ?? null;
    if (!is_array($posted) || $posted === []) {
        fsl_schedule_flash('danger', 'Nothing to save.');
        fsl_schedule_redirect_edit($season, $layout);
    }
    $db->beginTransaction();
    foreach ($posted as $scheduleId => $fields) {
        if (!is_array($fields)) {
            $db->rollBack();
            fsl_schedule_flash('danger', 'Could not read the schedule form.');
            fsl_schedule_redirect_edit($season, $layout);
        }
        $error = fsl_schedule_apply_broadcast($db, (int) $scheduleId, $fields, $season);
        if ($error !== null) {
            $db->rollBack();
            fsl_schedule_flash('danger', $error);
            fsl_schedule_redirect_edit($season, $layout);
        }
    }
    $db->commit();
    fsl_schedule_bust_cache($season);
    fsl_schedule_flash('success', 'Schedule saved.');
    header('Location: ' . fsl_schedule_href($season, $layout, false));
    exit;
}

/**
 * @param array<string, mixed> $fields
 */
function fsl_schedule_apply_broadcast(PDO $db, int $scheduleId, array $fields, int $expectedSeason): ?string
{
    $stmt = $db->prepare('SELECT schedule_id, season, week_number, production_player_id, caster1_player_id, caster2_player_id, caster_backup_player_id FROM fsl_schedule WHERE schedule_id = ?');
    $stmt->execute([$scheduleId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int) $row['season'] !== $expectedSeason) {
        return 'That broadcast was not found.';
    }
    $week = (int) $row['week_number'];
    $existingProduction = (int) ($row['production_player_id'] ?? 0);
    $existingCasters = array_filter([
        (int) ($row['caster1_player_id'] ?? 0),
        (int) ($row['caster2_player_id'] ?? 0),
        (int) ($row['caster_backup_player_id'] ?? 0),
    ]);

    $matchDateRaw = trim((string) ($fields['match_date'] ?? ''));
    $matchDateSql = null;
    if ($matchDateRaw !== '') {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $matchDateRaw);
        if (!$dt) {
            return 'Week ' . $week . ': enter a valid broadcast date and time.';
        }
        $matchDateSql = $dt->format('Y-m-d H:i:s');
    }

    $team1 = fsl_schedule_team_id($db, $fields['team1_id'] ?? '');
    $team2 = fsl_schedule_team_id($db, $fields['team2_id'] ?? '');
    $postedTeam1 = trim((string) ($fields['team1_id'] ?? ''));
    $postedTeam2 = trim((string) ($fields['team2_id'] ?? ''));
    if (($postedTeam1 !== '' && $team1 === null) || ($postedTeam2 !== '' && $team2 === null)) {
        return 'Week ' . $week . ': pick teams from the list.';
    }
    if ($team1 !== null && $team2 !== null && $team1 === $team2) {
        return 'Week ' . $week . ': the two teams have to be different.';
    }

    $statuses = ['scheduled', 'in_progress', 'completed', 'cancelled'];
    $status = (string) ($fields['status'] ?? 'scheduled');
    if (!in_array($status, $statuses, true)) {
        $status = 'scheduled';
    }
    $notes = trim((string) ($fields['notes'] ?? ''));
    if (strlen($notes) > 4000) {
        $notes = substr($notes, 0, 4000);
    }

    $casterSlots = [
        'caster1_player_id' => $fields['caster1_player_id'] ?? '',
        'caster2_player_id' => $fields['caster2_player_id'] ?? '',
        'caster_backup_player_id' => $fields['caster_backup_player_id'] ?? '',
    ];
    $casterIds = [];
    foreach ($casterSlots as $field => $raw) {
        if (trim((string) $raw) === '') {
            $casterIds[$field] = null;
            continue;
        }
        $playerId = fsl_schedule_registered_player_id($db, $raw);
        $candidate = (int) $raw;
        if ($playerId === null && $candidate > 0 && in_array($candidate, $existingCasters, true)) {
            $playerId = $candidate;
        }
        if ($playerId === null) {
            return 'Week ' . $week . ': casters have to be registered players.';
        }
        $casterIds[$field] = $playerId;
    }
    $picked = array_values(array_filter($casterIds));
    if (count($picked) !== count(array_unique($picked))) {
        return 'Week ' . $week . ': use a different person for each caster slot.';
    }

    $productionRaw = trim((string) ($fields['production_player_id'] ?? ''));
    $productionId = null;
    if ($productionRaw !== '') {
        $candidate = (int) $productionRaw;
        if ($candidate <= 0) {
            return 'Week ' . $week . ': pick production from the roster.';
        }
        $allowed = $db->prepare('SELECT player_id FROM fsl_production_staff WHERE player_id = ? AND active = 1');
        $allowed->execute([$candidate]);
        $onRoster = (bool) $allowed->fetchColumn();
        if (!$onRoster && $candidate !== $existingProduction) {
            return 'Week ' . $week . ': production has to be someone on the production roster.';
        }
        if (!$onRoster && $candidate === $existingProduction) {
            $productionId = $existingProduction;
        } else {
            $productionId = $candidate;
        }
    }

    $update = $db->prepare("
        UPDATE fsl_schedule
        SET match_date = ?, team1_id = ?, team2_id = ?, status = ?, notes = ?,
            caster1_player_id = ?, caster2_player_id = ?, caster_backup_player_id = ?, production_player_id = ?
        WHERE schedule_id = ?
    ");
    $update->execute([
        $matchDateSql,
        $team1,
        $team2,
        $status,
        $notes,
        $casterIds['caster1_player_id'],
        $casterIds['caster2_player_id'],
        $casterIds['caster_backup_player_id'],
        $productionId,
        $scheduleId,
    ]);
    return null;
}

function fsl_schedule_add_production(PDO $db): void
{
    $layout = fsl_schedule_layout_value($_POST['layout'] ?? 'cards');
    $season = (int) ($_POST['season'] ?? 0);
    $playerId = fsl_schedule_registered_player_id($db, $_POST['player_id'] ?? '');
    if ($playerId === null) {
        fsl_schedule_flash('danger', 'Pick a registered player to add.');
        fsl_schedule_redirect_edit($season, $layout);
    }
    $existing = $db->prepare('SELECT active FROM fsl_production_staff WHERE player_id = ?');
    $existing->execute([$playerId]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if ($row && (int) $row['active'] === 1) {
        fsl_schedule_flash('danger', 'That player is already on the production roster.');
        fsl_schedule_redirect_edit($season, $layout);
    }
    $maxSort = (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) FROM fsl_production_staff')->fetchColumn();
    $hasDefault = (int) $db->query('SELECT COUNT(*) FROM fsl_production_staff WHERE active = 1 AND is_default = 1')->fetchColumn();
    $makeDefault = $hasDefault > 0 ? 0 : 1;
    $insert = $db->prepare('
        INSERT INTO fsl_production_staff (player_id, is_default, sort_order, active)
        VALUES (?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE active = 1
    ');
    $insert->execute([$playerId, $makeDefault, $maxSort + 1]);
    if ($makeDefault) {
        $db->prepare('UPDATE fsl_production_staff SET is_default = 0 WHERE player_id <> ?')->execute([$playerId]);
        $db->prepare('UPDATE fsl_production_staff SET is_default = 1 WHERE player_id = ?')->execute([$playerId]);
    }
    fsl_schedule_bust_cache($season);
    fsl_schedule_flash('success', 'Production roster updated.');
    fsl_schedule_redirect_edit($season, $layout);
}

function fsl_schedule_remove_production(PDO $db): void
{
    $layout = fsl_schedule_layout_value($_POST['layout'] ?? 'cards');
    $season = (int) ($_POST['season'] ?? 0);
    $playerId = (int) ($_POST['player_id'] ?? 0);
    if ($playerId <= 0) {
        fsl_schedule_flash('danger', 'Pick someone to remove.');
        fsl_schedule_redirect_edit($season, $layout);
    }
    $current = $db->prepare('SELECT is_default FROM fsl_production_staff WHERE player_id = ? AND active = 1');
    $current->execute([$playerId]);
    $row = $current->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        fsl_schedule_flash('danger', 'That player is not on the production roster.');
        fsl_schedule_redirect_edit($season, $layout);
    }
    $db->prepare('UPDATE fsl_production_staff SET active = 0, is_default = 0 WHERE player_id = ?')->execute([$playerId]);
    if ((int) $row['is_default'] === 1) {
        $db->exec('UPDATE fsl_production_staff SET is_default = 1 WHERE active = 1 ORDER BY sort_order, production_staff_id LIMIT 1');
    }
    fsl_schedule_bust_cache($season);
    fsl_schedule_flash('success', 'Removed from the production roster.');
    fsl_schedule_redirect_edit($season, $layout);
}

function fsl_schedule_set_default_production(PDO $db): void
{
    $layout = fsl_schedule_layout_value($_POST['layout'] ?? 'cards');
    $season = (int) ($_POST['season'] ?? 0);
    $playerId = (int) ($_POST['player_id'] ?? 0);
    $current = $db->prepare('SELECT player_id FROM fsl_production_staff WHERE player_id = ? AND active = 1');
    $current->execute([$playerId]);
    if (!$current->fetchColumn()) {
        fsl_schedule_flash('danger', 'That player is not on the production roster.');
        fsl_schedule_redirect_edit($season, $layout);
    }
    $db->beginTransaction();
    $db->exec('UPDATE fsl_production_staff SET is_default = 0');
    $db->prepare('UPDATE fsl_production_staff SET is_default = 1, active = 1 WHERE player_id = ?')->execute([$playerId]);
    $db->commit();
    fsl_schedule_bust_cache($season);
    fsl_schedule_flash('success', 'Default production updated.');
    fsl_schedule_redirect_edit($season, $layout);
}
