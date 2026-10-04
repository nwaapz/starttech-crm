<?php
/**
 * POST /api/sync/buffer/apply — apply phone buffer entries onto remote DB.
 * Body: { entries: [{ entryId, type, payload, serial?, createdAt? }] }
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/serials_write.php';
require_once dirname(__DIR__) . '/sync_auth.php';
require_once dirname(__DIR__) . '/sync_buffer_helpers.php';
require_once dirname(__DIR__) . '/sync_apply_helpers.php';

sync_json_headers();
require_sync_token();

$conn = payamesh_mysqli();
sync_ensure_buffer_table($conn);

$body = json_decode(file_get_contents('php://input'), true);
$entries = $body['entries'] ?? [];
if (!is_array($entries)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid body']);
    exit;
}

$applied = 0;
foreach ($entries as $ev) {
    if (!is_array($ev)) {
        continue;
    }
    if (!isset($ev['entryId']) && isset($ev['eventId'])) {
        $ev['entryId'] = $ev['eventId'];
    }
    if (sync_apply_entry($conn, $ev)) {
        $applied++;
    }
}

$now = (int) (microtime(true) * 1000);
$conn->query(
    "INSERT INTO crm_sync_meta (meta_key, meta_value, updated_at)
     VALUES ('last_buffer_apply_ms', '$now', $now)
     ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value), updated_at=VALUES(updated_at)"
);

echo json_encode(['ok' => true, 'applied' => $applied], JSON_UNESCAPED_UNICODE);
