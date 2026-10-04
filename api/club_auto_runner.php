<?php
/**
 * Remote club / warranty auto-message runner.
 * Sends SMS via Melipayamak (crm_send_panel_sms) — independent of the Android SMS pipeline.
 *
 * Ledger is per (rule, serial, channel). Each run sends at most daily_cap distinct serials.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function crm_club_ensure_sends_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS club_auto_message_sends (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            rule_id BIGINT NOT NULL,
            phone VARCHAR(32) NOT NULL,
            serial VARCHAR(64) NOT NULL DEFAULT '',
            channel VARCHAR(16) NOT NULL DEFAULT 'sms',
            sent_at BIGINT NOT NULL,
            sent_date_jalali VARCHAR(16) NOT NULL,
            UNIQUE KEY uq_club_send_serial (rule_id, serial, channel),
            INDEX idx_club_send_rule_at (rule_id, sent_at),
            INDEX idx_club_send_rule_day (rule_id, sent_date_jalali)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if (!column_exists($conn, 'club_auto_message_sends', 'serial')) {
        @$conn->query('ALTER TABLE club_auto_message_sends ADD COLUMN serial VARCHAR(64) NOT NULL DEFAULT \'\' AFTER phone');
    }
    // Migrate legacy phone-only unique key → serial-based.
    $idx = $conn->query("SHOW INDEX FROM club_auto_message_sends WHERE Key_name = 'uq_club_send'");
    if ($idx && $idx->num_rows > 0) {
        @$conn->query(
            "UPDATE club_auto_message_sends
             SET serial = CONCAT('__phone__', phone)
             WHERE serial = '' OR serial IS NULL"
        );
        @$conn->query('ALTER TABLE club_auto_message_sends DROP INDEX uq_club_send');
    }
    $idx2 = $conn->query("SHOW INDEX FROM club_auto_message_sends WHERE Key_name = 'uq_club_send_serial'");
    if (!$idx2 || $idx2->num_rows === 0) {
        @$conn->query(
            'ALTER TABLE club_auto_message_sends ADD UNIQUE KEY uq_club_send_serial (rule_id, serial, channel)'
        );
    }
}

function crm_club_ensure_daily_cap_column(mysqli $conn): void
{
    if (table_exists($conn, 'club_auto_message_rules')
        && !column_exists($conn, 'club_auto_message_rules', 'daily_cap')
    ) {
        @$conn->query(
            'ALTER TABLE club_auto_message_rules ADD COLUMN daily_cap INT NOT NULL DEFAULT 200 AFTER delay_seconds'
        );
    }
}

/** Cutoff Jalali YYYY/MM/DD = today − months − days (days may be negative). */
function crm_club_jalali_cutoff(int $offsetMonths, int $offsetDays): string
{
    $dt = new DateTime('today', new DateTimeZone('Asia/Tehran'));
    if ($offsetMonths !== 0) {
        $dt->modify(($offsetMonths > 0 ? '-' : '+') . abs($offsetMonths) . ' months');
    }
    if ($offsetDays !== 0) {
        $dt->modify(($offsetDays > 0 ? '-' : '+') . abs($offsetDays) . ' days');
    }
    $parts = jalali_from_gregorian(
        (int) $dt->format('Y'),
        (int) $dt->format('n'),
        (int) $dt->format('j')
    );
    return format_jalali($parts[0], $parts[1], $parts[2]);
}

function crm_club_normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D/', '', trim($phone)) ?? '';
    if ($digits !== '' && strpos($digits, '98') === 0 && strlen($digits) >= 12) {
        $digits = '0' . substr($digits, 2);
    }
    if ($digits !== '' && strlen($digits) === 10 && isset($digits[0]) && $digits[0] === '9') {
        $digits = '0' . $digits;
    }
    return $digits;
}

/**
 * @return list<int>
 */
