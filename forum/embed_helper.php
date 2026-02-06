<?php
/**
 * Optional URL embedding for forum post bodies using embed/embed.
 * Requires: composer require embed/embed (run from project root).
 * If vendor/autoload.php is missing, falls back to safe_post_html only.
 */

if (!function_exists('safe_post_html')) {
    require_once __DIR__ . '/safe_html.php';
}

/** Allowed iframe src hosts for oEmbed output (lowercase). */
$GLOBALS['embed_allowed_iframe_hosts'] = [
    'youtube.com', 'www.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com', 'youtu.be',
    'vimeo.com', 'player.vimeo.com',
    'twitch.tv', 'player.twitch.tv', 'clips.twitch.tv',
    'open.spotify.com', 'spotify.com',
    'w.soundcloud.com', 'soundcloud.com',
    'www.instagram.com', 'instagram.com',
    'platform.twitter.com', 'platform.x.com',
];

/**
 * Sanitize embed HTML: allow only iframe with src from allowed hosts.
 *
 * @param string $html Raw HTML from Embed (e.g. iframe).
 * @return string Safe iframe tag or empty string.
 */
function sanitize_embed_html($html) {
    if ($html === null || $html === '') return '';
    $hosts = isset($GLOBALS['embed_allowed_iframe_hosts']) ? $GLOBALS['embed_allowed_iframe_hosts'] : [];
    // Match iframe with src (allow any attribute order and whitespace)
    if (preg_match('/<iframe[\s\S]*?src\s*=\s*["\']([^"\']+)["\'][\s\S]*?>/i', $html, $m)) {
        $url = $m[1];
        $parsed = @parse_url($url);
        if (empty($parsed['host'])) return '';
        $host = strtolower($parsed['host']);
        foreach ($hosts as $allowed) {
            $suffix = '.' . $allowed;
            if ($host === $allowed || (strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix)) {
                return '<iframe src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" width="560" height="315" frameborder="0" allowfullscreen loading="lazy"></iframe>';
            }
        }
    }
    return '';
}

/**
 * Extract URLs from text (plain and inside href="...").
 * Decodes HTML entities so stored links work.
 *
 * @param string $text
 * @return string[]
 */
function extract_urls_from_text($text) {
    if ($text === null || $text === '') return [];
    $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $urls = [];
    // Plain URLs in text
    if (preg_match_all('#https?://[^\s<>"\']+#i', $decoded, $m)) {
        foreach ($m[0] as $u) {
            $u = rtrim($u, '.,;:!?)');
            if ($u !== '' && !in_array($u, $urls, true)) $urls[] = $u;
        }
    }
    // href="https://..."
    if (preg_match_all('#href\s*=\s*["\'](https?://[^"\']+)["\']#i', $decoded, $m)) {
        foreach ($m[1] as $u) {
            $u = trim($u);
            if ($u !== '' && !in_array($u, $urls, true)) $urls[] = $u;
        }
    }
    return $urls;
}

/**
 * Post body HTML with optional embeds for detected URLs.
 * Uses embed/embed when vendor is present; otherwise returns safe_post_html only.
 *
 * @param string|null $raw Raw post body from DB.
 * @param int $maxEmbeds Max number of URLs to try per post (avoids slow timeouts).
 * @return string HTML safe for output.
 */
function post_body_with_embeds($raw, $maxEmbeds = 3) {
    $html = safe_post_html($raw);
    $root = dirname(__DIR__);
    $autoload = $root . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        return $html;
    }
    require_once $autoload;
    $urls = extract_urls_from_text($raw);
    if ($urls === []) return $html;
    $urls = array_slice($urls, 0, $maxEmbeds);
    $embeds = [];
    try {
        $embed = new \Embed\Embed();
        foreach ($urls as $url) {
            try {
                $info = $embed->get($url);
                if (!isset($info->code) || $info->code === null) continue;
                $codeHtml = (string) $info->code;
                if ($codeHtml === '') continue;
                $safe = sanitize_embed_html($codeHtml);
                if ($safe !== '' && !in_array($safe, $embeds, true)) {
                    $embeds[] = $safe;
                }
            } catch (Throwable $e) {
                continue;
            }
        }
    } catch (Throwable $e) {
        // Embed failed (e.g. no PSR-17); fall through to URL fallbacks
    }
    // Fallbacks when Embed not available or returned no iframe: build iframe from known URL patterns
    if ($embeds === []) {
        foreach ($urls as $url) {
            // YouTube (?v=ID or &v=ID or /embed/ID or youtu.be/ID)
            if (preg_match('#(?:youtube\.com/watch\?.*?v=|youtube\.com/embed/|youtu\.be/)([a-zA-Z0-9_-]{10,})#i', $url, $mv)) {
                $embeds[] = '<iframe src="https://www.youtube.com/embed/' . htmlspecialchars($mv[1], ENT_QUOTES, 'UTF-8') . '" width="560" height="315" frameborder="0" allowfullscreen loading="lazy"></iframe>';
                break;
            }
            // Instagram /p/CODE or /reel/CODE
            if (preg_match('#instagram\.com/(p|reel)/([a-zA-Z0-9_-]+)#i', $url, $mv)) {
                $type = strtolower($mv[1]);
                $code = $mv[2];
                $embeds[] = '<iframe src="https://www.instagram.com/' . $type . '/' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '/embed/" width="400" height="480" frameborder="0" loading="lazy"></iframe>';
                break;
            }
            // X / Twitter status
            if (preg_match('#(?:x\.com|twitter\.com)/\w+/status/(\d+)#i', $url, $mv)) {
                $tid = $mv[1];
                $embeds[] = '<iframe src="https://platform.twitter.com/embed/index.html?dnt=true&amp;id=' . htmlspecialchars($tid, ENT_QUOTES, 'UTF-8') . '" width="550" height="350" frameborder="0" loading="lazy"></iframe>';
                break;
            }
        }
    }
    if ($embeds === []) return $html;
    $block = '<div class="post-embeds">' . implode('', $embeds) . '</div>';
    return $html . $block;
}
