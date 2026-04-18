<?php
/**
 * Map veto — validate and store uploaded map images under map-veto/data/images/.
 */

declare(strict_types=1);

require_once __DIR__ . '/map_veto_constants.php';

function map_veto_public_image_url_from_basename(string $basename): string
{
    return MAP_VETO_PUBLIC_WEB_IMAGE_BASE . '/' . $basename;
}

/**
 * Remove any existing image file for this map id (any supported extension).
 */
function map_veto_delete_existing_images_for_map_id(string $mapId): void
{
    $dir = MAP_VETO_IMAGES_DIR;
    foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $ext) {
        $p = $dir . '/' . $mapId . '.' . $ext;
        if (is_file($p)) {
            @unlink($p);
        }
    }
}

/**
 * Process an optional single file upload for a map; replaces on-disk image for $mapId.
 *
 * @return array{ok: bool, message?: string, url?: string|null}
 */
function map_veto_process_map_image_upload(string $mapId, array $file): array
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'url' => null];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Image upload failed (code ' . $err . ').'];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'Invalid upload.'];
    }
    $size = (int) ($file['size'] ?? 0);
    $max = (int) MAP_VETO_MAX_IMAGE_BYTES;
    if ($size <= 0 || $size > $max) {
        return ['ok' => false, 'message' => 'Image must be 2 MB or smaller.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $mimeToExt = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!is_string($mime) || !isset($mimeToExt[$mime])) {
        return ['ok' => false, 'message' => 'Use JPEG, PNG, WebP, or GIF.'];
    }
    $ext = $mimeToExt[$mime];

    if (!is_dir(MAP_VETO_IMAGES_DIR)) {
        mkdir(MAP_VETO_IMAGES_DIR, 0775, true);
    }

    map_veto_delete_existing_images_for_map_id($mapId);

    $basename = $mapId . '.' . $ext;
    $dest = MAP_VETO_IMAGES_DIR . '/' . $basename;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'message' => 'Could not save image file.'];
    }
    @chmod($dest, 0644);

    return ['ok' => true, 'url' => map_veto_public_image_url_from_basename($basename)];
}
