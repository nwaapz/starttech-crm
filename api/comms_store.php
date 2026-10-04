<?php
/**
 * Unified communications log for remote CRM (SMS + bots).
 */
declare(strict_types=1);

function comms_ensure_schema(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS communication_messages (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            phone VARCHAR(96) NOT NULL,
            direction VARCHAR(16) NOT NULL,
            body TEXT NOT NULL,
            timestamp_ms BIGINT NOT NULL,
            status VARCHAR(32) DEFAULT NULL,
            sim_label VARCHAR(64) DEFAULT NULL,
            channel VARCHAR(16) NOT NULL DEFAULT 'sms',
            INDEX idx_comms_ts (timestamp_ms),
            INDEX idx_comms_phone_ts (phone, timestamp_ms),
            INDEX idx_comms_channel (channel)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // Widen phone for unpaired bot keys (bot:channel:chatId).
    if (function_exists('column_exists') && column_exists($conn, 'communication_messages', 'phone')) {
        @$conn->query('ALTER TABLE communication_messages MODIFY phone VARCHAR(96) NOT NULL');
    }
}

function comms_normalize_phone(string $phone): string
{
    $phone = trim($phone);
    // Synthetic keys for unpaired bot chats: bot:{channel}:{chatId}
    if (strpos($phone, 'bot:') === 0) {
        return $phone;
    }
    $digits = preg_replace('/\D/', '', $phone) ?? '';
    if ($digits !== '' && strpos($digits, '98') === 0 && strlen($digits) >= 12) {
        $digits = '0' . substr($digits, 2);
    }
    if ($digits !== '' && strlen($digits) === 10 && isset($digits[0]) && $digits[0] === '9') {
        $digits = '0' . $digits;
    }
    return $digits;
}

/** Phone key for communications when real mobile may be unknown. */
function comms_phone_key(?string $phone, string $channel = 'sms', string $chatId = ''): string
{
    $normalized = $phone !== null && $phone !== '' ? comms_normalize_phone($phone) : '';
    if ($normalized !== '' && strpos($normalized, 'bot:') !== 0) {
        return $normalized;
    }
    if ($chatId !== '' && in_array($channel, ['rubika', 'telegram', 'bale'], true)) {
        return 'bot:' . $channel . ':' . $chatId;
    }
    return $normalized;
}

function comms_channel_label(string $channel): string
{
    switch ($channel) {
        case 'rubika':
            return 'روبیکا';
        case 'telegram':
            return 'تلگرام';
        case 'bale':
            return 'بله';
        default:
            return 'پیامک';
    }
}

function comms_insert(
    mysqli $conn,
    string $phone,
    string $direction,
    string $body,
    string $channel = 'sms',
    ?string $status = 'sent',
    ?int $timestampMs = null,
    ?string $simLabel = null
): int {
    comms_ensure_schema($conn);
    $phone = comms_normalize_phone($phone);
    if ($phone === '' || trim($body) === '') {
        return 0;
    }
    $channel = strtolower(trim($channel));
    if (!in_array($channel, ['sms', 'rubika', 'telegram', 'bale'], true)) {
        $channel = 'sms';
    }
    $direction = $direction === 'incoming' ? 'incoming' : 'outgoing';
    $ts = $timestampMs ?? (int) round(microtime(true) * 1000);
    $label = $simLabel ?? comms_channel_label($channel);
    $stmt = $conn->prepare(
        'INSERT INTO communication_messages (phone, direction, body, timestamp_ms, status, sim_label, channel)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('sssisss', $phone, $direction, $body, $ts, $status, $label, $channel);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function comms_row_to_api(array $row): array
{
    return [
        'id' => (int) ($row['id'] ?? 0),
        'phone' => (string) ($row['phone'] ?? ''),
        'direction' => (string) ($row['direction'] ?? 'outgoing'),
        'body' => (string) ($row['body'] ?? ''),
        'timestamp' => (int) ($row['timestamp_ms'] ?? 0),
        'status' => $row['status'] ?? null,
        'simLabel' => $row['sim_label'] ?? null,
        'channel' => (string) ($row['channel'] ?? 'sms'),
    ];
}

/**
 * @return array{items:list<array>,total:int,page:int,limit:int}
 */
function comms_list(
    mysqli $conn,
    int $page,
    int $limit,
    string $direction = '',
    string $phonePrefix = '',
    string $channel = ''
): array {
    comms_ensure_schema($conn);
    $page = max(1, $page);
    $limit = max(1, min(100, $limit));
    $offset = ($page - 1) * $limit;
    $where = ['1=1'];
    $types = '';
    $params = [];
    if ($direction === 'incoming' || $direction === 'outgoing') {
        $where[] = 'direction = ?';
        $types .= 's';
        $params[] = $direction;
    }
    $channel = strtolower(trim($channel));
    if (in_array($channel, ['sms', 'rubika', 'telegram', 'bale'], true)) {
        $where[] = 'channel = ?';
        $types .= 's';
        $params[] = $channel;
    }
    $phonePrefix = trim($phonePrefix);
    if ($phonePrefix !== '' && strpos($phonePrefix, 'bot:') === 0) {
        $where[] = 'phone LIKE ?';
        $types .= 's';
        $params[] = $phonePrefix . '%';
    } else {
        $phonePrefix = comms_normalize_phone($phonePrefix);
        if ($phonePrefix !== '') {
            $where[] = 'phone LIKE ?';
            $types .= 's';
            $params[] = $phonePrefix . '%';
        }
    }
    $whereSql = implode(' AND ', $where);
    $total = 0;
    $sqlCount = "SELECT COUNT(*) AS c FROM communication_messages WHERE $whereSql";
    if ($types !== '') {
        $stmt = $conn->prepare($sqlCount);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $row = stmt_fetch_assoc($stmt);
            $stmt->close();
            $total = (int) ($row['c'] ?? 0);
        }
    } else {
        $res = $conn->query($sqlCount);
        if ($res && ($row = $res->fetch_assoc())) {
            $total = (int) ($row['c'] ?? 0);
        }
    }

    $items = [];
    $sql = "SELECT * FROM communication_messages WHERE $whereSql ORDER BY timestamp_ms DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $bindTypes = $types . 'ii';
        $bindParams = array_merge($params, [$limit, $offset]);
        $stmt->bind_param($bindTypes, ...$bindParams);
        $stmt->execute();
        foreach (stmt_fetch_all_assoc($stmt) as $row) {
            $items[] = comms_row_to_api($row);
        }
        $stmt->close();
    }
    return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
}

/**
 * @return array{phone:string,items:list<array>,hasMore:bool}
 */
function comms_thread(mysqli $conn, string $phone, ?int $before, int $limit): array
{
    comms_ensure_schema($conn);
    $phone = comms_normalize_phone($phone);
    $limit = max(1, min(100, $limit));
    $items = [];
    if ($before !== null && $before > 0) {
        $stmt = $conn->prepare(
            'SELECT * FROM communication_messages WHERE phone = ? AND timestamp_ms < ?
             ORDER BY timestamp_ms DESC LIMIT ?'
        );
        if ($stmt) {
            $stmt->bind_param('sii', $phone, $before, $limit);
            $stmt->execute();
            $items = stmt_fetch_all_assoc($stmt);
            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare(
            'SELECT * FROM communication_messages WHERE phone = ?
             ORDER BY timestamp_ms DESC LIMIT ?'
        );
        if ($stmt) {
            $stmt->bind_param('si', $phone, $limit);
            $stmt->execute();
            $items = stmt_fetch_all_assoc($stmt);
            $stmt->close();
        }
    }
    $items = array_reverse($items);
    $hasMore = false;
    if ($items !== []) {
        $oldest = (int) ($items[0]['timestamp_ms'] ?? 0);
        $stmt = $conn->prepare(
            'SELECT COUNT(*) AS c FROM communication_messages WHERE phone = ? AND timestamp_ms < ?'
        );
        if ($stmt) {
            $stmt->bind_param('si', $phone, $oldest);
            $stmt->execute();
            $row = stmt_fetch_assoc($stmt);
            $stmt->close();
            $hasMore = ((int) ($row['c'] ?? 0)) > 0;
        }
    }
    return [
        'phone' => $phone,
        'items' => array_map('comms_row_to_api', $items),
        'hasMore' => $hasMore,
    ];
}
