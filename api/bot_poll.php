<?php
/**
 * Shared bot getUpdates poller (Rubika primary; TG/Bale optional backup).
 */
declare(strict_types=1);

require_once __DIR__ . '/bots_store.php';
require_once __DIR__ . '/bot_processor.php';
require_once __DIR__ . '/bots/RubikaClient.php';
require_once __DIR__ . '/bots/TelegramClient.php';
require_once __DIR__ . '/bots/BaleClient.php';

/**
 * Poll one channel once and process inbound updates.
 *
 * @return array{channel:string,skipped?:bool,error?:string,processed?:int,nextOffset?:?string}
 */
function bots_poll_channel_once(mysqli $conn, string $channel): array
{
    $runtime = bots_get_runtime($conn, $channel);
    $token = trim((string) ($runtime['token'] ?? ''));
    if ($token === '' || empty($runtime['active'])) {
        return ['channel' => $channel, 'skipped' => true];
    }
    $offsetRaw = $runtime['next_offset_id'] ?? null;
    $offset = ($offsetRaw !== null && trim((string) $offsetRaw) !== '')
        ? (string) $offsetRaw
        : null;
    try {
        if ($channel === 'rubika') {
            $result = RubikaClient::getUpdates($token, $offset, 20);
        } elseif ($channel === 'telegram') {
            $result = TelegramClient::getUpdates($token, $offset, 20);
        } elseif ($channel === 'bale') {
            $result = BaleClient::getUpdates($token, $offset, 20);
        } else {
            return ['channel' => $channel, 'skipped' => true];
        }
    } catch (Throwable $e) {
        return ['channel' => $channel, 'error' => $e->getMessage()];
    }

    $count = 0;
    foreach ($result['updates'] as $update) {
        try {
            bot_process_incoming($conn, $channel, $update);
            $count++;
        } catch (Throwable $e) {
            error_log('[bot_poll] process ' . $channel . ': ' . $e->getMessage());
        }
    }
    $next = $result['nextOffsetId'] ?? null;
    if ($next !== null && $next !== '') {
        bots_save_runtime($conn, $channel, ['next_offset_id' => $next]);
    }
    return [
        'channel' => $channel,
        'processed' => $count,
        'nextOffset' => $next,
    ];
}

/**
 * @return array{at:string,rubika:array,telegram?:array,bale?:array}
 */
function bots_poll_all_once(mysqli $conn, bool $includeTelegramBale = false): array
{
    bots_ensure_schema($conn);
    $out = [
        'at' => date('Y-m-d H:i:s'),
        'rubika' => bots_poll_channel_once($conn, 'rubika'),
    ];
    if ($includeTelegramBale) {
        $out['telegram'] = bots_poll_channel_once($conn, 'telegram');
        $out['bale'] = bots_poll_channel_once($conn, 'bale');
    }
    return $out;
}

