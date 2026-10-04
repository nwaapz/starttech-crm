<?php
/**
 * Cron: poll Rubika getUpdates (and optional TG/Bale backup).
 *
 * Shell crontab (preferred):
 *   * * * * * /usr/bin/php /path/to/crm-php/cron/bot_poll_rubika.php >> /path/to/logs/bot_poll.log 2>&1
 *
 * Or hit the web endpoint every minute (cPanel "Cron Job" URL):
 *   https://YOUR-DOMAIN/crm/api/cron_poll_bots.php?key=YOUR_WEBHOOK_SECRET
 *   (key = bot_runtime_state.webhook_secret for rubika, created at Connect)
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/bootstrap.php';
require_once $root . '/api/bot_poll.php';

date_default_timezone_set('Asia/Tehran');
$conn = payamesh_mysqli();
echo json_encode(bots_poll_all_once($conn, true), JSON_UNESCAPED_UNICODE) . "\n";

