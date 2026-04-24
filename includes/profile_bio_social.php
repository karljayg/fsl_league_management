<?php
/**
 * Bio + social link types for user profile / edit profile.
 * social_links JSON: [{"type":"youtube","value":"https://..."}, ...]
 */

/** value => label for <select> */
const PROFILE_SOCIAL_TYPES = [
    'twitter' => 'X (Twitter)',
    'youtube' => 'YouTube',
    'twitch' => 'Twitch',
    'discord' => 'Discord',
    'instagram' => 'Instagram',
    'tiktok' => 'TikTok',
    'bluesky' => 'Bluesky',
    'mastodon' => 'Mastodon',
    'website' => 'Website / link',
];

const PROFILE_BIO_MAX_LEN = 4000;
const PROFILE_SOCIAL_VALUE_MAX = 500;
const PROFILE_SOCIAL_MAX_ROWS = 8;

/**
 * Web path (relative to site root, e.g. profile.php) to a monochrome SVG icon.
 * Icons under images/social/ (Simple Icons, CC0 — see https://simpleicons.org).
 */
function profile_social_icon_web_path(string $type): string
{
    static $map = [
        'twitter' => 'x.svg',
        'youtube' => 'youtube.svg',
        'twitch' => 'twitch.svg',
        'discord' => 'discord.svg',
        'instagram' => 'instagram.svg',
        'tiktok' => 'tiktok.svg',
        'bluesky' => 'bluesky.svg',
        'mastodon' => 'mastodon.svg',
        'website' => 'website.svg',
    ];
    $file = $map[$type] ?? 'website.svg';
    $abs = __DIR__ . '/../images/social/' . $file;
    if (!is_file($abs)) {
        return 'images/social/website.svg';
    }

    return 'images/social/' . $file;
}

function profile_text_len(string $s): int
{
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($s, 'UTF-8');
    }

    return strlen($s);
}

function profile_text_truncate(string $s, int $max): string
{
    if (profile_text_len($s) <= $max) {
        return $s;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max, 'UTF-8');
    }

    return substr($s, 0, $max);
}

/**
 * @return list<array{type:string,value:string}>
 */
function profile_parse_social_json(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $type = isset($row['type']) ? (string) $row['type'] : '';
        $value = isset($row['value']) ? trim((string) $row['value']) : '';
        if ($type === '' || $value === '') {
            continue;
        }
        if (!array_key_exists($type, PROFILE_SOCIAL_TYPES)) {
            continue;
        }
        $out[] = ['type' => $type, 'value' => $value];
    }

    return $out;
}

/**
 * Build href for a social row; empty string if not linkable.
 */
function profile_social_href(string $type, string $value): string
{
    $v = trim($value);
    if ($v === '') {
        return '';
    }

    // Discord: normalize invites pasted without scheme, discord.com paths, or bare invite codes
    if ($type === 'discord' && !preg_match('#\Ahttps?://#i', $v)) {
        if (preg_match('#\A(?:www\.)?discord\.gg/([^?\s]+)#i', $v, $m)) {
            $v = 'https://discord.gg/' . trim($m[1], '/');
        } elseif (preg_match('#\A(?:www\.)?discord\.com/.+#i', $v)) {
            $v = 'https://' . ltrim($v, '/');
        } elseif (preg_match('#\A[a-zA-Z0-9_-]{2,40}\z#', $v)) {
            $v = 'https://discord.gg/' . $v;
        }
    }

    if (preg_match('#\Ahttps?://#i', $v)) {
        $p = parse_url($v);
        if (!isset($p['scheme']) || !in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
            return '';
        }

        return $v;
    }

    switch ($type) {
        case 'twitter':
            $h = ltrim($v, '@');

            return 'https://x.com/' . rawurlencode($h);
        case 'youtube':
            $h = ltrim($v, '@');

            return 'https://www.youtube.com/@' . rawurlencode($h);
        case 'twitch':
            return 'https://www.twitch.tv/' . rawurlencode(ltrim($v, '@'));
        case 'instagram':
            return 'https://www.instagram.com/' . rawurlencode(ltrim($v, '@')) . '/';
        case 'tiktok':
            return 'https://www.tiktok.com/@' . rawurlencode(ltrim($v, '@'));
        case 'bluesky':
            $h = ltrim($v, '@');

            return 'https://bsky.app/profile/' . rawurlencode($h);
        case 'mastodon':
            return '';
        case 'discord':
            // Non-URL shapes handled above; remaining values are not linkable here
            return '';
        case 'website':
            return 'https://' . ltrim($v, '/');
        default:
            return '';
    }
}

/**
 * Normalize POSTed social rows into JSON string or null if empty.
 *
 * @return array{0:?string,1:?string} [json or null, error message or null]
 */
function profile_socials_from_post(array $types, array $values): array
{
    $allowed = array_keys(PROFILE_SOCIAL_TYPES);
    $rows = [];
    $n = max(count($types), count($values));
    for ($i = 0; $i < $n && count($rows) < PROFILE_SOCIAL_MAX_ROWS; $i++) {
        $t = isset($types[$i]) ? trim((string) $types[$i]) : '';
        $v = isset($values[$i]) ? trim((string) $values[$i]) : '';
        if ($t === '' && $v === '') {
            continue;
        }
        if ($t === '' || $v === '') {
            return [null, 'Each social row needs both a type and a value, or leave the row empty.'];
        }
        if (!in_array($t, $allowed, true)) {
            return [null, 'Invalid social type selected.'];
        }
        if (profile_text_len($v) > PROFILE_SOCIAL_VALUE_MAX) {
            return [null, 'Each social value may be at most ' . PROFILE_SOCIAL_VALUE_MAX . ' characters.'];
        }
        $rows[] = ['type' => $t, 'value' => $v];
    }
    if ($rows === []) {
        return [null, null];
    }

    return [json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), null];
}
