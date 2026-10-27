<?php
/**
 * Walk-back announcement SMS: distinct registered phones, newest Jalali day first
 * from a ceiling date, daily Melipayamak cap, per-phone ledger.
 */
declare(strict_types=1);

function crm_ann_ensure_schema(mysqli $conn): void
{
    $ok = $conn->query(
        "CREATE TABLE IF NOT EXISTS club_announcements (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL DEFAULT '',
            message TEXT NOT NULL,
            ceiling_date_jalali VARCHAR(16) NOT NULL,
            cursor_date_jalali VARCHAR(16) NOT NULL,
            daily_cap INT NOT NULL DEFAULT 300,
            delay_seconds INT NOT NULL DEFAULT 2,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            status VARCHAR(16) NOT NULL DEFAULT 'idle',
            last_run_at BIGINT DEFAULT NULL,
            last_run_date_jalali VARCHAR(16) DEFAULT NULL,
            last_success_count INT NOT NULL DEFAULT 0,
            last_fail_count INT NOT NULL DEFAULT 0,
            sent_count INT NOT NULL DEFAULT 0,
            fail_count INT NOT NULL DEFAULT 0,
            eligible_count INT NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL DEFAULT 0,
            updated_at BIGINT NOT NULL DEFAULT 0,
            INDEX idx_ann_enabled_status (enabled, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if ($ok === false) {
        json_error('DB schema error (club_announcements): ' . $conn->error, 500);
    }
    $ok2 = $conn->query(
        "CREATE TABLE IF NOT EXISTS club_announcement_sends (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            announcement_id BIGINT NOT NULL,
            phone VARCHAR(32) NOT NULL,
            serial VARCHAR(64) NOT NULL DEFAULT '',
            sent_at BIGINT NOT NULL,
            sent_date_jalali VARCHAR(16) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'sent',
            UNIQUE KEY uq_ann_phone (announcement_id, phone),
            INDEX idx_ann_send_day (announcement_id, sent_date_jalali)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if ($ok2 === false) {
        json_error('DB schema error (club_announcement_sends): ' . $conn->error, 500);
    }
}

function crm_ann_prev_jalali(string $date): ?string
{
    $parsed = parse_jalali_date($date);
    if ($parsed === null) {
        return null;
    }
    [$y, $m, $d] = $parsed;
    $d--;
    if ($d < 1) {
        $m--;
        if ($m < 1) {
            $m = 12;
            $y--;
        }
        $d = jalali_days_in_month($y, $m);
    }
    return format_jalali($y, $m, $d);
}

function crm_ann_norm_phone(string $phone): string
{
    if (function_exists('comms_normalize_phone')) {
        return comms_normalize_phone($phone);
    }
    $digits = preg_replace('/\D/', '', trim($phone)) ?? '';
    if ($digits !== '' && strpos($digits, '98') === 0 && strlen($digits) >= 12) {
        $digits = '0' . substr($digits, 2);
    }
    if ($digits !== '' && strlen($digits) === 10 && isset($digits[0]) && $digits[0] === '9') {
        $digits = '0' . $digits;
    }
    return $digits;
}

function crm_ann_row_day(array $row): string
{
    $jalali = '';
    if (function_exists('serial_date_jalali')) {
        $jalali = (string) (serial_date_jalali($row) ?? '');
    }
    if ($jalali === '' && !empty($row['date_jalali'])) {
        $jalali = trim((string) $row['date_jalali']);
    }
    if (strlen($jalali) >= 10) {
        return substr($jalali, 0, 10);
    }
    return '';
}

/**
 * Distinct phones whose latest registration Jalali day is on or before $ceiling.
 * @return array<string, array{phone:string,serial:string,km:string,date:string}>
 */
function crm_ann_latest_by_phone(mysqli $conn, string $ceiling): array
{
    $table = serials_table($conn);
    $sql = 'SELECT serial, phone, km'
        . (column_exists($conn, $table, 'date_jalali') ? ', date_jalali' : '')
        . (column_exists($conn, $table, 'time') ? ', time' : '')
        . " FROM `$table` WHERE " . is_registered_sql();
    $res = $conn->query($sql);
    $byPhone = [];
    if (!$res) {
        return [];
    }
    while ($row = $res->fetch_assoc()) {
        $phone = crm_ann_norm_phone((string) ($row['phone'] ?? ''));
        if ($phone === '' || !preg_match('/^09\d{9}$/', $phone)) {
            continue;
        }
        $day = crm_ann_row_day($row);
        if ($day === '' || $day > $ceiling) {
            continue;
        }
        $prev = $byPhone[$phone] ?? null;
        if ($prev === null || strcmp($day, (string) $prev['date']) > 0) {
            $km = '';
            if (isset($row['km']) && $row['km'] !== null && $row['km'] !== '') {
                $km = (string) (int) $row['km'];
            }
            $byPhone[$phone] = [
                'phone' => $phone,
                'serial' => (string) ($row['serial'] ?? ''),
                'km' => $km,
                'date' => $day,
            ];
        }
    }
    return $byPhone;
}

function crm_ann_count_sent_today(mysqli $conn, int $id): int
{
    $today = today_jalali_date();
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS c FROM club_announcement_sends
         WHERE announcement_id = ? AND sent_date_jalali = ? AND status = \'sent\''
    );
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('is', $id, $today);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return (int) ($row['c'] ?? 0);
}

/** @return array<string, true> */
function crm_ann_sent_phones(mysqli $conn, int $id): array
{
    $out = [];
    $stmt = $conn->prepare(
        'SELECT phone FROM club_announcement_sends WHERE announcement_id = ?'
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $phone = (string) ($row['phone'] ?? '');
            if ($phone !== '') {
                $out[$phone] = true;
            }
        }
    }
    $stmt->close();
    return $out;
}

function crm_ann_already_sent(mysqli $conn, int $id, string $phone): bool
{
    $stmt = $conn->prepare(
        'SELECT id FROM club_announcement_sends WHERE announcement_id = ? AND phone = ? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('is', $id, $phone);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->fetch_assoc();
    $stmt->close();
    return (bool) $ok;
}

function crm_ann_record_send(
    mysqli $conn,
    int $id,
    string $phone,
    string $serial,
    string $status
): void {
    $now = (int) round(microtime(true) * 1000);
    $today = today_jalali_date();
    $stmt = $conn->prepare(
        'INSERT INTO club_announcement_sends
            (announcement_id, phone, serial, sent_at, sent_date_jalali, status)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE sent_at = VALUES(sent_at), status = VALUES(status),
            serial = VALUES(serial), sent_date_jalali = VALUES(sent_date_jalali)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ississ', $id, $phone, $serial, $now, $today, $status);
    $stmt->execute();
    $stmt->close();
}

function crm_ann_to_api(array $row): array
{
    $dailyCap = max(1, min(10000, (int) ($row['daily_cap'] ?? 300)));
    $sent = (int) ($row['sent_count'] ?? 0);
    $failed = (int) ($row['fail_count'] ?? 0);
    $eligible = (int) ($row['eligible_count'] ?? 0);
    $remaining = max(0, $eligible - $sent - $failed);
    $etaDays = $dailyCap > 0 ? (int) ceil($remaining / $dailyCap) : 0;
    return [
        'id' => (int) ($row['id'] ?? 0),
        'title' => (string) ($row['title'] ?? ''),
        'message' => (string) ($row['message'] ?? ''),
        'ceilingDateJalali' => (string) ($row['ceiling_date_jalali'] ?? ''),
        'cursorDateJalali' => (string) ($row['cursor_date_jalali'] ?? ''),
        'dailyCap' => $dailyCap,
        'delaySeconds' => max(0, (int) ($row['delay_seconds'] ?? 0)),
        'enabled' => !empty($row['enabled']),
        'status' => (string) ($row['status'] ?? 'idle'),
        'lastRunAt' => isset($row['last_run_at']) && $row['last_run_at'] !== null ? (int) $row['last_run_at'] : null,
        'lastRunDateJalali' => $row['last_run_date_jalali'] ?? null,
        'lastSuccessCount' => (int) ($row['last_success_count'] ?? 0),
        'lastFailCount' => (int) ($row['last_fail_count'] ?? 0),
        'sentCount' => $sent,
        'failCount' => (int) ($row['fail_count'] ?? 0),
        'eligibleCount' => $eligible,
        'remainingCount' => $remaining,
        'etaDays' => $etaDays,
        'createdAt' => (int) ($row['created_at'] ?? 0),
        'updatedAt' => (int) ($row['updated_at'] ?? 0),
    ];
}

/**
 * @return array{ok:bool,sent:int,failed:int,skipped:int,message?:string}
 */
function crm_ann_run_one(mysqli $conn, array $ann, bool $force = false): array
{
    crm_ann_ensure_schema($conn);
    require_once __DIR__ . '/comms_store.php';
    require_once __DIR__ . '/club_auto_runner.php';
    date_default_timezone_set('Asia/Tehran');
    @set_time_limit(0);

    $id = (int) ($ann['id'] ?? 0);
    if ($id <= 0) {
        return ['ok' => false, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'message' => 'invalid'];
    }
    $status = (string) ($ann['status'] ?? 'idle');
    if ($status === 'completed') {
        return ['ok' => true, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'message' => 'completed'];
    }
    if (empty($ann['enabled']) && !$force) {
        return ['ok' => true, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'message' => 'disabled'];
    }

    $ceiling = normalize_jalali_date_param((string) ($ann['ceiling_date_jalali'] ?? ''));
    $cursor = normalize_jalali_date_param((string) ($ann['cursor_date_jalali'] ?? ''));
    if ($ceiling === '') {
        return ['ok' => false, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'message' => 'bad ceiling'];
    }
    if ($cursor === '' || $cursor > $ceiling) {
        $cursor = $ceiling;
    }

    $dailyCap = max(1, min(10000, (int) ($ann['daily_cap'] ?? 300)));
    $sentToday = crm_ann_count_sent_today($conn, $id);
    $remainingCap = max(0, $dailyCap - $sentToday);
    if ($remainingCap <= 0) {
        return [
            'ok' => true,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'message' => 'daily cap reached',
        ];
    }

    $byPhone = crm_ann_latest_by_phone($conn, $ceiling);
    $eligible = count($byPhone);
    $ledger = crm_ann_sent_phones($conn, $id);
    $unsent = [];
    foreach ($byPhone as $phone => $reg) {
        if (!isset($ledger[$phone])) {
            $unsent[$phone] = $reg;
        }
    }
    if ($unsent === []) {
        crm_ann_update_progress($conn, $id, [
            'status' => 'completed',
            'eligible_count' => $eligible,
            'cursor_date_jalali' => $cursor,
        ]);
        return ['ok' => true, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'message' => 'completed'];
    }

    $nowMs = (int) round(microtime(true) * 1000);
    $conn->query(
        "UPDATE club_announcements SET status = 'running', updated_at = " . $nowMs
        . ', eligible_count = ' . (int) $eligible
        . ' WHERE id = ' . $id
    );

    $template = (string) ($ann['message'] ?? '');
    $delay = max(0, (int) ($ann['delay_seconds'] ?? 0));
    $sent = 0;
    $failed = 0;
    $skipped = 0;
    while ($remainingCap > 0 && $cursor !== null && $cursor !== '') {
        $dayBatch = [];
        foreach ($unsent as $phone => $reg) {
            if ((string) $reg['date'] === $cursor) {
                $dayBatch[$phone] = $reg;
            }
        }
        if ($dayBatch === []) {
            $next = null;
            foreach ($unsent as $reg) {
                $day = (string) $reg['date'];
                if ($day !== '' && $day < $cursor && ($next === null || $day > $next)) {
                    $next = $day;
                }
            }
            if ($next === null) {
                break;
            }
            $cursor = $next;
            continue;
        }

        foreach ($dayBatch as $phone => $reg) {
            if ($remainingCap <= 0) {
                break 2;
            }
            unset($unsent[$phone]);
            if (crm_ann_already_sent($conn, $id, $phone)) {
                $skipped++;
                continue;
            }
            $body = crm_club_apply_placeholders($template, $reg);
            if (trim($body) === '') {
                $skipped++;
                continue;
            }
            $result = crm_send_panel_sms($phone, $body);
            if ($result === true) {
                crm_ann_record_send($conn, $id, $phone, (string) $reg['serial'], 'sent');
                comms_insert($conn, $phone, 'outgoing', $body, 'sms', 'sent');
                $sent++;
                $remainingCap--;
            } else {
                crm_ann_record_send($conn, $id, $phone, (string) $reg['serial'], 'failed');
                comms_insert($conn, $phone, 'outgoing', $body, 'sms', 'failed');
                $failed++;
                error_log('[announcement] sms fail id=' . $id . ' phone=' . $phone . ' err=' . $result);
            }
            if ($delay > 0) {
                sleep($delay);
            }
        }

        if ($remainingCap <= 0) {
            break;
        }
        $stillOnDay = false;
        foreach ($unsent as $reg) {
            if ((string) $reg['date'] === $cursor) {
                $stillOnDay = true;
                break;
            }
        }
        if (!$stillOnDay) {
            $prev = crm_ann_prev_jalali($cursor);
            if ($prev === null) {
                break;
            }
            $cursor = $prev;
        }
    }

    $left = 0;
    foreach ($unsent as $_ignored) {
        $left++;
    }
    $finalStatus = $left <= 0 ? 'completed' : (empty($ann['enabled']) ? 'paused' : 'idle');
    crm_ann_update_progress($conn, $id, [
        'status' => $finalStatus,
        'cursor_date_jalali' => $cursor ?: $ceiling,
        'eligible_count' => $eligible,
        'last_success_count' => $sent,
        'last_fail_count' => $failed,
        'bump_sent' => $sent,
        'bump_fail' => $failed,
    ]);

    return [
        'ok' => true,
        'sent' => $sent,
        'failed' => $failed,
        'skipped' => $skipped,
        'message' => $finalStatus,
        'cursor' => $cursor,
        'eligible' => $eligible,
    ];
}

function crm_ann_update_progress(mysqli $conn, int $id, array $fields): void
{
    $now = (int) round(microtime(true) * 1000);
    $today = today_jalali_date();
    $status = $conn->real_escape_string((string) ($fields['status'] ?? 'idle'));
    $cursor = $conn->real_escape_string((string) ($fields['cursor_date_jalali'] ?? ''));
    $eligible = (int) ($fields['eligible_count'] ?? 0);
    $lastOk = (int) ($fields['last_success_count'] ?? 0);
    $lastFail = (int) ($fields['last_fail_count'] ?? 0);
    $bumpSent = (int) ($fields['bump_sent'] ?? 0);
    $bumpFail = (int) ($fields['bump_fail'] ?? 0);
    $sql = "UPDATE club_announcements SET
        status = '$status',
        cursor_date_jalali = '$cursor',
        eligible_count = $eligible,
        last_run_at = $now,
        last_run_date_jalali = '$today',
        last_success_count = $lastOk,
        last_fail_count = $lastFail,
        sent_count = COALESCE(sent_count,0) + $bumpSent,
        fail_count = COALESCE(fail_count,0) + $bumpFail,
        updated_at = $now
        WHERE id = " . (int) $id;
    $conn->query($sql);
}

function crm_ann_tick_all(mysqli $conn): array
{
    crm_ann_ensure_schema($conn);
    $res = $conn->query(
        "SELECT * FROM club_announcements
         WHERE enabled = 1 AND status <> 'completed'
         ORDER BY id ASC"
    );
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[] = array_merge(['announcementId' => (int) $row['id']], crm_ann_run_one($conn, $row, false));
        }
    }
    return $out;
}
