<?php
/**
 * Paths for map veto JSON/images (project root map-veto/data/).
 */

declare(strict_types=1);

if (!defined('MAP_VETO_ROOT')) {
    define('MAP_VETO_ROOT', dirname(__DIR__) . '/map-veto/data');
    define('MAP_VETO_SESSIONS_DIR', MAP_VETO_ROOT . '/sessions');
    define('MAP_VETO_MAPS_FILE', MAP_VETO_ROOT . '/maps.json');
    define('MAP_VETO_SEASONS_FILE', MAP_VETO_ROOT . '/seasons.json');
    define('MAP_VETO_IMAGES_DIR', MAP_VETO_ROOT . '/images');
    /** Web path prefix for uploaded/static map images (same prefix as MAP_VETO_IMAGES_DIR relative to doc root /fsl). */
    define('MAP_VETO_PUBLIC_WEB_IMAGE_BASE', '/fsl/map-veto/data/images');
    define('MAP_VETO_RANKINGS_FILE', dirname(__DIR__) . '/rankings/rankings.json');
    /** Max uploaded map image size (bytes); practical ~1 MB, cap 2 MB for safety */
    define('MAP_VETO_MAX_IMAGE_BYTES', 2 * 1024 * 1024);
}