function crm_club_rule_group_ids(array $rule): array
{
    $raw = $rule['serial_group_ids'] ?? '';
    if ($raw === '' || $raw === null) {
        return [];
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $ids = [];
    foreach ($decoded as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

/**
 * Eligible registrations as one row per serial (oldest first).
 * For auto_source=warranty_pre: registration date must equal the cutoff day exactly
 * (so "X days remaining" is never sent after that reminder day has passed).
 * For all other rules: registration on or before the cutoff (catch-up).
 *
 * @return list<array{phone:string,serial:string,km:?string,date:?string}>
 */
function crm_club_load_eligible_registrations(mysqli $conn, array $rule, string $cutoffJalali): array
{
    $table = serials_table($conn);
    $groupIds = crm_club_rule_group_ids($rule);
    $where = [is_registered_sql()];
    $types = '';
    $params = [];
    $exactDayOnly = (($rule['auto_source'] ?? '') === 'warranty_pre');

    if ($groupIds !== []) {
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        if (column_exists($conn, $table, 'lan_group_id')) {
            $where[] = "lan_group_id IN ($placeholders)";
            $types .= str_repeat('i', count($groupIds));
            foreach ($groupIds as $gid) {
                $params[] = $gid;
            }
        } else {
            return [];
        }
    } else {
        $cat = trim((string) ($rule['serial_category'] ?? ''));
        if ($cat !== 'end_user_client' && $cat !== 'seller_to_end_user') {
            $cat = 'end_user_client';
        }
        if (column_exists($conn, $table, 'category')) {
            $where[] = 'category = ?';
            $types .= 's';
            $params[] = $cat;
        }
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT serial, phone, km, time"
        . (column_exists($conn, $table, 'date_jalali') ? ', date_jalali' : '')
        . " FROM `$table` WHERE $whereSql";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = method_exists($stmt, 'get_result') ? $stmt->get_result() : null;

    $cutoffDay = substr(trim($cutoffJalali), 0, 10);
    $appendEligible = static function (array $row) use (&$items, $exactDayOnly, $cutoffDay): void {
        $phone = crm_club_normalize_phone((string) ($row['phone'] ?? ''));
        $serial = strtoupper(trim((string) ($row['serial'] ?? '')));
        if ($phone === '' || $serial === '') {
            return;
        }
        $regDate = serial_date_jalali($row);
        if ($regDate === null) {
            return;
        }
        $regDay = substr(trim($regDate), 0, 10);
        if ($exactDayOnly) {
            if ($regDay !== $cutoffDay) {
                return;
            }
        } elseif ($regDay > $cutoffDay) {
            return;
        }
        $km = null;
        if (isset($row['km']) && $row['km'] !== null && $row['km'] !== '') {
            $km = (string) (int) $row['km'];
        }
        $items[] = [
            'phone' => $phone,
            'serial' => $serial,
            'km' => $km,
            'date' => $regDate,
            '_sort' => $regDay,
        ];
    };

    $items = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $appendEligible($row);
        }
    } else {
        foreach (stmt_fetch_all_assoc($stmt) as $row) {
            $appendEligible($row);
        }
    }
    $stmt->close();

    // One row per serial (oldest registration first).
    $bySerial = [];
    foreach ($items as $it) {
        $serial = (string) $it['serial'];
        if ($serial === '') {
            continue;
        }
        if (!isset($bySerial[$serial]) || strcmp((string) $it['_sort'], (string) $bySerial[$serial]['_sort']) < 0) {
            $bySerial[$serial] = $it;
        }
    }
    $items = array_values($bySerial);
    usort($items, static function ($a, $b) {
        return strcmp((string) $a['_sort'], (string) $b['_sort']);
    });
    foreach ($items as &$it) {
        unset($it['_sort']);
    }
    unset($it);
    return $items;
}

function crm_club_apply_placeholders(string $template, array $reg): string
{
    $text = $template;
    $serial = (string) ($reg['serial'] ?? '');
    $km = (string) ($reg['km'] ?? '');
    $date = (string) ($reg['date'] ?? '');
    foreach (['{سریال}', '{serial}'] as $p) {
        $text = str_replace($p, $serial, $text);
    }
    foreach (['{کیلومتر}', '{km}'] as $p) {
        $text = str_replace($p, $km, $text);
    }
    foreach (['{تاریخ}', '{date}'] as $p) {
        $text = str_replace($p, $date, $text);
    }
    return $text;
}

function crm_club_already_sent_serial(
    mysqli $conn,
    int $ruleId,
    string $serial,
    string $channel = 'sms'
): bool {
    $serial = strtoupper(trim($serial));
    if ($serial === '') {
        return false;
    }
    $stmt = $conn->prepare(
        'SELECT id FROM club_auto_message_sends WHERE rule_id=? AND serial=? AND channel=? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iss', $ruleId, $serial, $channel);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    $stmt->close();
    return $row !== null;
}

/** @deprecated phone-only check kept for older callers */
function crm_club_already_sent(mysqli $conn, int $ruleId, string $phone, string $channel = 'sms'): bool
{
    $stmt = $conn->prepare(
        'SELECT id FROM club_auto_message_sends WHERE rule_id=? AND phone=? AND channel=? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iss', $ruleId, $phone, $channel);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    $stmt->close();
    return $row !== null;
}

function crm_club_record_send(
    mysqli $conn,
    int $ruleId,
    string $phone,
    string $channel = 'sms',
    string $serial = ''
): void {
    $now = (int) round(microtime(true) * 1000);
    $day = today_jalali_date();
    $serial = strtoupper(trim($serial));
    if ($serial === '') {
        $serial = '__phone__' . crm_club_normalize_phone($phone);
    }
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO club_auto_message_sends (rule_id, phone, serial, channel, sent_at, sent_date_jalali)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('isssis', $ruleId, $phone, $serial, $channel, $now, $day);
    $stmt->execute();
    $stmt->close();
}

/** Distinct serials alarmed today for this rule (any channel). */
function crm_club_serials_sent_today(mysqli $conn, int $ruleId): int
{
    $day = today_jalali_date();
    $stmt = $conn->prepare(
        "SELECT COUNT(DISTINCT serial) AS c FROM club_auto_message_sends
         WHERE rule_id=? AND sent_date_jalali=? AND serial NOT LIKE '__phone__%'"
    );
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('is', $ruleId, $day);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    $stmt->close();
    return (int) ($row['c'] ?? 0);
}

function crm_club_past_send_time(array $rule): bool
{
    $tz = new DateTimeZone('Asia/Tehran');
    $now = new DateTime('now', $tz);
    $hour = (int) ($rule['send_hour'] ?? 10);
    $minute = (int) ($rule['send_minute'] ?? 0);
    $target = clone $now;
    $target->setTime($hour, $minute, 0);
    return $now >= $target;
}

function crm_club_rule_daily_cap(array $rule): int
{
    $cap = (int) ($rule['daily_cap'] ?? 200);
    if ($cap < 1) {
        $cap = 1;
    }
    if ($cap > 10000) {
        $cap = 10000;
    }
    return $cap;
}

/**
 * True if serial still needs at least one enabled channel send.
 */
function crm_club_serial_needs_any_channel(
    mysqli $conn,
    int $ruleId,
    string $serial,
    bool $sendSms,
    bool $sendRubika,
    bool $sendTelegram,
    bool $sendBale
): bool {
    $channels = [];
    if ($sendSms) {
        $channels[] = 'sms';
    }
    if ($sendRubika) {
        $channels[] = 'rubika';
    }
    if ($sendTelegram) {
        $channels[] = 'telegram';
    }
    if ($sendBale) {
        $channels[] = 'bale';
    }
    foreach ($channels as $ch) {
        if (!crm_club_already_sent_serial($conn, $ruleId, $serial, $ch)) {
            return true;
        }
    }
    return false;
}

/**
 * @return array{ok:bool,sent:int,failed:int,skipped:int,eligible:int,capped?:int,message?:string}
 */
function crm_club_run_rule(mysqli $conn, array $rule, bool $ignoreTimeGate = false): array
{
    crm_club_ensure_sends_table($conn);
    crm_club_ensure_daily_cap_column($conn);
    date_default_timezone_set('Asia/Tehran');

    $ruleId = (int) ($rule['id'] ?? 0);
    if ($ruleId <= 0) {
        return ['ok' => false, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'eligible' => 0, 'message' => 'Invalid rule'];
    }
    if (empty($rule['enabled']) && !$ignoreTimeGate) {
        return ['ok' => true, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'eligible' => 0, 'message' => 'disabled'];
    }
    $sendSms = !empty($rule['send_sms']);
    $sendRubika = !empty($rule['send_rubika']);
    $sendTelegram = !empty($rule['send_telegram']);
    $sendBale = !empty($rule['send_bale']);
    if (!$sendSms && !$sendRubika && !$sendTelegram && !$sendBale) {
        return ['ok' => true, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'eligible' => 0, 'message' => 'no channels'];
    }

    $offsetMonths = (int) ($rule['offset_months'] ?? 0);
    $offsetDays = (int) ($rule['offset_days'] ?? 0);
    if ($offsetMonths <= 0 && $offsetDays === 0) {
        return ['ok' => false, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'eligible' => 0, 'message' => 'bad offset'];
    }

    $dailyCap = crm_club_rule_daily_cap($rule);
    $sentToday = crm_club_serials_sent_today($conn, $ruleId);
    $remainingCap = max(0, $dailyCap - $sentToday);

    if (!$ignoreTimeGate) {
        if (!crm_club_past_send_time($rule)) {
            return ['ok' => true, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'eligible' => 0, 'message' => 'before send time'];
        }
        if ($remainingCap <= 0) {
            return [
                'ok' => true,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'eligible' => 0,
                'capped' => $dailyCap,
                'message' => 'daily cap reached',
            ];
        }
        // One scheduled pass per Jalali day; remainingCap still limits the batch size.
        $todayGate = today_jalali_date();
        if (($rule['last_run_date_jalali'] ?? '') === $todayGate) {
            return [
                'ok' => true,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'eligible' => 0,
                'capped' => $dailyCap,
                'message' => 'already ran today',
            ];
        }
    } elseif ($remainingCap <= 0) {
        return [
            'ok' => true,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'eligible' => 0,
            'capped' => $dailyCap,
            'message' => 'daily cap reached',
        ];
    }

    $cutoff = crm_club_jalali_cutoff($offsetMonths, $offsetDays);
    $allRegs = crm_club_load_eligible_registrations($conn, $rule, $cutoff);

    // Pending serials only (need at least one channel), oldest first — already sorted.
    $pending = [];
    foreach ($allRegs as $reg) {
        $serial = (string) $reg['serial'];
        if (!crm_club_serial_needs_any_channel(
            $conn,
            $ruleId,
            $serial,
            $sendSms,
            $sendRubika,
            $sendTelegram,
            $sendBale
        )) {
            continue;
        }
        $pending[] = $reg;
    }
    $eligibleTotal = count($pending);
    $batch = array_slice($pending, 0, $remainingCap);

    $template = (string) ($rule['message'] ?? '');
    $delay = max(0, (int) ($rule['delay_seconds'] ?? 0));

    if ($sendRubika || $sendTelegram || $sendBale) {
        require_once __DIR__ . '/bot_processor.php';
        require_once __DIR__ . '/comms_store.php';
    }
    if ($sendSms) {
        require_once __DIR__ . '/comms_store.php';
    }

    $sent = 0;
    $failed = 0;
    $skipped = 0;
    $serialsAlarmOk = 0;

    foreach ($batch as $reg) {
        $phone = $reg['phone'];
        $serial = (string) $reg['serial'];
        $body = crm_club_apply_placeholders($template, $reg);
        $anyAttempt = false;
        $anySuccess = false;

        if ($sendSms) {
            if (crm_club_already_sent_serial($conn, $ruleId, $serial, 'sms')) {
                $skipped++;
            } else {
                $anyAttempt = true;
                $result = crm_send_panel_sms($phone, $body);
                if ($result === true) {
                    crm_club_record_send($conn, $ruleId, $phone, 'sms', $serial);
                    if (function_exists('comms_insert')) {
                        comms_insert($conn, $phone, 'outgoing', $body, 'sms', 'sent');
                    }
                    $sent++;
                    $anySuccess = true;
                } else {
                    $failed++;
                    error_log('[club_auto] sms fail rule=' . $ruleId . ' serial=' . $serial . ' err=' . $result);
                }
            }
        }

        foreach (['rubika' => $sendRubika, 'telegram' => $sendTelegram, 'bale' => $sendBale] as $ch => $on) {
            if (!$on) {
                continue;
            }
            if (crm_club_already_sent_serial($conn, $ruleId, $serial, $ch)) {
                $skipped++;
                continue;
            }
            $anyAttempt = true;
            if (function_exists('bot_send_plain') && bot_send_plain($conn, $ch, $phone, $body)) {
                crm_club_record_send($conn, $ruleId, $phone, $ch, $serial);
                $sent++;
                $anySuccess = true;
            } else {
                $failed++;
                error_log('[club_auto] ' . $ch . ' fail/skip rule=' . $ruleId . ' serial=' . $serial);
            }
        }

        if ($anySuccess) {
            $serialsAlarmOk++;
        }

        if ($anyAttempt && $delay > 0) {
            sleep($delay);
        }
    }

    $today = today_jalali_date();
    $nowMs = (int) round(microtime(true) * 1000);
    $totalBump = $sent;
    $stmt = $conn->prepare(
        'UPDATE club_auto_message_rules SET
            last_run_at=?, last_run_date_jalali=?, last_success_count=?, last_fail_count=?,
            total_sent_count = COALESCE(total_sent_count,0) + ?, updated_at=?
         WHERE id=?'
    );
    if ($stmt) {
        $stmt->bind_param('isiiiii', $nowMs, $today, $sent, $failed, $totalBump, $nowMs, $ruleId);
        $stmt->execute();
        $stmt->close();
    }

    if (is_file(__DIR__ . '/activity_store.php')) {
        require_once __DIR__ . '/activity_store.php';
        $left = max(0, $eligibleTotal - count($batch));
        crm_activity_record($conn, 'club_auto_run', [
            'ruleId' => $ruleId,
            'title' => (string) ($rule['title'] ?? ''),
            'message' => 'اجرای پیام خودکار — موفق: ' . $sent
                . ' سریال: ' . $serialsAlarmOk
                . ' سقف روزانه: ' . $dailyCap
                . ($left > 0 ? (' — باقی‌مانده برای فردا: ' . $left) : ''),
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'eligible' => $eligibleTotal,
            'dailyCap' => $dailyCap,
            'batch' => count($batch),
            'remaining' => $left,
        ]);
    }

    return [
        'ok' => true,
        'sent' => $sent,
        'failed' => $failed,
        'skipped' => $skipped,
        'eligible' => $eligibleTotal,
        'capped' => $dailyCap,
        'batch' => count($batch),
        'serials' => $serialsAlarmOk,
        'cutoff' => $cutoff,
    ];
}

/**
 * Tick all enabled rules (cron).
 * @return list<array>
 */
function crm_club_tick_all(mysqli $conn): array
{
    crm_ensure_settings_schema($conn);
    crm_club_ensure_daily_cap_column($conn);
    $res = $conn->query('SELECT * FROM club_auto_message_rules WHERE enabled = 1 ORDER BY id ASC');
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[] = array_merge(['ruleId' => (int) $row['id']], crm_club_run_rule($conn, $row, false));
        }
    }
    return $out;
}

