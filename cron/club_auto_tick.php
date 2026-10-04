<?php
/**
 * Cron entry: run due club / warranty auto SMS on the remote server.
 *
 * Sends via Melipayamak (crm_send_panel_sms). Completely separate from the Android app SMS pipeline.
 *
 * Crontab example (every 5 minutes; set host TZ to Asia/Tehran or rely on script timezone):
 *   */5 * * * * /usr/bin/php /path/to/crm-php/cron/club_auto_tick.php >> /path/to/logs/club_auto.log 2>&1
 *
 * Manual:
 *   php cron/club_auto_tick.php
 *   php cron/club_auto_tick.php --rule=123
 *
 * --rule=ID forces one rule immediately (ignores daily time gate / already-ran-today).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/bootstrap.php';
require_once $root . '/api/settings_store.php';
require_once $root . '/api/club_auto_runner.php';

date_default_timezone_set('Asia/Tehran');

$conn = payamesh_mysqli();
crm_ensure_settings_schema($conn);
crm_club_ensure_sends_table($conn);

$forceRuleId = null;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--rule=(\d+)$/', $arg, $m)) {
        $forceRuleId = (int) $m[1];
    }
}

$started = date('Y-m-d H:i:s');
if ($forceRuleId !== null) {
    $got = null;
    $res = $conn->query('SELECT * FROM club_auto_message_rules WHERE id = ' . (int) $forceRuleId);
    if ($res) {
        $got = $res->fetch_assoc();
    }
    if (!$got) {
        fwrite(STDERR, "[$started] rule $forceRuleId not found\n");
        exit(1);
    }
    $result = crm_club_run_rule($conn, $got, true);
    echo "[$started] force rule=$forceRuleId " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
    exit(!empty($result['ok']) ? 0 : 1);
}

$summary = crm_club_run_due_rules($conn);
echo '[' . $started . '] ' . json_encode($summary, JSON_UNESCAPED_UNICODE) . "\n";
exit(0);
