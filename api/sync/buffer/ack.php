<?php
/**
 * POST /api/sync/buffer/ack — mark remote buffer entries as applied by phone.
 * Body: { entryIds: ["uuid", ...] }
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/sync_auth.php';
require_once dirname(__DIR__) . '/sync_buffer_helpers.php';

sync_json_headers();
require_sync_token();

$conn = payamesh_mysqli();
$body = json_decode(file_get_contents('php://input'), true);
$ids = $body['entryIds'] ?? [];
if (!is_array($ids)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid body']);
    exit;
}

$acked = sync_buffer_ack($conn, $ids);
echo json_encode(['ok' => true, 'acked' => $acked], JSON_UNESCAPED_UNICODE);