/**
 * Run all enabled rules that are due (past send time + remaining daily cap).
 * @return array{ok:bool,rules:int,sent:int,failed:int,details:list<array>}
 */
function crm_club_run_due_rules(mysqli $conn): array
{
    crm_club_ensure_sends_table($conn);
    crm_club_ensure_daily_cap_column($conn);
    date_default_timezone_set('Asia/Tehran');
    $res = $conn->query(
        'SELECT * FROM club_auto_message_rules WHERE enabled = 1 ORDER BY id ASC'
    );
    $details = [];
    $sent = 0;
    $failed = 0;
    $rules = 0;
    if ($res) {
        while ($rule = $res->fetch_assoc()) {
            $rules++;
            $result = crm_club_run_rule($conn, $rule, false);
            $sent += (int) ($result['sent'] ?? 0);
            $failed += (int) ($result['failed'] ?? 0);
            $details[] = [
                'ruleId' => (int) ($rule['id'] ?? 0),
                'title' => (string) ($rule['title'] ?? ''),
                'result' => $result,
            ];
        }
    }
    return [
        'ok' => true,
        'rules' => $rules,
        'sent' => $sent,
        'failed' => $failed,
        'details' => $details,
    ];
}

/**
 * Preview eligible serials for a rule (no send).
 * @return array{items:list<array>,total:int,pendingCount:int,cutoffDateJalali:string}
 */
