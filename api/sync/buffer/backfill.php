<?php
/**
 * POST /api/sync/buffer/backfill — manual catch-up: re-enqueue the most recently
 * registered serials as pending buffer entries so a phone that missed them
 * (e.g. after loading a stale CSV/backup) can pull them via the normal
 * /api/sync/buffer -> apply -> ack flow. Count-based (not date-based), so it
 * covers a gap of any age. No request body required.
 */
declare(strict_types=1);

try {
    require_once dirname(__DIR__, 3) . '/config/database.php';
    require_once dirname(__DIR__, 2) . '/bootstrap.php';
    require_once dirname(__DIR__) . '/sync_auth.php';
    require_once dirname(__DIR__) . '/sync_buffer_helpers.php';

    sync_json_headers();
    require_sync_token();

    $conn = payamesh_mysqli();
    $enqueued = sync_buffer_backfill_registrations($conn, 5000);
    echo json_encode([
        'ok' => true,
        'enqueued' => $enqueued,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error' => 'Buffer backfill failed: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
}
