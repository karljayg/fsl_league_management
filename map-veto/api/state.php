<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/map_veto.php';

header('Content-Type: application/json; charset=UTF-8');

$token = $_GET['t'] ?? '';
if ($token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing token']);
    exit;
}

$hit = map_veto_find_by_token($token);
if ($hit === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Invalid token']);
    exit;
}

$sessionId = (string) ($hit['session']['id'] ?? '');
$live = map_veto_refresh_session($sessionId);
if ($live === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Session not found']);
    exit;
}

echo json_encode([
    'ok' => true,
    'state' => map_veto_build_payload($live, $hit['role']),
], JSON_UNESCAPED_SLASHES);
