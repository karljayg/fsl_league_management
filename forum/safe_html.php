<?php
/**
 * Allowlist filter for post body HTML. Use for display only; store raw in DB.
 * Allows common forum tags; strips script, event handlers, javascript: and data: URLs.
 * Plain URLs are made clickable; links open in a new tab.
 */

/**
 * Wrap plain URLs (not already inside an HTML tag) in <a> so they become clickable.
 * Skips linkifying when the text is already the content of an <a> (between <a...> and </a>).
 */
function linkify_plain_urls($text) {
    if ($text === null || $text === '') return '';
    $parts = preg_split('/(<[^>]+>)/', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    $out = '';
    $prev = '';
    $n = count($parts);
    for ($i = 0; $i < $n; $i++) {
        $part = $parts[$i];
        if (strpos($part, '<') === 0) {
            $out .= $part;
            $prev = $part;
            continue;
        }
        $next = ($i + 1 < $n) ? $parts[$i + 1] : '';
        $is_inside_anchor = (preg_match('/^<a\s/i', $prev) && strtolower(substr(trim($next), 0, 4)) === '</a>');
        if (!$is_inside_anchor) {
            $part = preg_replace_callback(
                '#https?://[^\s<>"\']+#i',
                function ($m) {
                    $url = $m[0];
                    return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</a>';
                },
                $part
            );
        }
        $out .= $part;
        if (strpos($part, '<') === 0) $prev = $part;
    }
    return $out;
}

/**
 * Add target="_blank" rel="noopener noreferrer" to <a href="http..."> that don't have target.
 */
function add_target_blank_to_links($html) {
    return preg_replace(
        '/<a(\s+(?![^>]*\btarget=)[^>]*)href=["\'](https?:\/\/[^"\']+)["\']/i',
        '<a$1target="_blank" rel="noopener noreferrer" href="$2"',
        $html
    );
}

function safe_post_html($body) {
    if ($body === null || $body === '') return '';
    $s = (string) $body;
    $s = linkify_plain_urls($s);
    $allowed = '<a><b><i><u><em><strong><blockquote><font><br><p><img><span>';
    $s = strip_tags($s, $allowed);
    // Remove event handlers and style (XSS)
    $s = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $s);
    $s = preg_replace('/\s+style\s*=\s*["\'][^"\']*["\']/i', '', $s);
    // Only allow safe href: http, https, relative /, or #
    $s = preg_replace('/href\s*=\s*["\']\s*(?!https?:\/\/|\/|#)[^"\']*["\']/i', 'href="#"', $s);
    // Only allow safe img src: http, https (no data: or javascript:)
    $s = preg_replace('/src\s*=\s*["\']\s*(?!https?:\/\/)[^"\']*["\']/i', 'src=""', $s);
    $s = add_target_blank_to_links($s);
    return nl2br($s, false);
}
