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
    $bLabel = trim((string) ($match['broadcast_label_full'] ?? $match['broadcast_label'] ?? ''));
    if ($bLabel !== '') {
        $line .= ' · (' . $bLabel . ')';
    }
    $champLabel = trim((string) ($match['code_championship_label'] ?? ''));
    if ($champLabel === '' && !empty($match['code_championship'])) {
        $code = strtoupper(trim((string) $match['code_championship']));
        $champLabel = $code !== '' ? ('Code ' . $code . ' Championship') : 'Championship';
    }
    if ($champLabel !== '') {
        $line .= ' · ' . $champLabel;
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
    // Stamp stable "(Nth broadcast)" labels onto contested matches when missing.
    if (!isset($bracket['broadcast_schedule']) || !is_array($bracket['broadcast_schedule'])) {
        $state = tournament_load_state();
        $annotated = tournament_annotate_broadcasts_from_state($bracket, $state, [
            'matches_per_broadcast' => 4,
            'code_order' => ['B', 'A', 'S'],
            'same_broadcast_chaining' => true,
            'phase_by_code' => true,
        ]);
        $bracket = $annotated['bracket'];
    }

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
 * Normalize export format: png|svg (default png).
 */
function tournament_d2_normalize_format(?string $format): string
{
    $format = strtolower(trim((string) $format));
    return $format === 'svg' ? 'svg' : 'png';
}

/**
 * Normalize scale percent to 100|50|25 (default 50).
 */
function tournament_d2_normalize_scale_pct($scale): int
{
    $n = (int) $scale;
    if ($n === 100 || $n === 25) {
        return $n;
    }
    return 50;
}

/**
 * @return float d2 --scale value
 */
function tournament_d2_scale_factor(int $scalePct): float
{
    return max(0.01, $scalePct / 100.0);
}

/**
 * Output basename for a format+scale combo (no directory).
 */
function tournament_d2_export_basename(string $format, int $scalePct): string
{
    $format = tournament_d2_normalize_format($format);
    $scalePct = tournament_d2_normalize_scale_pct($scalePct);
    return 'individual_championships_bracket_s' . $scalePct . '.' . $format;
}

/**
 * Legacy diagram basename (older export filename).
 */
function tournament_d2_legacy_export_basename(string $format, int $scalePct): string
{
    $format = tournament_d2_normalize_format($format);
    $scalePct = tournament_d2_normalize_scale_pct($scalePct);
    return 'challenger_bracket_s' . $scalePct . '.' . $format;
}

/**
 * Resolve existing diagram file path: prefer new name, fall back to legacy.
 *
 * @return array{name:string,fs:string,web:string}|null
 */
function tournament_d2_resolve_export(string $outDir, string $format, int $scalePct): ?array
{
    $format = tournament_d2_normalize_format($format);
    $scalePct = tournament_d2_normalize_scale_pct($scalePct);
    foreach ([
        tournament_d2_export_basename($format, $scalePct),
        tournament_d2_legacy_export_basename($format, $scalePct),
    ] as $name) {
        $fs = rtrim($outDir, '/') . '/' . $name;
        if (is_file($fs)) {
            return [
                'name' => $name,
                'fs' => $fs,
                'web' => 'diagrams/' . $name,
            ];
        }
    }
    return null;
}

/**
 * Convert an SVG file to PNG using the best available local tool.
 *
 * @param array{error?: string}|null $err
 */
function tournament_d2_svg_to_png(string $svgPath, string $pngPath, ?array &$err = null): bool
{
    $err = $err ?? [];
    $candidates = [
        ['rsvg-convert', ['-o', $pngPath, $svgPath]],
        ['magick', [$svgPath, $pngPath]],
        ['convert', [$svgPath, $pngPath]],
    ];
    // macOS fallback
    if (is_executable('/usr/bin/sips')) {
        $candidates[] = ['/usr/bin/sips', ['-s', 'format', 'png', $svgPath, '--out', $pngPath]];
    }

    foreach ($candidates as [$bin, $args]) {
        $resolved = $bin;
        if ($bin[0] !== '/') {
            $which = trim((string) shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
            if ($which === '' || !is_executable($which)) {
                continue;
            }
            $resolved = $which;
        } elseif (!is_executable($resolved)) {
            continue;
        }

        $cmd = escapeshellcmd($resolved);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $cmd .= ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        if ($code === 0 && is_file($pngPath) && filesize($pngPath) > 0) {
            return true;
        }
        @unlink($pngPath);
    }

    $err['error'] = 'PNG conversion failed. Install rsvg-convert (librsvg2-tools) or ImageMagick. '
        . 'See rankings/README.md (Amazon Linux: sudo dnf install -y librsvg2-tools).';
    return false;
}

/**
 * Render D2 source to SVG or PNG at a given scale.
 * Returns absolute output path or null on failure.
 *
 * @param array{format?:string,scale?:int|float}|null $opts
 * @param array{error?: string}|null $err
 */
function tournament_d2_render(string $d2Source, string $outDir, ?array $opts = null, ?array &$err = null): ?string
{
    $err = [];
    $opts = is_array($opts) ? $opts : [];
    $format = tournament_d2_normalize_format($opts['format'] ?? 'png');
    $scalePct = tournament_d2_normalize_scale_pct($opts['scale'] ?? 50);
    $scale = tournament_d2_scale_factor($scalePct);

    $bin = tournament_d2_binary();
    if ($bin === '') {
        $err['error'] = 'd2 CLI not found. Install with: curl -fsSL https://d2lang.com/install.sh | sudo sh -s -- --prefix /usr/local (see rankings/README.md)';
        return null;
    }

    if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
        $err['error'] = 'Could not create output directory';
        return null;
    }

    $base = 'individual_championships_bracket_s' . $scalePct;
    $d2Path = $outDir . '/' . $base . '.d2';
    $svgPath = $outDir . '/' . $base . '.svg';
    $pngPath = $outDir . '/' . $base . '.png';
    $finalPath = $format === 'png' ? $pngPath : $svgPath;

    if (file_put_contents($d2Path, $d2Source) === false) {
        $err['error'] = 'Could not write .d2 source';
        return null;
    }

    // Always render SVG first (reliable). PNG via d2 direct or SVG→PNG converter.
    $cmd = escapeshellcmd($bin)
        . ' --theme 200 --pad 24 --scale ' . escapeshellarg((string) $scale) . ' '
        . escapeshellarg($d2Path) . ' ' . escapeshellarg($svgPath) . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    if ($code !== 0 || !is_file($svgPath)) {
        $err['error'] = 'd2 render failed: ' . implode("\n", $out);
        return null;
    }

    if ($format === 'svg') {
        return $svgPath;
    }

    // Prefer SVG→PNG converters. d2's native PNG path pulls Playwright and needs a
    // writable HOME/.cache for the Apache/httpd user — fragile on shared hosts.
    $convErr = [];
    if (tournament_d2_svg_to_png($svgPath, $pngPath, $convErr)) {
        return $pngPath;
    }

    $err['error'] = $convErr['error'] ?? 'PNG render failed';
    return null;
}

/**
 * Backward-compatible SVG helper (100% scale).
 *
 * @param array{error?: string}|null $err
 */
function tournament_d2_render_svg(string $d2Source, string $svgPath, ?array &$err = null): ?string
{
    $dir = dirname($svgPath);
    $path = tournament_d2_render($d2Source, $dir, ['format' => 'svg', 'scale' => 100], $err);
    if ($path === null) {
        return null;
    }
    // If caller asked for a specific filename, copy/rename when different.
    if (realpath($path) !== realpath($svgPath) && $path !== $svgPath) {
        if (!@copy($path, $svgPath)) {
            $err = $err ?? [];
            $err['error'] = 'Could not write SVG to requested path';
            return null;
        }
        return $svgPath;
    }
    return $path;
}
