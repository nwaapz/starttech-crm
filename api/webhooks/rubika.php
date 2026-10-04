<?php
/**
 * Rubika webhook receiver (instant — no cron delay).
 * Registered on connect via updateBotEndpoints (ReceiveUpdate / ReceiveInlineMessage).
 * URL: /crm/api/webhooks/rubika.php?secret=...
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/bots_store.php';
require_once dirname(__DIR__) . '/bot_processor.php';
require_once dirname(__DIR__) . '/bots/RubikaClient.php';

$conn = payamesh_mysqli();
bots_ensure_schema($conn);

$secret = (string) ($_GET['secret'] ?? '');
$runtime = bots_get_runtime($conn, 'rubika');
if (!$runtime || empty($runtime['active']) || $secret === '' || !hash_equals((string) ($runtime['webhook_secret'] ?? ''), $secret)) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo 'bad json';
    exit;
}

$parsedList = RubikaClient::parseWebhookPayload($body);
foreach ($parsedList as $parsed) {
    try {
        bot_process_incoming($conn, 'rubika', $parsed);
    } catch (Throwable $e) {
        error_log('[webhook/rubika] ' . $e->getMessage());
    }
}

header('Content-Type: text/plain; charset=utf-8');
echo 'ok';

