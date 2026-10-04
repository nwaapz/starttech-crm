<?php
declare(strict_types=1);

require_once __DIR__ . '/comms_store.php';
require_once __DIR__ . '/bots_store.php';
require_once __DIR__ . '/bot_processor.php';

function crm_handle_comms_routes(mysqli $conn, string $method, string $path): bool
{
    if ($path === '/communications' && $method === 'GET') {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
        $direction = trim((string) ($_GET['direction'] ?? ''));
        $phone = trim((string) ($_GET['phone'] ?? ''));
        $channel = trim((string) ($_GET['channel'] ?? ''));
        json_out(comms_list($conn, $page, $limit, $direction, $phone, $channel));
    }

    if ($path === '/communications/thread' && $method === 'GET') {
        $phone = trim((string) ($_GET['phone'] ?? ''));
        if ($phone === '') {
            json_error('phone is required', 400);
        }
        $before = isset($_GET['before']) ? (int) $_GET['before'] : null;
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
        json_out(comms_thread($conn, $phone, $before, $limit));
    }

    if ($path === '/communications/send' && $method === 'POST') {
        $body = body_json();
        $phone = trim((string) ($body['phone'] ?? ''));
        $text = trim((string) ($body['body'] ?? ''));
        $channels = $body['channels'] ?? [];
        if ($phone === '' || $text === '') {
            json_error('شماره و متن پیام الزامی است', 400);
        }
        if (!is_array($channels) || $channels === []) {
            json_error('حداقل یک تونل معتبر انتخاب کنید', 400);
        }
        $phone = comms_normalize_phone($phone);
        $results = [];
        foreach ($channels as $ch) {
            $ch = strtolower(trim((string) $ch));
            if (!in_array($ch, ['sms', 'rubika', 'telegram', 'bale'], true)) {
                $results[] = ['channel' => $ch, 'ok' => false, 'error' => 'کانال نامعتبر'];
                continue;
            }
            try {
                if ($ch === 'sms') {
                    $send = crm_send_panel_sms($phone, $text);
                    if ($send === true) {
                        comms_insert($conn, $phone, 'outgoing', $text, 'sms', 'sent');
                        $results[] = ['channel' => 'sms', 'ok' => true];
                    } else {
                        comms_insert($conn, $phone, 'outgoing', $text, 'sms', 'failed');
                        $results[] = ['channel' => 'sms', 'ok' => false, 'error' => (string) $send];
                    }
                } else {
                    $ok = bot_send_plain($conn, $ch, $phone, $text);
                    $results[] = [
                        'channel' => $ch,
                        'ok' => $ok,
                        'error' => $ok ? null : 'ربات متصل نیست یا شماره جفت نشده',
                    ];
                }
            } catch (Throwable $e) {
                $results[] = ['channel' => $ch, 'ok' => false, 'error' => $e->getMessage()];
            }
        }
        json_out(['phone' => $phone, 'results' => $results]);
    }

    return false;
}

function crm_handle_bot_channel_routes(mysqli $conn, string $method, string $path): bool
{
    if (!preg_match('#^/(rubika|telegram|bale)(/.*)?$#', $path, $m)) {
        return false;
    }
    $channel = $m[1];
    $sub = $m[2] ?? '';

    require_once __DIR__ . '/bots/TelegramClient.php';
    require_once __DIR__ . '/bots/BaleClient.php';
    require_once __DIR__ . '/bots/RubikaClient.php';
    require_once __DIR__ . '/activity_store.php';

    if ($sub === '/status' && $method === 'GET') {
        json_out(bots_status_payload($conn, $channel));
    }

    if ($sub === '/messages' && $method === 'GET') {
        $since = (int) ($_GET['since'] ?? 0);
        $limit = max(1, min(200, (int) ($_GET['limit'] ?? 100)));
        json_out(bots_list_messages($conn, $channel, $since, $limit));
    }

    if ($sub === '/connect' && $method === 'POST') {
        $body = body_json();
        $token = trim((string) ($body['token'] ?? ''));
        if ($token === '') {
            json_error('token is required', 400);
        }
        try {
            if ($channel === 'telegram') {
                $info = TelegramClient::getMe($token);
            } elseif ($channel === 'bale') {
                $info = BaleClient::getMe($token);
            } else {
                $info = RubikaClient::getMe($token);
            }
            $secret = bin2hex(random_bytes(16));
            bots_save_runtime($conn, $channel, [
                'token' => $token,
                'bot_id' => $info['botId'] ?? '',
                'bot_title' => $info['title'] ?? '',
                'bot_username' => $info['username'] ?? '',
                'active' => 1,
                'next_offset_id' => null,
                'webhook_secret' => $secret,
            ]);
            if ($channel === 'telegram' || $channel === 'bale') {
                $url = bots_webhook_url($conn, $channel);
                if ($channel === 'telegram') {
                    TelegramClient::setWebhook($token, $url);
                } else {
                    BaleClient::setWebhook($token, $url);
                }
            } elseif ($channel === 'rubika') {
                // Instant delivery (HTTPS required). Same URL for message + inline keypad events.
                $url = bots_webhook_url($conn, 'rubika');
                RubikaClient::updateBotEndpoint($token, $url, 'ReceiveUpdate');
                try {
                    RubikaClient::updateBotEndpoint($token, $url, 'ReceiveInlineMessage');
                } catch (Throwable $e) {
                    // Non-fatal: main chat updates still work
                    error_log('[rubika] ReceiveInlineMessage endpoint: ' . $e->getMessage());
                }
            }
            crm_activity_record($conn, 'bot_connected', [
                'channel' => $channel,
                'title' => $info['title'] ?? $info['username'] ?? $channel,
                'message' => 'اتصال ربات ' . $channel,
            ]);
            json_out(bots_status_payload($conn, $channel));
        } catch (Throwable $e) {
            json_error($e->getMessage() ?: 'Connect failed', 400);
        }
    }

    if ($sub === '/disconnect' && $method === 'POST') {
        $runtime = bots_get_runtime($conn, $channel);
        $token = trim((string) ($runtime['token'] ?? ''));
        if ($token !== '') {
            if ($channel === 'telegram') {
                TelegramClient::deleteWebhook($token);
            } elseif ($channel === 'bale') {
                BaleClient::deleteWebhook($token);
            } elseif ($channel === 'rubika') {
                try {
                    // Clear endpoints (empty URL) so Rubika stops POSTing to this host
                    RubikaClient::updateBotEndpoint($token, '', 'ReceiveUpdate');
                    RubikaClient::updateBotEndpoint($token, '', 'ReceiveInlineMessage');
                } catch (Throwable $e) {
                    error_log('[rubika] clear endpoint: ' . $e->getMessage());
                }
            }
        }
        bots_clear_runtime($conn, $channel);
        crm_activity_record($conn, 'bot_disconnected', [
            'channel' => $channel,
            'message' => 'قطع اتصال ربات ' . $channel,
        ]);
        json_out(bots_status_payload($conn, $channel));
    }

    // Optional getUpdates backup (primary path is HTTPS webhook on connect).
    if ($sub === '/poll' && $method === 'POST') {
        require_once __DIR__ . '/bot_poll.php';
        $result = bots_poll_channel_once($conn, $channel);
        json_out(['ok' => empty($result['error']), 'result' => $result]);
    }

    return false;
}
