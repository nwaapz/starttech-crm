<?php
declare(strict_types=1);

require_once __DIR__ . '/HttpJson.php';

final class TelegramClient
{
    private const BASE = 'https://api.telegram.org';

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
    }

    public static function deleteWebhook(string $token): void
    {
        try {
            self::post($token, 'deleteWebhook', ['drop_pending_updates' => false]);
        } catch (Throwable $e) {
            // ignore
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
            $parsed = self::parseUpdate($update);
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

    public static function parseUpdate(array $update): ?array
    {
        $message = $update['message'] ?? null;
        if (!is_array($message)) {
            return null;
        }
        $from = $message['from'] ?? [];
        if (!empty($from['is_bot'])) {
            return null;
        }
        $chat = $message['chat'] ?? [];
        $chatId = (string) ($chat['id'] ?? '');
        $rawId = (string) ($message['message_id'] ?? '');
        if ($chatId === '' || $rawId === '') {
            return null;
        }
        $phone = null;
        $contact = $message['contact'] ?? null;
        if (is_array($contact) && !empty($contact['phone_number'])) {
            $phone = preg_replace('/\D/', '', (string) $contact['phone_number']);
        }
        $text = (string) ($message['text'] ?? '');
        if ($text === '') {
            $text = (string) ($message['caption'] ?? '');
        }
        $photoFileId = null;
        $photoMimeType = null;
        if (!empty($message['photo']) && is_array($message['photo'])) {
            $largest = null;
            $largestArea = -1;
            foreach ($message['photo'] as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $w = (int) ($p['width'] ?? 0);
                $h = (int) ($p['height'] ?? 0);
                $area = $w * $h;
                if ($area >= $largestArea && !empty($p['file_id'])) {
                    $largestArea = $area;
                    $largest = $p;
                }
            }
            if ($largest !== null) {
                $photoFileId = (string) $largest['file_id'];
                $photoMimeType = 'image/jpeg';
            }
        }
        if ($photoFileId === null && !empty($message['document']) && is_array($message['document'])) {
            $doc = $message['document'];
            $mime = (string) ($doc['mime_type'] ?? '');
            if (strpos($mime, 'image/') === 0 && !empty($doc['file_id'])) {
                $photoFileId = (string) $doc['file_id'];
                $photoMimeType = $mime !== '' ? $mime : 'image/jpeg';
            }
        }
        $ts = (int) ($message['date'] ?? 0);
        if ($ts > 0) {
            $ts *= 1000;
        } else {
            $ts = (int) round(microtime(true) * 1000);
        }
        $display = $text;
        if ($phone && $display === '') {
            $display = $phone;
        }
        return [
            'chatId' => $chatId,
            'messageId' => $chatId . ':' . $rawId,
            'text' => $display,
            'senderId' => isset($from['id']) ? (string) $from['id'] : null,
            'senderType' => !empty($from['is_bot']) ? 'Bot' : 'User',
            'phoneNumber' => $phone,
            'senderName' => trim(((string) ($from['first_name'] ?? '')) . ' ' . ((string) ($from['last_name'] ?? ''))),
            'timestamp' => $ts,
            'updateId' => (string) ($update['update_id'] ?? ''),
            'photoFileId' => $photoFileId,
            'photoMimeType' => $photoMimeType,
        ];
    }

    /**
     * @return array{0:string,1:?string}
     */
    public static function downloadFileBytes(string $token, string $fileId): array
    {
        $body = self::post($token, 'getFile', ['file_id' => $fileId]);
        $path = (string) (($body['result']['file_path'] ?? '') ?: '');
        if ($path === '') {
            throw new RuntimeException('Telegram file path missing');
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
            throw new RuntimeException((string) ($body['description'] ?? 'Telegram API error'));
        }
        return $body;
    }
}
