<?php
/**
 * Bale webhook receiver (public).
 * URL set on connect: /crm/api/webhooks/bale.php?secret=...
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/bots_store.php';
require_once dirname(__DIR__) . '/bot_processor.php';
require_once dirname(__DIR__) . '/bots/TelegramClient.php';
require_once dirname(__DIR__) . '/bots/BaleClient.php';

$conn = payamesh_mysqli();
bots_ensure_schema($conn);

$secret = (string) ($_GET['secret'] ?? '');
$runtime = bots_get_runtime($conn, 'bale');
if (!$runtime || empty($runtime['active']) || $secret === '' || !hash_equals((string) ($runtime['webhook_secret'] ?? ''), $secret)) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$update = json_decode($raw, true);
if (!is_array($update)) {
    http_response_code(400);
    echo 'bad json';
    exit;
}

$parsed = TelegramClient::parseUpdate($update);
if ($parsed !== null) {
    try {
        bot_process_incoming($conn, 'bale', $parsed);
    } catch (Throwable $e) {
        error_log('[webhook/bale] ' . $e->getMessage());
    }
}

header('Content-Type: text/plain; charset=utf-8');
echo 'ok';