function crm_club_preview_rule(mysqli $conn, array $rule, int $page = 1, int $limit = 50): array
{
    crm_club_ensure_sends_table($conn);
    $offsetMonths = (int) ($rule['offset_months'] ?? 0);
    $offsetDays = (int) ($rule['offset_days'] ?? 0);
    $cutoff = crm_club_jalali_cutoff($offsetMonths, $offsetDays);
    $regs = crm_club_load_eligible_registrations($conn, $rule, $cutoff);
    $ruleId = (int) ($rule['id'] ?? 0);
    $sendSms = !empty($rule['send_sms']);
    $sendRubika = !empty($rule['send_rubika']);
    $sendTelegram = !empty($rule['send_telegram']);
    $sendBale = !empty($rule['send_bale']);
    $items = [];
    $pending = 0;
    $alreadyFully = 0;
    foreach ($regs as $reg) {
        $serial = (string) $reg['serial'];
        $channels = [];
        $channelDefs = [];
        if ($sendSms) {
            $channelDefs[] = 'sms';
        }
        if ($sendRubika) {
            $channelDefs[] = 'rubika';
        }
        if ($sendTelegram) {
            $channelDefs[] = 'telegram';
        }
        if ($sendBale) {
            $channelDefs[] = 'bale';
        }
        $willSend = false;
        $allSent = $channelDefs !== [];
        foreach ($channelDefs as $ch) {
            $already = crm_club_already_sent_serial($conn, $ruleId, $serial, $ch);
            $status = $already ? 'already_sent' : 'pending';
            if ($already) {
                // keep
            } else {
                $willSend = true;
                $allSent = false;
            }
            $channels[] = ['channel' => $ch, 'status' => $status];
        }
        if ($willSend) {
            $pending++;
        } elseif ($allSent) {
            $alreadyFully++;
        }
        $items[] = [
            'phone' => $reg['phone'],
            'serial' => $serial,
            'channels' => $channels,
            'willSendAny' => $willSend,
        ];
    }
    $page = max(1, $page);
    $limit = max(1, min(100, $limit));
    $offset = ($page - 1) * $limit;
    $slice = array_slice($items, $offset, $limit);
    $dailyCap = crm_club_rule_daily_cap($rule);
    $sentToday = $ruleId > 0 ? crm_club_serials_sent_today($conn, $ruleId) : 0;
    return [
        'items' => $slice,
        'total' => count($items),
        'pendingCount' => $pending,
        'blockedCount' => 0,
        'alreadyFullySentCount' => $alreadyFully,
        'cutoffDateJalali' => $cutoff,
        'dailyCap' => $dailyCap,
        'sentToday' => $sentToday,
        'remainingToday' => max(0, $dailyCap - $sentToday),
        'page' => $page,
        'limit' => $limit,
    ];
}

