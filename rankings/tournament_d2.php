<?php
/**
 * Build a D2 diagram from tournament_build_bracket() output and render SVG via the d2 CLI.
 * Layout: climb from lowest group (bottom) → Code S champ (top).
 * Only Code B / Code A / Code S (overall) champs are labeled — no per-group champ nodes.
 */

/**
 * @return string Absolute path to d2 binary, or empty string if missing
 */
function tournament_d2_binary(): string
{
    static $bin = null;
    if ($bin !== null) {
        return $bin;
    }
    $candidates = [
        '/opt/homebrew/bin/d2',
        '/usr/local/bin/d2',
        '/usr/bin/d2',
    ];
    foreach ($candidates as $path) {
        if (is_executable($path)) {
            $bin = $path;
            return $bin;
        }
    }
    $which = trim((string) shell_exec('command -v d2 2>/dev/null'));
    $bin = ($which !== '' && is_executable($which)) ? $which : '';
    return $bin;
}

function tournament_d2_escape_label(string $text): string
{
    // D2 0.7 leaves "\n" as literal text in SVG — use a spaced separator instead.
    $text = str_replace(["\r\n", "\n", "\r", '\\n'], ' · ', $text);
    return str_replace(['\\', '"'], ['\\\\', '\\"'], $text);
}

function tournament_d2_node_id(string $raw): string
{
    $id = preg_replace('/[^a-zA-Z0-9_]/', '_', $raw);
    if ($id === '' || preg_match('/^[0-9]/', $id)) {
        $id = 'n_' . $id;
    }
    return $id;
}

/**
 * @param array<string,mixed> $bracket
 * @return array<string,int> name => overall rank
 */
function tournament_d2_rank_map(array $bracket): array
{
    $map = [];
    $groups = is_array($bracket['groups'] ?? null) ? $bracket['groups'] : [];
    foreach ($groups as $gb) {
        if (!is_array($gb)) {
            continue;
        }
        foreach (['players', 'local_players', 'fed_in'] as $key) {
            $list = is_array($gb[$key] ?? null) ? $gb[$key] : [];
            foreach ($list as $p) {
                if (!is_array($p) || !empty($p['is_placeholder'])) {
                    continue;
                }
                $n = trim((string) ($p['name'] ?? ''));
                if ($n === '' || tournament_is_feed_placeholder($n)) {
                    continue;
                }
                if ($p['rank'] === null || $p['rank'] === '') {
                    continue;
                }
                $rank = (int) $p['rank'];
                if ($rank > 0) {
                    $map[$n] = $rank;
                }
            }
        }
    }
    return $map;
}

/**
 * @param array<string,int> $ranks
 */
function tournament_d2_player_label(?string $name, array $ranks): string
{
    if ($name === null || $name === '') {
        return 'TBD';
    }
    if (tournament_is_feed_placeholder($name)) {
        return $name;
    }
    $rank = $ranks[$name] ?? null;
    if ($rank === null || $rank < 1) {
        return $name;
    }
    return '#' . $rank . ' ' . $name;
}

/**
 * @param array<string,mixed> $match
 * @param array<string,int> $ranks
 */
function tournament_d2_match_label(array $match, array $ranks = []): string
{
    $p1 = $match['p1'] ?? null;
    $p2 = $match['p2'] ?? null;
    $status = (string) ($match['status'] ?? '');
    $s1 = $match['score1'] ?? null;
    $s2 = $match['score2'] ?? null;
    $winner = $match['winner'] ?? null;

    $left = tournament_d2_player_label(
        ($p1 !== null && $p1 !== '') ? (string) $p1 : null,
        $ranks
    );
    $right = tournament_d2_player_label(
        ($p2 !== null && $p2 !== '') ? (string) $p2 : null,
        $ranks
    );

    if (!empty($match['is_bye']) || $status === 'bye') {
        $who = $winner ?: ($p1 ?: $p2 ?: null);
        return tournament_d2_player_label($who !== null && $who !== '' ? (string) $who : null, $ranks) . ' · BYE';
    }

    $line = $left . ' vs ' . $right;
    if ($s1 !== null && $s1 !== '' && $s2 !== null && $s2 !== '') {
        $line .= ' · ' . $s1 . '–' . $s2;
    } elseif ($status === 'done' && $winner) {
        $line .= ' · W: ' . tournament_d2_player_label((string) $winner, $ranks);
    }
    return $line;
}

