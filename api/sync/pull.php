<?php
/**
 * Legacy endpoint retired — dual-buffer sync only.
 */
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'error' => 'Gone',
    'message' => 'Use /api/sync/buffer, /api/sync/buffer/apply, /api/sync/buffer/ack',
]);
