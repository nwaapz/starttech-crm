<?php
/**
 * Dashboard activity / recent-events log for remote CRM.
 */
declare(strict_types=1);

function crm_activity_ensure_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS crm_activity_events (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(64) NOT NULL,
            payload_json TEXT NOT NULL,
            at_ms BIGINT NOT NULL,
            username VARCHAR(100) DEFAULT NULL,
            INDEX idx_crm_activity_at (at_ms),
            INDEX idx_crm_activity_type_at (event_type, at_ms)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/**
 * @param array<string,mixed> $payload
 */
function crm_activity_record(
    mysqli $conn,
    string $type,
    array $payload = [],
    ?int $atMs = null,
    ?string $username = null
): void {
    crm_activity_ensure_table($conn);
    $type = trim($type);
    if ($type === '') {
        return;
    }
    $at = $atMs !== null && $atMs > 0 ? $atMs : (int) round(microtime(true) * 1000);
    $payload['at'] = $payload['at'] ?? $at;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = '{}';
    }
    $stmt = $conn->prepare(
        'INSERT INTO crm_activity_events (event_type, payload_json, at_ms, username) VALUES (?, ?, ?, ?)'
    );
    if (!$stmt) {
        return;
    }
    $userVal = ($username !== null && trim($username) !== '') ? trim($username) : '';
    $stmt->bind_param('ssis', $type, $json, $at, $userVal);
    $stmt->execute();
    $stmt->close();

    // Keep the feed bounded (same idea as LAN 7-day prune, but by row count).
    $conn->query(
        'DELETE FROM crm_activity_events
         WHERE id NOT IN (
            SELECT id FROM (
                SELECT id FROM crm_activity_events ORDER BY at_ms DESC, id DESC LIMIT 500
            ) t
         )'
    );
}

/**
 * Map a stored activity row to a SyncChange-shaped array for the Vue dashboard.
 * @return array<string,mixed>|null
 */
function crm_activity_to_change(string $type, array $payload, int $at): ?array
{
    $at = (int) ($payload['at'] ?? $at);
    switch ($type) {
        case 'registered':
        case 'registration_updated':
            return [
                'type' => $type,
                'serial' => isset($payload['serial']) ? (string) $payload['serial'] : null,
                'phone' => isset($payload['phone']) ? (string) $payload['phone'] : null,
                'date' => isset($payload['date']) ? (string) $payload['date'] : null,
                'city' => isset($payload['city']) ? (string) $payload['city'] : null,
                'km' => array_key_exists('km', $payload) && $payload['km'] !== null && $payload['km'] !== ''
                    ? (int) $payload['km']
                    : null,
                'at' => $at,
            ];
        case 'serials_added':
            return [
                'type' => 'serials_added',
                'inserted' => (int) ($payload['inserted'] ?? 0),
                'skipped' => (int) ($payload['skipped'] ?? 0),
                'total' => (int) ($payload['total'] ?? 0),
                'message' => isset($payload['message']) ? (string) $payload['message'] : null,
                'at' => $at,
            ];
        case 'serials_removed':
            return [
                'type' => 'serials_removed',
                'removed' => (int) ($payload['removed'] ?? $payload['deleted'] ?? 0),
                'groupId' => isset($payload['groupId']) ? (int) $payload['groupId'] : null,
                'message' => isset($payload['message']) ? (string) $payload['message'] : null,
                'at' => $at,
            ];
        case 'photo_approved':
        case 'photo_rejected':
            $serial = isset($payload['serial']) ? (string) $payload['serial'] : '';
            $msg = isset($payload['message']) ? (string) $payload['message'] : '';
            if ($msg === '') {
                $msg = ($type === 'photo_approved' ? 'تأیید عکس' : 'رد عکس')
                    . ($serial !== '' ? (' سریال ' . $serial) : '');
            }
            return [
                'type' => $type,
                'serial' => $serial !== '' ? $serial : null,
                'phone' => isset($payload['phone']) ? (string) $payload['phone'] : null,
                'message' => $msg,
                'at' => $at,
            ];
        case 'bot_connected':
        case 'bot_disconnected':
            $channel = isset($payload['channel']) ? (string) $payload['channel'] : '';
            $msg = isset($payload['message']) ? (string) $payload['message'] : '';
            if ($msg === '') {
                $msg = ($type === 'bot_connected' ? 'اتصال ربات' : 'قطع اتصال ربات')
                    . ($channel !== '' ? (' ' . $channel) : '');
            }
            return [
                'type' => $type,
                'key' => $channel !== '' ? $channel : null,
                'title' => isset($payload['title']) ? (string) $payload['title'] : null,
                'message' => $msg,
                'at' => $at,
            ];
        case 'settings_changed':
            return [
                'type' => 'settings_changed',
                'key' => isset($payload['key']) ? (string) $payload['key'] : null,
                'message' => isset($payload['message'])
                    ? (string) $payload['message']
                    : ('تغییر تنظیمات: ' . (string) ($payload['key'] ?? '')),
                'at' => $at,
            ];
        case 'club_auto_created':
        case 'club_auto_updated':
        case 'club_auto_deleted':
        case 'club_auto_run':
            $title = isset($payload['title']) ? (string) $payload['title'] : '';
            $defaults = [
                'club_auto_created' => 'ایجاد پیام خودکار باشگاه',
                'club_auto_updated' => 'ویرایش پیام خودکار باشگاه',
                'club_auto_deleted' => 'حذف پیام خودکار باشگاه',
                'club_auto_run' => 'اجرای پیام خودکار باشگاه',
            ];
            $msg = isset($payload['message']) ? (string) $payload['message'] : ($defaults[$type] ?? $type);
            if ($title !== '') {
                $msg .= ': ' . $title;
            }
            return [
                'type' => $type,
                'title' => $title !== '' ? $title : null,
                'ruleId' => isset($payload['ruleId']) ? (int) $payload['ruleId'] : null,
                'message' => $msg,
                'at' => $at,
            ];
        default:
            return [
                'type' => $type,
                'message' => isset($payload['message']) ? (string) $payload['message'] : $type,
                'at' => $at,
            ];
    }
}

