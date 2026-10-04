<?php
/**
 * Bot runtime state, chat contacts, and per-channel message inbox.
 */
declare(strict_types=1);

require_once __DIR__ . '/comms_store.php';

function bots_ensure_schema(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS bot_runtime_state (
            channel VARCHAR(16) NOT NULL PRIMARY KEY,
            token TEXT,
            bot_id VARCHAR(64) DEFAULT NULL,
            bot_title VARCHAR(255) DEFAULT NULL,
            bot_username VARCHAR(128) DEFAULT NULL,
            active TINYINT(1) NOT NULL DEFAULT 0,
            next_offset_id VARCHAR(128) DEFAULT NULL,
            webhook_secret VARCHAR(64) DEFAULT NULL,
            updated_at BIGINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS bot_chat_contacts (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            channel VARCHAR(16) NOT NULL,
            chat_id VARCHAR(64) NOT NULL,
            phone VARCHAR(32) DEFAULT NULL,
            sender_name VARCHAR(255) DEFAULT NULL,
            phone_request_sent_at BIGINT DEFAULT NULL,
            updated_at BIGINT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_bot_chat (channel, chat_id),
            INDEX idx_bot_phone (channel, phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS bot_messages (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            channel VARCHAR(16) NOT NULL,
            message_id VARCHAR(128) NOT NULL,
            chat_id VARCHAR(64) NOT NULL,
            sender_id VARCHAR(64) DEFAULT NULL,
            text TEXT,
            direction VARCHAR(16) NOT NULL DEFAULT 'incoming',
            phone VARCHAR(32) DEFAULT NULL,
            sender_name VARCHAR(255) DEFAULT NULL,
            timestamp_ms BIGINT NOT NULL,
            UNIQUE KEY uq_bot_msg (channel, message_id),
            INDEX idx_bot_msg_ts (channel, timestamp_ms)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function bots_valid_channel(string $channel): bool
{
    return in_array($channel, ['rubika', 'telegram', 'bale'], true);
}

function bots_get_runtime(mysqli $conn, string $channel): ?array
{
    bots_ensure_schema($conn);
    if (!bots_valid_channel($channel)) {
        return null;
    }
    $stmt = $conn->prepare('SELECT * FROM bot_runtime_state WHERE channel = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $channel);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    $stmt->close();
    return $row;
}

function bots_save_runtime(mysqli $conn, string $channel, array $fields): void
{
    bots_ensure_schema($conn);
    $now = (int) round(microtime(true) * 1000);
    $existing = bots_get_runtime($conn, $channel);
    $token = array_key_exists('token', $fields) ? $fields['token'] : ($existing['token'] ?? null);
    $botId = array_key_exists('bot_id', $fields) ? $fields['bot_id'] : ($existing['bot_id'] ?? null);
    $title = array_key_exists('bot_title', $fields) ? $fields['bot_title'] : ($existing['bot_title'] ?? null);
    $username = array_key_exists('bot_username', $fields) ? $fields['bot_username'] : ($existing['bot_username'] ?? null);
    $active = array_key_exists('active', $fields) ? (int) (bool) $fields['active'] : (int) ($existing['active'] ?? 0);
    $offset = array_key_exists('next_offset_id', $fields) ? $fields['next_offset_id'] : ($existing['next_offset_id'] ?? null);
    $secret = array_key_exists('webhook_secret', $fields) ? $fields['webhook_secret'] : ($existing['webhook_secret'] ?? null);
    // mysqli string binds: use empty string instead of null
    $token = $token === null ? '' : (string) $token;
    $botId = $botId === null ? '' : (string) $botId;
    $title = $title === null ? '' : (string) $title;
    $username = $username === null ? '' : (string) $username;
    $offset = $offset === null ? '' : (string) $offset;
    $secret = $secret === null ? '' : (string) $secret;

    $stmt = $conn->prepare(
        'INSERT INTO bot_runtime_state
            (channel, token, bot_id, bot_title, bot_username, active, next_offset_id, webhook_secret, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            token=VALUES(token), bot_id=VALUES(bot_id), bot_title=VALUES(bot_title),
            bot_username=VALUES(bot_username), active=VALUES(active),
            next_offset_id=VALUES(next_offset_id), webhook_secret=VALUES(webhook_secret),
            updated_at=VALUES(updated_at)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param(
        'sssssissi',
        $channel,
        $token,
        $botId,
        $title,
        $username,
        $active,
        $offset,
        $secret,
        $now
    );
    $stmt->execute();
    $stmt->close();
}

function bots_clear_runtime(mysqli $conn, string $channel): void
{
    bots_save_runtime($conn, $channel, [
        'token' => null,
        'bot_id' => null,
        'bot_title' => null,
        'bot_username' => null,
        'active' => 0,
        'next_offset_id' => null,
        'webhook_secret' => null,
    ]);
}

function bots_status_payload(mysqli $conn, string $channel): array
{
    require_once __DIR__ . '/settings_store.php';
    $runtime = bots_get_runtime($conn, $channel);
    $settingsKey = $channel . '_settings';
    $menu = crm_settings_get_or_default($conn, $settingsKey, crm_default_bot_menu());
    $active = !empty($runtime['active']) && trim((string) ($runtime['token'] ?? '')) !== '';
    return [
        'active' => $active,
        'botTitle' => ($runtime['bot_title'] ?? null) ?: null,
        'botUsername' => ($runtime['bot_username'] ?? null) ?: null,
        'serialEnabled' => (bool) ($menu['serialEnabled'] ?? true),
        'inviteEnabled' => (bool) ($menu['inviteEnabled'] ?? true),
        // Remote bots use HTTPS webhooks (instant). LAN Android uses getUpdates pollers.
        'polling' => false,
    ];
}

function bots_upsert_contact(
    mysqli $conn,
    string $channel,
    string $chatId,
    ?string $phone = null,
    ?string $senderName = null,
    ?int $phoneRequestSentAt = null
): void {
    bots_ensure_schema($conn);
    $now = (int) round(microtime(true) * 1000);
    $phoneNorm = $phone !== null && $phone !== '' ? comms_normalize_phone($phone) : '';
    $senderName = $senderName !== null ? (string) $senderName : '';
    $existing = bots_find_contact($conn, $channel, $chatId);
    if ($existing) {
        if ($phoneNorm === '') {
            $phoneNorm = (string) ($existing['phone'] ?? '');
        }
        if ($senderName === '') {
            $senderName = (string) ($existing['sender_name'] ?? '');
        }
        $reqAt = $phoneRequestSentAt !== null
            ? $phoneRequestSentAt
            : (int) ($existing['phone_request_sent_at'] ?? 0);
        $stmt = $conn->prepare(
            'UPDATE bot_chat_contacts SET phone=?, sender_name=?, phone_request_sent_at=?, updated_at=?
             WHERE channel=? AND chat_id=?'
        );
        if ($stmt) {
            $stmt->bind_param('ssiiss', $phoneNorm, $senderName, $reqAt, $now, $channel, $chatId);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }
    $reqAt = $phoneRequestSentAt ?? 0;
    $stmt = $conn->prepare(
        'INSERT INTO bot_chat_contacts (channel, chat_id, phone, sender_name, phone_request_sent_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    if ($stmt) {
        $stmt->bind_param('ssssii', $channel, $chatId, $phoneNorm, $senderName, $reqAt, $now);
        $stmt->execute();
        $stmt->close();
    }
}

function bots_find_contact(mysqli $conn, string $channel, string $chatId): ?array
{
    bots_ensure_schema($conn);
    $stmt = $conn->prepare('SELECT * FROM bot_chat_contacts WHERE channel=? AND chat_id=? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ss', $channel, $chatId);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    $stmt->close();
    return $row;
}

function bots_chat_id_for_phone(mysqli $conn, string $channel, string $phone): ?string
{
    bots_ensure_schema($conn);
    $phone = comms_normalize_phone($phone);
    if ($phone === '') {
        return null;
    }
    $stmt = $conn->prepare(
        'SELECT chat_id FROM bot_chat_contacts
         WHERE channel=? AND phone=? AND phone IS NOT NULL AND phone <> ""
         ORDER BY updated_at DESC LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ss', $channel, $phone);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    $stmt->close();
    return $row ? (string) $row['chat_id'] : null;
}

function bots_is_phone_paired(mysqli $conn, string $channel, string $phone): bool
{
    return bots_chat_id_for_phone($conn, $channel, $phone) !== null;
}

/**
 * @return array<string,array{rubika:bool,telegram:bool,bale:bool}>
 */
function bots_pairing_map_for_phones(mysqli $conn, array $phones): array
{
    bots_ensure_schema($conn);
    $out = [];
    $normalized = [];
    foreach ($phones as $p) {
        $n = comms_normalize_phone((string) $p);
        if ($n !== '') {
            $normalized[$n] = true;
            $out[$n] = ['rubika' => false, 'telegram' => false, 'bale' => false];
        }
    }
    if ($normalized === []) {
        return $out;
    }
    $list = array_keys($normalized);
    $placeholders = implode(',', array_fill(0, count($list), '?'));
    $types = str_repeat('s', count($list));
    $sql = "SELECT channel, phone FROM bot_chat_contacts
            WHERE phone IN ($placeholders) AND phone IS NOT NULL AND phone <> ''";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $out;
    }
    $stmt->bind_param($types, ...$list);
    $stmt->execute();
    $res = method_exists($stmt, 'get_result') ? $stmt->get_result() : null;
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $phone = comms_normalize_phone((string) ($row['phone'] ?? ''));
            $ch = (string) ($row['channel'] ?? '');
            if ($phone !== '' && isset($out[$phone]) && isset($out[$phone][$ch])) {
                $out[$phone][$ch] = true;
            }
        }
    }
    $stmt->close();
    return $out;
}

function bots_append_message(
    mysqli $conn,
    string $channel,
    string $messageId,
    string $chatId,
    string $text,
    string $direction,
    ?string $phone = null,
    ?string $senderId = null,
    ?string $senderName = null,
    ?int $timestampMs = null
): bool {
    bots_ensure_schema($conn);
    $ts = $timestampMs ?? (int) round(microtime(true) * 1000);
    $phoneNorm = $phone ? comms_normalize_phone($phone) : null;
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO bot_messages
            (channel, message_id, chat_id, sender_id, text, direction, phone, sender_name, timestamp_ms)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param(
        'ssssssssi',
        $channel,
        $messageId,
        $chatId,
        $senderId,
        $text,
        $direction,
        $phoneNorm,
        $senderName,
        $ts
    );
    $ok = $stmt->execute() && $stmt->affected_rows > 0;
    $stmt->close();
    return $ok;
}

/**
 * @return array{items:list<array>,total:int}
 */
function bots_list_messages(mysqli $conn, string $channel, int $sinceMs = 0, int $limit = 100): array
{
    bots_ensure_schema($conn);
    $limit = max(1, min(200, $limit));
    $items = [];
    if ($sinceMs > 0) {
        $stmt = $conn->prepare(
            'SELECT * FROM bot_messages WHERE channel=? AND timestamp_ms > ? ORDER BY timestamp_ms ASC LIMIT ?'
        );
        if ($stmt) {
            $stmt->bind_param('sii', $channel, $sinceMs, $limit);
            $stmt->execute();
            $res = method_exists($stmt, 'get_result') ? $stmt->get_result() : null;
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $items[] = bots_message_to_api($row);
                }
            }
            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare(
            'SELECT * FROM bot_messages WHERE channel=? ORDER BY timestamp_ms DESC LIMIT ?'
        );
        if ($stmt) {
            $stmt->bind_param('si', $channel, $limit);
            $stmt->execute();
            $res = method_exists($stmt, 'get_result') ? $stmt->get_result() : null;
            $tmp = [];
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $tmp[] = $row;
                }
            }
            $stmt->close();
            $tmp = array_reverse($tmp);
            foreach ($tmp as $row) {
                $items[] = bots_message_to_api($row);
            }
        }
    }
    return ['items' => $items, 'total' => count($items)];
}

function bots_message_to_api(array $row): array
{
    return [
        'messageId' => (string) ($row['message_id'] ?? ''),
        'chatId' => (string) ($row['chat_id'] ?? ''),
        'senderId' => $row['sender_id'] ?? null,
        'text' => (string) ($row['text'] ?? ''),
        'timestamp' => (int) ($row['timestamp_ms'] ?? 0),
        'direction' => (string) ($row['direction'] ?? 'incoming'),
        'phoneNumber' => $row['phone'] ?? null,
        'senderName' => $row['sender_name'] ?? null,
    ];
}

function bots_public_base_url(): string
{
    $fwd = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $https = ($fwd === 'https')
        || (strcasecmp((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''), 'on') === 0)
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Prefer /crm/api as deploy path
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (preg_match('#^(.*?/api)(?:/|$)#', $script, $m)) {
        return $scheme . '://' . $host . $m[1];
    }
    return $scheme . '://' . $host . '/crm/api';
}

function bots_webhook_url(mysqli $conn, string $channel): string
{
    $runtime = bots_get_runtime($conn, $channel);
    $secret = (string) ($runtime['webhook_secret'] ?? '');
    if ($secret === '') {
        $secret = bin2hex(random_bytes(16));
        bots_save_runtime($conn, $channel, ['webhook_secret' => $secret]);
    }
    $url = rtrim(bots_public_base_url(), '/') . '/webhooks/' . $channel . '.php?secret=' . urlencode($secret);
    // Rubika / Telegram / Bale all require HTTPS webhook URLs (common behind SSL proxy).
    if (stripos($url, 'http://') === 0) {
        $url = 'https://' . substr($url, strlen('http://'));
    }
    return $url;
}

function bots_extract_phone_from_text(string $text): ?string
{
    $digits = preg_replace('/\D/', '', $text) ?? '';
    if (preg_match('/0?9\d{9}/', $digits, $m)) {
        return comms_normalize_phone($m[0]);
    }
    if (preg_match('/98\d{10}/', $digits, $m)) {
        return comms_normalize_phone($m[0]);
    }
    return null;
}
