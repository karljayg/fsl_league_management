<?php
/**
 * Load main-site user info. Uses main site includes/db.php (same DB as profile.php).
 */

/**
 * Returns map: user_id => ['avatar_url' => ...]
 * $author_by_id optional: map user_id => username to fallback lookup when id lookup misses (e.g. id 0 vs real id).
 */
function forum_get_user_avatars($user_ids, $author_by_id = []) {
    $user_ids = array_map('intval', (array) $user_ids);
    $user_ids = array_unique(array_filter($user_ids, function ($id) { return $id !== null && $id !== '' && $id >= 0; }));
    $out = [];
    $db = _forum_main_db();
    if (!$db) return $out;
    if ($user_ids !== []) {
        try {
            $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
            $stmt = $db->prepare("SELECT id, avatar_url FROM users WHERE id IN ($placeholders)");
            $stmt->execute(array_values($user_ids));
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $url = isset($row['avatar_url']) && $row['avatar_url'] !== '' ? $row['avatar_url'] : null;
                $out[(int) $row['id']] = ['avatar_url' => $url];
            }
        } catch (Exception $e) { /* ignore */ }
    }
    foreach ($user_ids as $uid) {
        if (isset($out[$uid]['avatar_url']) && $out[$uid]['avatar_url'] !== null) continue;
        $author = isset($author_by_id[$uid]) ? trim($author_by_id[$uid]) : '';
        if ($author === '') continue;
        try {
            $stmt = $db->prepare("SELECT avatar_url FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$author]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['avatar_url']) && $row['avatar_url'] !== '') {
                $out[$uid] = ['avatar_url' => $row['avatar_url']];
            } elseif (!isset($out[$uid])) {
                $out[$uid] = ['avatar_url' => null];
            }
        } catch (Exception $e) { /* ignore */ }
    }
    return $out;
}

/**
 * Look up main-site user by username. Returns ['id' => int, 'avatar_url' => string|null] or null.
 */
function forum_lookup_user_by_author($author) {
    $author = trim((string) $author);
    if ($author === '') return null;
    $db = _forum_main_db();
    if (!$db) return null;
    try {
        $stmt = $db->prepare("SELECT id, avatar_url FROM users WHERE LOWER(TRIM(username)) = LOWER(?) LIMIT 1");
        $stmt->execute([$author]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $url = isset($row['avatar_url']) && $row['avatar_url'] !== '' ? $row['avatar_url'] : null;
        return ['id' => (int) $row['id'], 'avatar_url' => $url];
    } catch (Exception $e) {
        return null;
    }
}

function _forum_main_db() {
    static $cached = null;
    if ($cached !== null) return $cached;
    if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
        $cached = $GLOBALS['db'];
        return $cached;
    }
    $dbFile = dirname(__DIR__) . '/includes/db.php';
    if (!file_exists($dbFile)) return null;
    try {
        require_once $dbFile;
        // includes/db.php creates $db in the requirer's scope; it does not set $GLOBALS['db']
        if (isset($db) && $db instanceof PDO) {
            $cached = $db;
        } else {
            $cached = null;
        }
        return $cached;
    } catch (Exception $e) {
        return null;
    }
}
