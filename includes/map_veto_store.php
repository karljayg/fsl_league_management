<?php
/**
 * JSON persistence for map veto sessions and catalogs.
 */

declare(strict_types=1);

require_once __DIR__ . '/map_veto_constants.php';

function map_veto_ensure_directories(): void
{
    if (!is_dir(MAP_VETO_ROOT)) {
        mkdir(MAP_VETO_ROOT, 0775, true);
    }
    if (!is_dir(MAP_VETO_SESSIONS_DIR)) {
        mkdir(MAP_VETO_SESSIONS_DIR, 0775, true);
    }
}

function map_veto_generate_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
}

/**
 * @return array<int, array<string, mixed>>
 */
function map_veto_load_maps(): array
{
    map_veto_ensure_directories();
    if (!is_readable(MAP_VETO_MAPS_FILE)) {
        return [];
    }
    $data = json_decode((string) file_get_contents(MAP_VETO_MAPS_FILE), true);
    return is_array($data) ? $data : [];
}

/**
 * @param array<int, array<string, mixed>> $maps
 */
function map_veto_save_maps(array $maps): void
{
    map_veto_ensure_directories();
    $tmp = MAP_VETO_MAPS_FILE . '.tmp';
    file_put_contents($tmp, json_encode($maps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmp, MAP_VETO_MAPS_FILE);
}

/**
 * @return array<int, array<string, mixed>>
 */
function map_veto_load_seasons(): array
{
    map_veto_ensure_directories();
    if (!is_readable(MAP_VETO_SEASONS_FILE)) {
        return [];
    }
    $data = json_decode((string) file_get_contents(MAP_VETO_SEASONS_FILE), true);
    return is_array($data) ? $data : [];
}

/**
 * @param array<int, array<string, mixed>> $seasons
 */
function map_veto_save_seasons(array $seasons): void
{
    map_veto_ensure_directories();
    $tmp = MAP_VETO_SEASONS_FILE . '.tmp';
    file_put_contents($tmp, json_encode($seasons, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmp, MAP_VETO_SEASONS_FILE);
}

/**
 * @return array<string, mixed>|null
 */
function map_veto_load_session(string $id): ?array
{
    map_veto_ensure_directories();
    $path = MAP_VETO_SESSIONS_DIR . '/' . basename($id) . '.json';
    if (!is_readable($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

/**
 * @param array<string, mixed> $session
 */
function map_veto_save_session(array $session): void
{
    map_veto_ensure_directories();
    $id = basename((string) ($session['id'] ?? ''));
    if ($id === '') {
        return;
    }
    $session['updated_at'] = gmdate('c');
    $path = MAP_VETO_SESSIONS_DIR . '/' . $id . '.json';
    $tmp = $path . '.tmp';
    file_put_contents($tmp, json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmp, $path);
}

/**
 * Permanently delete a session file (must match stored id format).
 *
 * @return bool True if a session file was removed
 */
function map_veto_delete_session(string $id): bool
{
    map_veto_ensure_directories();
    $safe = basename($id);
    if ($safe === '' || !preg_match('/^mv_[a-f0-9]{24}$/', $safe)) {
        return false;
    }
    $path = MAP_VETO_SESSIONS_DIR . '/' . $safe . '.json';
    if (!is_file($path)) {
        return false;
    }

    return @unlink($path);
}

/**
 * @template T
 * @param callable(array<string, mixed>): array<string, mixed>|null $fn
 * @return array<string, mixed>|null
 */
function map_veto_session_transaction(string $id, callable $fn): ?array
{
    map_veto_ensure_directories();
    $path = MAP_VETO_SESSIONS_DIR . '/' . basename($id) . '.json';
    if (!is_file($path)) {
        return null;
    }
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        return null;
    }
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $session = json_decode($raw ?: 'null', true);
    if (!is_array($session)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return null;
    }
    $session = $fn($session);
    if (!is_array($session)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return null;
    }
    $session['updated_at'] = gmdate('c');
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $session;
}

/**
 * @return list<array<string, mixed>>
 */
function map_veto_list_sessions(): array
{
    map_veto_ensure_directories();
    $out = [];
    $files = glob(MAP_VETO_SESSIONS_DIR . '/*.json') ?: [];
    foreach ($files as $file) {
        $j = json_decode((string) file_get_contents($file), true);
        if (is_array($j)) {
            $out[] = $j;
        }
    }
    usort($out, static function ($a, $b) {
        $ta = strtotime((string) ($a['created_at'] ?? '0')) ?: 0;
        $tb = strtotime((string) ($b['created_at'] ?? '0')) ?: 0;
        return $tb <=> $ta;
    });
    return $out;
}

/**
 * @return array{session: array<string, mixed>, role: string}|null
 */
function map_veto_find_by_token(string $token): ?array
{
    if ($token === '') {
        return null;
    }
    map_veto_ensure_directories();
    $files = glob(MAP_VETO_SESSIONS_DIR . '/*.json') ?: [];
    foreach ($files as $file) {
        $s = json_decode((string) file_get_contents($file), true);
        if (!is_array($s) || !isset($s['tokens']) || !is_array($s['tokens'])) {
            continue;
        }
        $tok = $s['tokens'];
        if (($tok['player_a'] ?? '') === $token) {
            return ['session' => $s, 'role' => 'player_a'];
        }
        if (($tok['player_b'] ?? '') === $token) {
            return ['session' => $s, 'role' => 'player_b'];
        }
        if (($tok['public'] ?? '') === $token) {
            return ['session' => $s, 'role' => 'public'];
        }
    }
    return null;
}
