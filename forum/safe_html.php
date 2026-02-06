<?php
/**
 * Allowlist filter for post body HTML. Use for display only; store raw in DB.
 * Allows common forum tags; strips script, event handlers, javascript: and data: URLs.
 */
function safe_post_html($body) {
    if ($body === null || $body === '') return '';
    $s = (string) $body;
    $allowed = '<a><b><i><u><em><strong><blockquote><font><br><p><img><span>';
    $s = strip_tags($s, $allowed);
    // Remove event handlers and style (XSS)
    $s = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $s);
    $s = preg_replace('/\s+style\s*=\s*["\'][^"\']*["\']/i', '', $s);
    // Only allow safe href: http, https, relative /, or #
    $s = preg_replace('/href\s*=\s*["\']\s*(?!https?:\/\/|\/|#)[^"\']*["\']/i', 'href="#"', $s);
    // Only allow safe img src: http, https (no data: or javascript:)
    $s = preg_replace('/src\s*=\s*["\']\s*(?!https?:\/\/)[^"\']*["\']/i', 'src=""', $s);
    return nl2br($s, false);
}
