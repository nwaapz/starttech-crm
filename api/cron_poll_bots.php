<?php
/**
 * Web-triggerable bot poller for hosts without CLI crontab.
 *
 * GET/POST /crm/api/cron_poll_bots.php?key=<rubika webhook_secret>
 *
 * The secret is stored when you Connect the Rubika bot (bot_runtime_state.webhook_secret).
 * Find it in MySQL: SELECT webhook_secret FROM bot_runtime_state WHERE channel='rubika';
 *
 * cPanel Cron example (every minute):
 *   curl -fsS "https://YOUR-DOMAIN/crm/api/cron_poll_bots.php?key=SECRET" >/dev/null
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/bots_store.php';
require_once __DIR__ . '/bot_poll.php';

date_default_timezone_set('Asia/Tehran');

$key = trim((string) ($_GET['key'] ?? $_POST['key'] ?? ''));
if ($key === '') {
    // Also accept Authorization: Bearer <key>
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $hdr, $m)) {
        $key = trim($m[1]);
    }
}
if ($key === '') {
    json_error('Missing key', 401);
}

$conn = payamesh_mysqli();
bots_ensure_schema($conn);

$ok = false;
foreach (['rubika', 'telegram', 'bale'] as $channel) {
    $runtime = bots_get_runtime($conn, $channel);
    $secret = trim((string) ($runtime['webhook_secret'] ?? ''));
    if ($secret !== '' && hash_equals($secret, $key)) {
        $ok = true;
        break;
    }
}
// Optional shared sync token from config
global $PAYAMESH_SYNC_TOKEN;
if (!$ok && !empty($PAYAMESH_SYNC_TOKEN) && hash_equals((string) $PAYAMESH_SYNC_TOKEN, $key)) {
    $ok = true;
}
if (!$ok) {
    json_error('Invalid key', 401);
}

json_out(bots_poll_all_once($conn, true));

