<?php
declare(strict_types=1);

require_once __DIR__ . '/HttpJson.php';
require_once __DIR__ . '/TelegramClient.php';

/** Bale Bot API — Telegram-compatible shape on tapi.bale.ai */
final class BaleClient
{
    private const BASE = 'https://tapi.bale.ai';

    public static function getMe(string $token): array
    {
        $body = self::post($token, 'getMe', []);
        $bot = $body['result'] ?? [];
        return [
            'botId' => (string) ($bot['id'] ?? ''),
            'title' => (string) ($bot['first_name'] ?? ''),
            'username' => (string) ($bot['username'] ?? ''),
        ];
    }

    public static function setWebhook(string $token, string $url): void
    {
        $body = self::post($token, 'setWebhook', ['url' => $url]);
        if (empty($body['ok'])) {
            throw new RuntimeException((string) ($body['description'] ?? 'setWebhook failed'));
        }
        // Confirm registration — empty url means getUpdates (phone LAN poller) stole the channel.
        try {
            $info = self::getWebhookInfo($token);
            $registered = trim((string) (($info['result']['url'] ?? '') ?: ''));
            if ($registered === '') {
                throw new RuntimeException(
                    'وب‌هوک بله ثبت نشد. اگر همین توکن روی اپ LAN (گوشی) متصل است، اول آنجا قطع اتصال کنید — getUpdates با وب‌هوک تداخل دارد.'
                );
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            // setWebhook already succeeded; info check is best-effort
            error_log('[bale] getWebhookInfo: ' . $e->getMessage());
        }
    }

    public static function getWebhookInfo(string $token): array
    {
        return self::post($token, 'getWebhookInfo', []);
    }

    public static function deleteWebhook(string $token): void
    {
        try {
            // Prefer empty URL (documented) so polling can resume on LAN if needed
            self::post($token, 'setWebhook', ['url' => '']);
        } catch (Throwable $e) {
            try {
                self::post($token, 'deleteWebhook', []);
            } catch (Throwable $e2) {
                // ignore
            }
        }
    }

    public static function sendMessage(string $token, string $chatId, string $text): string
    {
        $body = self::post($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
        ]);
        return (string) (($body['result']['message_id'] ?? '') ?: '');
    }

    public static function sendMessageWithReplyKeyboard(
        string $token,
        string $chatId,
        string $text,
        array $rows
    ): string {
        if ($rows === []) {
            return self::sendMessage($token, $chatId, $text);
        }
        $keyboardRows = [];
        foreach ($rows as $rowLabels) {
            $row = [];
            foreach ($rowLabels as $label) {
                $row[] = ['text' => (string) $label];
            }
            $keyboardRows[] = $row;
        }
        $body = self::post($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => [
                'keyboard' => $keyboardRows,
                'resize_keyboard' => true,
                'one_time_keyboard' => false,
            ],
        ]);
        return (string) (($body['result']['message_id'] ?? '') ?: '');
    }

    public static function sendPhoneNumberRequest(
        string $token,
        string $chatId,
        string $text,
        string $buttonText = 'ارسال شماره تماس'
    ): string {
        $body = self::post($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => [
                'keyboard' => [[['text' => $buttonText, 'request_contact' => true]]],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ],
        ]);
        return (string) (($body['result']['message_id'] ?? '') ?: '');
    }

    public static function getUpdates(string $token, ?string $offsetId, int $limit = 10): array
    {
        $payload = ['limit' => $limit, 'timeout' => 0];
        if ($offsetId !== null && $offsetId !== '') {
            $payload['offset'] = (int) $offsetId;
        }
        $body = self::post($token, 'getUpdates', $payload);
        $updates = [];
        foreach (($body['result'] ?? []) as $update) {
            $parsed = TelegramClient::parseUpdate($update);
            if ($parsed !== null) {
                $updates[] = $parsed;
            }
        }
        $next = null;
        $arr = $body['result'] ?? [];
        if ($arr !== []) {
            $last = $arr[count($arr) - 1];
            $next = (string) (((int) ($last['update_id'] ?? 0)) + 1);
        }
        return ['updates' => $updates, 'nextOffsetId' => $next];
    }

    /**
     * @return array{0:string,1:?string}
     */
    public static function downloadFileBytes(string $token, string $fileId): array
    {
        $body = self::post($token, 'getFile', ['file_id' => $fileId]);
        $path = (string) (($body['result']['file_path'] ?? '') ?: '');
        if ($path === '') {
            throw new RuntimeException('Bale file path missing');
        }
        $url = self::BASE . '/file/bot' . trim($token) . '/' . $path;
        return bot_http_bytes($url);
    }

    private static function post(string $token, string $method, array $payload): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new RuntimeException('Bot token is not configured');
        }
        $url = self::BASE . '/bot' . $token . '/' . $method;
        $body = bot_http_json($url, $payload);
        if (isset($body['ok']) && $body['ok'] === false) {
            throw new RuntimeException((string) ($body['description'] ?? 'Bale API error'));
        }
        return $body;
    }
}