/**
 * @return array{items:list<array>,total:int,page:int,limit:int}
 */
function crm_club_list_sends(mysqli $conn, int $ruleId, int $page = 1, int $limit = 50): array
{
    crm_club_ensure_sends_table($conn);
    $page = max(1, $page);
    $limit = max(1, min(100, $limit));
    $offset = ($page - 1) * $limit;
    $total = 0;
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM club_auto_message_sends WHERE rule_id=?');
    if ($stmt) {
        $stmt->bind_param('i', $ruleId);
        $stmt->execute();
        $row = stmt_fetch_assoc($stmt);
        $stmt->close();
        $total = (int) ($row['c'] ?? 0);
    }
    $items = [];
    $stmt = $conn->prepare(
        'SELECT id, phone, serial, channel, sent_at, sent_date_jalali
         FROM club_auto_message_sends WHERE rule_id=?
         ORDER BY sent_at DESC LIMIT ? OFFSET ?'
    );
    if ($stmt) {
        $stmt->bind_param('iii', $ruleId, $limit, $offset);
        $stmt->execute();
        foreach (stmt_fetch_all_assoc($stmt) as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'phone' => (string) $row['phone'],
                'serial' => (string) ($row['serial'] ?? ''),
                'channel' => (string) $row['channel'],
                'sentAt' => (int) $row['sent_at'],
                'sentDateJalali' => (string) $row['sent_date_jalali'],
            ];
        }
        $stmt->close();
    }
    return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
}