function tournament_d2_node_style(string $kind): string
{
    if ($kind === 'done') {
        return " {\n    style.fill: \"#1a3d32\"\n    style.stroke: \"#00d4aa\"\n  }";
    }
    if ($kind === 'live') {
        return " {\n    style.fill: \"#2a2140\"\n    style.stroke: \"#a29bfe\"\n  }";
    }
    if ($kind === 'feed') {
        return " {\n    style.fill: \"#102a24\"\n    style.stroke: \"#00d4aa\"\n    style.stroke-dash: 3\n    style.border-radius: 20\n  }";
    }
    if ($kind === 'codeB' || $kind === 'codeA' || $kind === 'codeS') {
        return " {\n    style.fill: \"#3a2a08\"\n    style.stroke: \"#ffd700\"\n    style.bold: true\n    style.font-size: 18\n  }";
    }
    return " {\n    style.fill: \"#151a24\"\n    style.stroke: \"#556\"\n    style.stroke-dash: 2\n  }";
}

/**
 * True when a group has nothing worth drawing (no real players / matches).
 * Solo auto-climb hops (feeds_up) are skipped — the player appears in the
 * higher group where they actually play.
 *
 * @param array<string,mixed> $gb
 */
function tournament_d2_group_is_empty(array $gb): bool
{
    // Climber only passing through — don't draw a lone "Name" box here.
    if (!empty($gb['feeds_up'])) {
        return true;
    }

    $rounds = is_array($gb['rounds'] ?? null) ? $gb['rounds'] : [];
    if ($rounds !== []) {
        return false;
    }
    $players = is_array($gb['players'] ?? null) ? $gb['players'] : [];
    foreach ($players as $p) {
        if (!is_array($p)) {
            continue;
        }
        if (!empty($p['is_placeholder'])) {
            continue;
        }
        $n = trim((string) ($p['name'] ?? ''));
        if ($n === '' || tournament_is_feed_placeholder($n)) {
            continue;
        }
        return false;
    }
    $champ = $gb['champion'] ?? null;
    if ($champ !== null && $champ !== '' && !tournament_is_feed_placeholder((string) $champ)) {
        return false;
    }
    return true;
}

/**
 * Convert full bracket payload to D2 source.
 * Climb: lowest group at bottom → Code S (overall) at top.
 *
 * @param array<string,mixed> $bracket
 */
