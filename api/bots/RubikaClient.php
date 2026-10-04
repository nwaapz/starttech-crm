<?php
declare(strict_types=1);

require_once __DIR__ . '/HttpJson.php';

final class RubikaClient
{
    private const BASE = 'https://botapi.rubika.ir/v3';

    public static function getMe(string $token): array
    {
        $body = self::post($token, 'getMe', []);
        $bot = $body['data']['bot'] ?? [];
        return [
            'botId' => (string) ($bot['bot_id'] ?? ''),
            'title' => (string) ($bot['bot_title'] ?? ''),
            'username' => (string) ($bot['username'] ?? ''),
        ];
    }

    public static function sendMessage(string $token, string $chatId, string $text): string
    {
        $body = self::post($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
        ]);
        return (string) ($body['data']['message_id'] ?? '');
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
        $keypadRows = [];
        foreach ($rows as $rowIndex => $rowLabels) {
            $buttons = [];
            foreach ($rowLabels as $buttonIndex => $label) {
                $buttons[] = [
                    'id' => 'menu_' . $rowIndex . '_' . $buttonIndex,
                    'type' => 'Simple',
                    'button_text' => (string) $label,
                ];
            }
            $keypadRows[] = ['buttons' => $buttons];
        }
        $body = self::post($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'chat_keypad_type' => 'New',
            'chat_keypad' => [
                'rows' => $keypadRows,
                'resize_keyboard' => true,
                'one_time_keyboard' => false,
            ],
        ]);
        return (string) ($body['data']['message_id'] ?? '');
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
            'chat_keypad_type' => 'New',
            'chat_keypad' => [
                'rows' => [[
                    'buttons' => [[
                        'id' => 'share_phone',
                        'type' => 'RequestPhoneNumber',
                        'button_text' => $buttonText,
                    ]],
                ]],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ],
        ]);
        return (string) ($body['data']['message_id'] ?? '');
    }

    public static function getUpdates(string $token, ?string $offsetId, int $limit = 10): array
    {
        $payload = ['limit' => $limit];
        if ($offsetId !== null && $offsetId !== '') {
            $payload['offset_id'] = $offsetId;
        }
        $body = self::post($token, 'getUpdates', $payload);
        $data = $body['data'] ?? [];
        $updates = [];
        foreach (($data['updates'] ?? []) as $update) {
            $parsed = self::parseApiUpdate($update);
            if ($parsed !== null) {
                $updates[] = $parsed;
            }
        }
        $next = isset($data['next_offset_id']) && $data['next_offset_id'] !== ''
            ? (string) $data['next_offset_id']
            : null;
        return ['updates' => $updates, 'nextOffsetId' => $next];
    }

    /**
     * Register HTTPS webhook endpoint(s). Rubika pushes events instantly (no 1‑min cron).
     * Types: ReceiveUpdate | ReceiveInlineMessage | …
     */
    public static function updateBotEndpoint(string $token, string $url, string $type = 'ReceiveUpdate'): void
    {
        self::post($token, 'updateBotEndpoints', [
            'url' => $url,
            'type' => $type,
        ]);
    }

    /**
     * Parse webhook POST body from Rubika (update / inline_message wrappers).
     * @return list<array>
     */
    public static function parseWebhookPayload(array $body): array
    {
        $out = [];
        if (isset($body['update']) && is_array($body['update'])) {
            $parsed = self::parseApiUpdate($body['update']);
            if ($parsed !== null) {
                $out[] = $parsed;
            }
        }
        if (isset($body['inline_message']) && is_array($body['inline_message'])) {
            $im = $body['inline_message'];
            $chatId = (string) ($im['chat_id'] ?? '');
            $messageId = (string) ($im['message_id'] ?? '');
            if ($chatId !== '' && $messageId !== '') {
                $text = (string) ($im['text'] ?? '');
                $buttonId = '';
                if (!empty($im['aux_data']) && is_array($im['aux_data'])) {
                    $buttonId = (string) ($im['aux_data']['button_id'] ?? '');
                }
                if ($buttonId !== '' && $text === '') {
                    $text = $buttonId;
                }
                $out[] = [
                    'chatId' => $chatId,
                    'messageId' => $messageId,
                    'text' => $text,
                    'senderId' => ($im['sender_id'] ?? null) ? (string) $im['sender_id'] : null,
                    'senderType' => 'User',
                    'phoneNumber' => null,
                    'senderName' => null,
                    'timestamp' => (int) round(microtime(true) * 1000),
                    'photoFileId' => null,
                    'photoMimeType' => null,
                ];
            }
        }
        // Some payloads may be a bare Update object
        if ($out === [] && isset($body['type'], $body['chat_id'])) {
            $parsed = self::parseApiUpdate($body);
            if ($parsed !== null) {
                $out[] = $parsed;
            }
        }
        return $out;
    }

    /** @return array|null normalized update for bot_process_incoming */
    public static function parseApiUpdate(array $update): ?array
    {
        if (($update['type'] ?? '') !== 'NewMessage') {
            return null;
        }
        $chatId = (string) ($update['chat_id'] ?? '');
        $newMessage = $update['new_message'] ?? null;
        if (!is_array($newMessage) || $chatId === '') {
            return null;
        }
        $messageId = (string) ($newMessage['message_id'] ?? '');
        if ($messageId === '') {
            return null;
        }
        if (strcasecmp((string) ($newMessage['sender_type'] ?? ''), 'Bot') === 0) {
            return null;
        }
        $phone = null;
        $contact = $newMessage['contact_message'] ?? ($newMessage['contact'] ?? null);
        if (is_array($contact)) {
            $raw = (string) ($contact['phone_number'] ?? $contact['phone'] ?? '');
            if ($raw !== '') {
                $phone = preg_replace('/\D/', '', $raw);
            }
        }
        $text = (string) ($newMessage['text'] ?? '');
        // Chat keypad button presses often arrive via aux_data.button_id
        if ($text === '' && !empty($newMessage['aux_data']) && is_array($newMessage['aux_data'])) {
            $buttonId = (string) ($newMessage['aux_data']['button_id'] ?? '');
            if ($buttonId !== '') {
                $text = $buttonId;
            }
        }
        $ts = (int) ($newMessage['time'] ?? 0);
        if ($ts > 0) {
            $ts *= 1000;
        } else {
            $ts = (int) round(microtime(true) * 1000);
        }
        $file = self::extractIncomingFile($newMessage);
        $display = $text !== '' ? $text : ($phone ?? '');
        if ($display === '' && $file !== null) {
            $display = '[photo]';
        }
        return [
            'chatId' => $chatId,
            'messageId' => $messageId,
            'text' => $display,
            'senderId' => ($newMessage['sender_id'] ?? null) ? (string) $newMessage['sender_id'] : null,
            'senderType' => (string) ($newMessage['sender_type'] ?? 'User'),
            'phoneNumber' => $phone,
            'senderName' => null,
            'timestamp' => $ts,
            'photoFileId' => $file['fileId'] ?? null,
            'photoMimeType' => $file['mime'] ?? null,
        ];
    }

    /**
     * @return array{fileId:string,mime:?string}|null
     */
    public static function extractIncomingFile(array $newMessage): ?array
    {
        $candidates = [];
        foreach (['file', 'file_inline', 'photo', 'image'] as $key) {
            if (!empty($newMessage[$key]) && is_array($newMessage[$key])) {
                $candidates[] = $newMessage[$key];
            }
        }
        foreach ($candidates as $obj) {
            $id = '';
            foreach (['file_id', 'fileId', 'id'] as $k) {
                if (!empty($obj[$k])) {
                    $id = (string) $obj[$k];
                    break;
                }
            }
            if ($id === '') {
                continue;
            }
            $mime = (string) ($obj['mime'] ?? $obj['mime_type'] ?? '');
            if ($mime === '') {
                $name = (string) ($obj['file_name'] ?? '');
                if (preg_match('/\.png$/i', $name)) {
                    $mime = 'image/png';
                } elseif (preg_match('/\.webp$/i', $name)) {
                    $mime = 'image/webp';
                } elseif (preg_match('/\.gif$/i', $name)) {
                    $mime = 'image/gif';
                } else {
                    $mime = 'image/jpeg';
                }
            }
            return ['fileId' => $id, 'mime' => $mime];
        }
        if (!empty($newMessage['file_id'])) {
            return ['fileId' => (string) $newMessage['file_id'], 'mime' => 'image/jpeg'];
        }
        return null;
    }

    /**
     * @return array{0:string,1:?string}
     */
    public static function downloadFileBytes(string $token, string $fileId): array
    {
        $body = self::post($token, 'getFile', ['file_id' => $fileId]);
        $data = $body['data'] ?? $body;
        $downloadUrl = '';
        if (is_array($data)) {
            $downloadUrl = (string) ($data['download_url'] ?? '');
            if ($downloadUrl === '' && !empty($data['file']) && is_array($data['file'])) {
                $downloadUrl = (string) ($data['file']['download_url'] ?? '');
            }
            if ($downloadUrl === '') {
                $downloadUrl = (string) ($data['url'] ?? '');
            }
        }
        if ($downloadUrl === '') {
            throw new RuntimeException('Rubika download_url missing');
        }
        return bot_http_bytes($downloadUrl);
    }

    private static function post(string $token, string $method, array $payload): array
    {
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', trim($token)) ?? '';
        if ($token === '') {
            throw new RuntimeException('Bot token is not configured');
        }
        $url = self::BASE . '/' . rawurlencode($token) . '/' . $method;
        $body = bot_http_json($url, $payload);
        $status = (string) ($body['status'] ?? '');
        if ($status !== '' && strtoupper($status) !== 'OK') {
            $detail = trim((string) ($body['error'] ?? ''));
            if ($detail === '') {
                $detail = 'API status: ' . $status;
            }
            if (stripos($status, 'INVALID') !== false || stripos($detail, 'INVALID') !== false) {
                throw new RuntimeException(
                    'توکن روبیکا نامعتبر است یا رد شد (' . $detail . '). توکن را از BotFather روبیکا دوباره کپی کنید.'
                );
            }
            throw new RuntimeException($detail);
        }
        return $body;
    }
}
