<?php
/**
 * GET /api/sync/buffer — list remote pending change-buffer entries.
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
    // Do not backfill all registrations here — that blocked the phone on "Fetching buffers…"
    // for minutes. New remote regs enqueue on write; catch-up is optional via admin.
    $entries = sync_buffer_list_pending($conn, 500);
    echo json_encode([
        'ok' => true,
        'pendingCount' => count($entries),
        'entries' => $entries,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error' => 'Buffer GET failed: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
}