/**
 * @return list<array<string,mixed>>
 */
function crm_activity_list_since(mysqli $conn, int $since, int $limit = 80): array
{
    crm_activity_ensure_table($conn);
    $limit = max(1, min(200, $limit));
    if ($since > 0) {
        $stmt = $conn->prepare(
            'SELECT event_type, payload_json, at_ms FROM crm_activity_events
             WHERE at_ms > ? ORDER BY at_ms DESC, id DESC LIMIT ?'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $since, $limit);
    } else {
        $stmt = $conn->prepare(
            'SELECT event_type, payload_json, at_ms FROM crm_activity_events
             ORDER BY at_ms DESC, id DESC LIMIT ?'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $limit);
    }
    $stmt->execute();
    $res = method_exists($stmt, 'get_result') ? $stmt->get_result() : null;
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                $payload = [];
            }
            $change = crm_activity_to_change(
                (string) $row['event_type'],
                $payload,
                (int) $row['at_ms']
            );
            if ($change !== null) {
                $out[] = $change;
            }
        }
    }
    $stmt->close();
    return $out;
}

/**
 * Merge activity log with derived registration events; newest first, capped.
 * @param list<array<string,mixed>> $activity
 * @param list<array<string,mixed>> $fromSerials
 * @return list<array<string,mixed>>
 */
function crm_activity_merge_changes(array $activity, array $fromSerials, int $limit = 50): array
{
    $seenReg = [];
    foreach ($activity as $c) {
        if (($c['type'] ?? '') === 'registered' && !empty($c['serial'])) {
            $seenReg[(string) $c['serial'] . '@' . (int) ($c['at'] ?? 0)] = true;
        }
    }
    $merged = $activity;
    foreach ($fromSerials as $c) {
        if (($c['type'] ?? '') !== 'registered') {
            continue;
        }
        $key = (string) ($c['serial'] ?? '') . '@' . (int) ($c['at'] ?? 0);
        if ($key === '@0' || isset($seenReg[$key])) {
            continue;
        }
        // Also skip if same serial already in activity (any at) for bootstrap noise reduction
        $serialOnly = (string) ($c['serial'] ?? '');
        $dup = false;
        if ($serialOnly !== '') {
            foreach ($activity as $a) {
                if (($a['type'] ?? '') === 'registered' && (string) ($a['serial'] ?? '') === $serialOnly) {
                    $dup = true;
                    break;
                }
            }
        }
        if ($dup) {
            continue;
        }
        $merged[] = $c;
        $seenReg[$key] = true;
    }
    usort($merged, static function ($a, $b) {
        return ((int) ($b['at'] ?? 0)) <=> ((int) ($a['at'] ?? 0));
    });
    return array_slice($merged, 0, $limit);
}

/** Human labels for settings keys (Persian). */
function crm_activity_settings_label(string $key): string
{
    $map = [
        'sms_processing' => 'پردازش پیامک',
        'sim_quota' => 'سهمیه سیم‌کارت',
        'sms_messages' => 'متن پیامک‌ها',
        'rubika_settings' => 'تنظیمات روبیکا',
        'telegram_settings' => 'تنظیمات تلگرام',
        'bale_settings' => 'تنظیمات بله',
        'referral_settings' => 'معرفی دوستان',
        'whatsapp_config' => 'واتساپ',
        'lottery_preset' => 'پیش‌فرض قرعه‌کشی',
        'device_sync' => 'همگام‌سازی دستگاه',
        'sync_config' => 'پیکربندی همگام‌سازی',
    ];
    return $map[$key] ?? $key;
}