function tournament_bracket_to_d2(array $bracket, string $title = 'FSL Individual Championships'): string
{
    $groups = $bracket['groups'] ?? [];
    $maxGroup = (int) ($bracket['max_group'] ?? 0);
    if ($maxGroup < 1 && is_array($groups) && $groups !== []) {
        $maxGroup = (int) max(array_map('intval', array_keys($groups)));
    }

    $in = (int) ($bracket['participant_count'] ?? 0);
    $codeChamps = is_array($bracket['code_champs'] ?? null) ? $bracket['code_champs'] : [];
    $overallChamp = $bracket['champion'] ?? null;
    $ranks = tournament_d2_rank_map($bracket);

    // Top group number per code (lowest G# = highest seeds in that code) → where that code champ is crowned.
    $codeExitGroup = [];
    for ($g = 1; $g <= $maxGroup; $g++) {
        $gb = is_array($groups[$g] ?? null) ? $groups[$g] : [];
        $code = strtoupper(trim((string) ($gb['code'] ?? '')));
        if ($code === '') {
            continue;
        }
        if (!isset($codeExitGroup[$code]) || $g < $codeExitGroup[$code]) {
            $codeExitGroup[$code] = $g;
        }
    }

    $sub = $in . ' players · G' . $maxGroup . ' → G1 · Code B / A / S champs';
    $lines = [];
    $lines[] = 'vars: {';
    $lines[] = '  d2-config: {';
    $lines[] = '    layout-engine: dagre';
    $lines[] = '    theme-id: 200';
    $lines[] = '  }';
    $lines[] = '}';
    $lines[] = '';
    $lines[] = 'direction: up';
    $lines[] = '';
    $lines[] = 'banner: "' . tournament_d2_escape_label($title . "\\n" . $sub) . '" {';
    $lines[] = '  shape: rectangle';
    $lines[] = '  style: {';
    $lines[] = '    fill: "#120a24"';
    $lines[] = '    stroke: "#6c5ce7"';
    $lines[] = '    font-size: 18';
    $lines[] = '    bold: true';
    $lines[] = '  }';
    $lines[] = '}';
    $lines[] = '';

    /** @var array<int, string> $exitIds absolute node id leaving each group */
    $exitIds = [];
    /** @var array<int, string> $entryIds absolute node id that receives the lower-group feed */
    $entryIds = [];

    for ($g = $maxGroup; $g >= 1; $g--) {
        $gb = is_array($groups[$g] ?? null) ? $groups[$g] : [
            'group' => $g,
            'players' => [],
            'rounds' => [],
            'champion' => null,
            'code' => '',
            'waiting_for_feed' => false,
            'fed_in' => [],
        ];

        $rounds = is_array($gb['rounds'] ?? null) ? $gb['rounds'] : [];
        $players = is_array($gb['players'] ?? null) ? $gb['players'] : [];
        $code = strtoupper(trim((string) ($gb['code'] ?? '')));
        $champG = $gb['champion'] ?? null;
        $fedIn = is_array($gb['fed_in'] ?? null) ? $gb['fed_in'] : [];

        // Skip empty hop groups (e.g. G12 with nobody in) — climb goes to the next real group.
        if (tournament_d2_group_is_empty($gb)) {
            continue;
        }

        $cid = tournament_d2_node_id('G' . $g);

        $head = 'G' . $g;
        if ($code !== '') {
            $head .= ' · Code ' . $code;
        }

        $lines[] = $cid . ': {';
        $lines[] = '  label: "' . tournament_d2_escape_label($head) . '"';
        $lines[] = '  direction: right';
        $lines[] = '  style: {';
        $lines[] = '    border-radius: 10';
        $lines[] = '    stroke-width: 1';
        $lines[] = '  }';

        $feederNames = [];
        foreach ($fedIn as $fp) {
            $n = trim((string) ($fp['name'] ?? ''));
            if ($n !== '') {
                $feederNames[$n] = true;
            }
        }

        /** @var string|null $pathExit last real match / field node before optional code-champ */
        $pathExit = null;
        /** @var string|null $feedEntryLocal local node id that receives ↑ from G{g+1} */
        $feedEntryLocal = null;
        /** @var array<int, list<?string>> $roundNodeIds null = skipped bye */
        $roundNodeIds = [];

        if ($rounds === []) {
            $pathId = tournament_d2_node_id('g' . $g . '_path');
            if ($champG) {
                $label = tournament_d2_player_label((string) $champG, $ranks);
                $kind = 'done';
            } elseif ($players !== []) {
                $names = [];
                foreach ($players as $p) {
                    if (!is_array($p) || !empty($p['is_placeholder'])) {
                        continue;
                    }
                    $n = trim((string) ($p['name'] ?? ''));
                    if ($n === '' || tournament_is_feed_placeholder($n)) {
                        continue;
                    }
                    $names[] = tournament_d2_player_label($n, $ranks);
                }
                $label = $names !== [] ? implode(', ', $names) : 'TBD';
                $kind = 'tbd';
            } else {
                $label = 'TBD';
                $kind = 'tbd';
            }
            $lines[] = '  ' . $pathId . ': "' . tournament_d2_escape_label($label) . '"'
                . tournament_d2_node_style($kind);
            $pathExit = $pathId;
            if ($g < $maxGroup) {
                $feedEntryLocal = $pathId;
            }
        } else {
            foreach ($rounds as $ri => $matches) {
                $roundNodeIds[$ri] = [];
                if (!is_array($matches)) {
                    continue;
                }
                foreach ($matches as $mi => $match) {
                    if (!is_array($match)) {
                        $roundNodeIds[$ri][] = null;
                        continue;
                    }
                    // Structural byes are not real matches — player already sits in the next round.
                    if (!empty($match['is_bye']) || (string) ($match['status'] ?? '') === 'bye') {
                        $roundNodeIds[$ri][] = null;
                        continue;
                    }
                    $mid = (string) ($match['id'] ?? ('g' . $g . '-r' . ($ri + 1) . '-m' . $mi));
                    $nid = tournament_d2_node_id($mid);
                    $st = (string) ($match['status'] ?? '');
                    $kind = 'tbd';
                    if ($st === 'done') {
                        $kind = 'done';
                    } elseif ($st === 'ready' || $st === 'live') {
                        $kind = 'live';
                    }
                    $lines[] = '  ' . $nid . ': "' . tournament_d2_escape_label(tournament_d2_match_label($match, $ranks)) . '"'
                        . tournament_d2_node_style($kind);
                    $roundNodeIds[$ri][] = $nid;
                }
            }

            if ($roundNodeIds !== []) {
                $lastRound = $roundNodeIds[count($roundNodeIds) - 1];
                foreach ($lastRound as $nid) {
                    if ($nid !== null) {
                        $pathExit = $nid;
                        break;
                    }
                }

                for ($ri = 0; $ri < count($roundNodeIds) - 1; $ri++) {
                    $cur = $roundNodeIds[$ri];
                    $nxt = $roundNodeIds[$ri + 1];
                    for ($mi = 0; $mi < count($cur); $mi++) {
                        if ($cur[$mi] === null) {
                            continue;
                        }
                        $targetIdx = (int) floor($mi / 2);
                        if (!isset($nxt[$targetIdx]) || $nxt[$targetIdx] === null) {
                            continue;
                        }
                        $lines[] = '  ' . $cur[$mi] . ' -> ' . $nxt[$targetIdx];
                    }
                }
            }

            // Entry for lower-group winner: the R1 match that includes the feeder / placeholder.
            if ($g < $maxGroup && isset($roundNodeIds[0])) {
                foreach ($rounds[0] as $i => $match) {
                    if (!is_array($match) || !isset($roundNodeIds[0][$i]) || $roundNodeIds[0][$i] === null) {
                        continue;
                    }
                    $p1 = trim((string) ($match['p1'] ?? ''));
                    $p2 = trim((string) ($match['p2'] ?? ''));
                    if ($feederNames !== []) {
                        if (($p1 !== '' && isset($feederNames[$p1])) || ($p2 !== '' && isset($feederNames[$p2]))) {
                            $feedEntryLocal = $roundNodeIds[0][$i];
                            break;
                        }
                    }
                }
                // Fallback: first real (non-bye) R1 match
                if ($feedEntryLocal === null) {
                    foreach ($roundNodeIds[0] as $nid) {
                        if ($nid !== null) {
                            $feedEntryLocal = $nid;
                            break;
                        }
                    }
                }
            }
        }

        // Code champ only at the top group of each code (B / A / S)
        $groupExitLocal = $pathExit;
        $isCodeExit = ($code !== '' && isset($codeExitGroup[$code]) && (int) $codeExitGroup[$code] === $g);
        if ($isCodeExit && $pathExit !== null) {
            $cc = is_array($codeChamps[$code] ?? null) ? $codeChamps[$code] : [];
            $ccName = !empty($cc['ready']) && !empty($cc['name'])
                ? tournament_d2_player_label((string) $cc['name'], $ranks)
                : ($champG ? tournament_d2_player_label((string) $champG, $ranks) : null);
            if ($code === 'S' && $overallChamp) {
                $ccName = tournament_d2_player_label((string) $overallChamp, $ranks);
            }
            $titles = [
                'B' => 'Code B Champ',
                'A' => 'Code A Champ',
                'S' => 'Code S Champ · (overall)',
            ];
            $titleLine = $titles[$code] ?? ('Code ' . $code . ' Champ');
            $label = $ccName ? ($titleLine . ' · ' . $ccName) : ($titleLine . ' · TBD');
            $styleKey = 'code' . $code;
            if (!in_array($styleKey, ['codeB', 'codeA', 'codeS'], true)) {
                $styleKey = 'codeB';
            }
            $ccId = tournament_d2_node_id('code_' . $code . '_champ');
            $lines[] = '  ' . $ccId . ': "' . tournament_d2_escape_label($label) . '"'
                . tournament_d2_node_style($styleKey);
            $lines[] = '  ' . $pathExit . ' -> ' . $ccId;
            $groupExitLocal = $ccId;
        }

        $lines[] = '}';
        $lines[] = '';

        if ($groupExitLocal !== null) {
            $exitIds[$g] = $cid . '.' . $groupExitLocal;
        }
        if ($feedEntryLocal !== null) {
            $entryIds[$g] = $cid . '.' . $feedEntryLocal;
        }
    }

    // Climb spine: group exit → next higher *drawn* group's feeder match (skip empties)
    for ($g = $maxGroup; $g >= 2; $g--) {
        $from = $exitIds[$g] ?? null;
        if ($from === null) {
            continue;
        }
        $to = null;
        for ($h = $g - 1; $h >= 1; $h--) {
            if (isset($entryIds[$h])) {
                $to = $entryIds[$h];
                break;
            }
        }
        if ($to === null) {
            continue;
        }
        $who = '';
        $fromGb = is_array($groups[$g] ?? null) ? $groups[$g] : [];
        if (!empty($fromGb['champion'])) {
            $who = tournament_d2_player_label((string) $fromGb['champion'], $ranks);
        }
        // Arrow alone shows the climb; only label when a named champ is advancing.
        if ($who !== '') {
            $lines[] = $from . ' -> ' . $to . ': "' . tournament_d2_escape_label('↑ ' . $who) . '" {';
        } else {
            $lines[] = $from . ' -> ' . $to . ': {';
        }
        $lines[] = '  style.stroke: "#00d4aa"';
        $lines[] = '  style.stroke-width: 2';
        $lines[] = '}';
    }

    // Pin banner near Code S (or G1 exit)
    if (isset($exitIds[1])) {
        $lines[] = $exitIds[1] . ' -> banner: "" {style.opacity: 0}';
    }

    return implode("\n", $lines) . "\n";
}

/**
 * Render D2 source to an SVG file. Returns absolute SVG path or null on failure.
 *
 * @param array{error?: string}|null $err
 */
function tournament_d2_render_svg(string $d2Source, string $svgPath, ?array &$err = null): ?string
{
    $err = [];
    $bin = tournament_d2_binary();
    if ($bin === '') {
        $err['error'] = 'd2 CLI not found. Install with: brew install d2';
        return null;
    }

    $dir = dirname($svgPath);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        $err['error'] = 'Could not create output directory';
        return null;
    }

    $d2Path = preg_replace('/\.svg$/i', '.d2', $svgPath) ?: ($svgPath . '.d2');
    if (file_put_contents($d2Path, $d2Source) === false) {
        $err['error'] = 'Could not write .d2 source';
        return null;
    }

    $cmd = escapeshellcmd($bin) . ' --theme 200 --pad 24 '
        . escapeshellarg($d2Path) . ' ' . escapeshellarg($svgPath) . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    if ($code !== 0 || !is_file($svgPath)) {
        $err['error'] = 'd2 render failed: ' . implode("\n", $out);
        return null;
    }
    return $svgPath;
}
