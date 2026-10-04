<?php
/**
 * Remote product photo verification (bot upload + admin review).
 * Mirrors Android SerialPhotoVerificationService.
 */
declare(strict_types=1);

const PHOTO_CANCEL_LABEL = 'انصراف';
const PHOTO_STATE_PICK = 'awaiting_serial_pick';
const PHOTO_STATE_PHOTO = 'awaiting_photo';

function photo_ensure_schema(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS serial_photo_submissions (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            serial VARCHAR(64) NOT NULL,
            serial_row_id BIGINT DEFAULT NULL,
            group_id BIGINT DEFAULT NULL,
            phone VARCHAR(32) NOT NULL,
            channel VARCHAR(16) NOT NULL,
            chat_id VARCHAR(64) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            local_path VARCHAR(512) NOT NULL,
            mime_type VARCHAR(64) DEFAULT NULL,
            remote_file_id VARCHAR(255) DEFAULT NULL,
            score_awarded INT NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL,
            reviewed_at BIGINT DEFAULT NULL,
            review_note VARCHAR(512) DEFAULT NULL,
            INDEX idx_photo_status_created (status, created_at),
            INDEX idx_photo_phone (phone),
            INDEX idx_photo_serial (serial)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS bot_chat_sessions (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            channel VARCHAR(16) NOT NULL,
            chat_id VARCHAR(64) NOT NULL,
            phone VARCHAR(32) NOT NULL,
            state VARCHAR(32) NOT NULL,
            pending_serial VARCHAR(64) DEFAULT NULL,
            updated_at BIGINT NOT NULL,
            UNIQUE KEY uq_bot_session (channel, chat_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $dir = photo_storage_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $deny = $dir . '/.htaccess';
    if (!is_file($deny)) {
        @file_put_contents($deny, "Require all denied\nDeny from all\n");
    }
}

function photo_storage_dir(): string
{
    return dirname(__DIR__) . '/storage/serial_photos';
}

function photo_ext_for_mime(?string $mime): string
{
    switch (strtolower((string) $mime)) {
        case 'image/png':
            return '.png';
        case 'image/webp':
            return '.webp';
        case 'image/gif':
            return '.gif';
        default:
            return '.jpg';
    }
}

function photo_session_find(mysqli $conn, string $channel, string $chatId): ?array
{
    photo_ensure_schema($conn);
    $stmt = $conn->prepare('SELECT * FROM bot_chat_sessions WHERE channel=? AND chat_id=? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ss', $channel, $chatId);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    $stmt->close();
    return $row ?: null;
}

function photo_session_clear(mysqli $conn, string $channel, string $chatId): void
{
    photo_ensure_schema($conn);
    $stmt = $conn->prepare('DELETE FROM bot_chat_sessions WHERE channel=? AND chat_id=?');
    if ($stmt) {
        $stmt->bind_param('ss', $channel, $chatId);
        $stmt->execute();
        $stmt->close();
    }
}

function photo_session_upsert(
    mysqli $conn,
    string $channel,
    string $chatId,
    string $phone,
    string $state,
    ?string $pendingSerial
): void {
    photo_ensure_schema($conn);
    $now = (int) round(microtime(true) * 1000);
    $existing = photo_session_find($conn, $channel, $chatId);
    if ($existing) {
        $stmt = $conn->prepare(
            'UPDATE bot_chat_sessions SET phone=?, state=?, pending_serial=?, updated_at=? WHERE channel=? AND chat_id=?'
        );
        if ($stmt) {
            $stmt->bind_param('sssiss', $phone, $state, $pendingSerial, $now, $channel, $chatId);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }
    $stmt = $conn->prepare(
        'INSERT INTO bot_chat_sessions (channel, chat_id, phone, state, pending_serial, updated_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    if ($stmt) {
        $stmt->bind_param('sssssi', $channel, $chatId, $phone, $state, $pendingSerial, $now);
        $stmt->execute();
        $stmt->close();
    }
}

function photo_registration_complete(array $row): bool
{
    $phone = trim((string) ($row['phone'] ?? ''));
    if ($phone === '' || $phone === '0') {
        return false;
    }
    $cat = trim((string) ($row['category'] ?? ''));
    $city = trim((string) ($row['city'] ?? ''));
    if ($cat === 'seller_to_end_user') {
        return $city !== '';
    }
    if ($city === '') {
        return false;
    }
    return isset($row['km']) && $row['km'] !== null && $row['km'] !== '';
}

function photo_group_requires(mysqli $conn, array $row): bool
{
    if (!empty($row['image_verification_required'])) {
        return true;
    }
    $gid = (int) ($row['lan_group_id'] ?? 0);
    if ($gid <= 0 || !table_exists($conn, 'serial_groups')) {
        return false;
    }
    $stmt = $conn->prepare('SELECT image_verification_required, score, description, prefix FROM serial_groups WHERE id=? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $gid);
    $stmt->execute();
    $g = stmt_fetch_assoc($stmt);
    $stmt->close();
    return !empty($g['image_verification_required']);
}

function photo_load_group(mysqli $conn, ?int $groupId): ?array
{
    if ($groupId === null || $groupId <= 0 || !table_exists($conn, 'serial_groups')) {
        return null;
    }
    $stmt = $conn->prepare('SELECT * FROM serial_groups WHERE id=? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    $stmt->close();
    return $row ?: null;
}

/** @return list<array> serial rows needing photo */
function photo_list_needing(mysqli $conn, string $phone): array
{
    photo_ensure_schema($conn);
    $phone = comms_normalize_phone($phone);
    $table = serials_table($conn);
    $sql = "SELECT s.* FROM `$table` s WHERE s.phone = ? AND " . is_registered_sql('s');
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('s', $phone);
    $stmt->execute();
    $rows = stmt_fetch_all_assoc($stmt);
    $stmt->close();

    $out = [];
    foreach ($rows as $row) {
        if (!photo_registration_complete($row)) {
            continue;
        }
        if (!photo_group_requires($conn, $row)) {
            continue;
        }
        $serial = (string) ($row['serial'] ?? '');
        if ($serial === '' || photo_count_active($conn, $serial, $phone) > 0) {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

function photo_count_active(mysqli $conn, string $serial, string $phone): int
{
    photo_ensure_schema($conn);
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM serial_photo_submissions
         WHERE serial=? AND phone=? AND status IN ('pending','approved')"
    );
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('ss', $serial, $phone);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    $stmt->close();
    return (int) ($row['c'] ?? 0);
}

/**
 * @return array{reply:string,serialButtons:list<string>}
 */
function photo_start_flow(mysqli $conn, string $channel, string $chatId, string $phone): array
{
    $phone = comms_normalize_phone($phone);
    $candidates = photo_list_needing($conn, $phone);
    if ($candidates === []) {
        photo_session_clear($conn, $channel, $chatId);
        return [
            'reply' => 'سریالی که نیاز به ارسال عکس محصول داشته باشد برای این شماره یافت نشد.',
            'serialButtons' => [],
        ];
    }
    if (count($candidates) === 1) {
        $serial = (string) $candidates[0]['serial'];
        photo_session_upsert($conn, $channel, $chatId, $phone, PHOTO_STATE_PHOTO, $serial);
        return [
            'reply' => "لطفاً عکس محصول مربوط به سریال $serial را ارسال کنید.",
            'serialButtons' => [],
        ];
    }
    photo_session_upsert($conn, $channel, $chatId, $phone, PHOTO_STATE_PICK, null);
    $lines = [];
    $buttons = [];
    foreach ($candidates as $i => $row) {
        $serial = (string) $row['serial'];
        $lines[] = ($i + 1) . ') ' . $serial;
        $buttons[] = $serial;
    }
    return [
        'reply' => "کدام سریال را برای تأیید عکس انتخاب می‌کنید؟\n\n" . implode("\n", $lines),
        'serialButtons' => $buttons,
    ];
}

/**
 * @return array{reply:string,serialButtons:list<string>}
 */
function photo_start_flow_for_serial(
    mysqli $conn,
    string $channel,
    string $chatId,
    string $phone,
    string $serial
): array {
    $phone = comms_normalize_phone($phone);
    $serial = strtoupper(trim($serial));
    $candidates = photo_list_needing($conn, $phone);
    foreach ($candidates as $row) {
        if (strcasecmp((string) $row['serial'], $serial) === 0) {
            photo_session_upsert($conn, $channel, $chatId, $phone, PHOTO_STATE_PHOTO, (string) $row['serial']);
            return [
                'reply' => 'لطفاً عکس محصول مربوط به سریال ' . $row['serial'] . ' را ارسال کنید.',
                'serialButtons' => [],
            ];
        }
    }
    return photo_start_flow($conn, $channel, $chatId, $phone);
}

function photo_handle_session_text(
    mysqli $conn,
    string $channel,
    string $chatId,
    string $phone,
    string $text
): ?string {
    $session = photo_session_find($conn, $channel, $chatId);
    if (!$session) {
        return null;
    }
    $trimmed = trim($text);
    if ($trimmed === PHOTO_CANCEL_LABEL || $trimmed === 'cancel') {
        photo_session_clear($conn, $channel, $chatId);
        return 'ارسال عکس لغو شد.';
    }
    $state = (string) ($session['state'] ?? '');
    if ($state === PHOTO_STATE_PICK) {
        $phone = comms_normalize_phone($phone);
        $candidates = photo_list_needing($conn, $phone);
        $serial = null;
        if (ctype_digit($trimmed)) {
            $idx = ((int) $trimmed) - 1;
            if (isset($candidates[$idx])) {
                $serial = (string) $candidates[$idx]['serial'];
            }
        }
        if ($serial === null) {
            $norm = strtoupper(preg_replace('/\s+/', '', $trimmed) ?? '');
            foreach ($candidates as $row) {
                if (strcasecmp((string) $row['serial'], $norm) === 0) {
                    $serial = (string) $row['serial'];
                    break;
                }
            }
        }
        if ($serial === null) {
            return 'سریال نامعتبر است. شماره ردیف یا کد سریال را ارسال کنید، یا «انصراف».';
        }
        photo_session_upsert($conn, $channel, $chatId, $phone, PHOTO_STATE_PHOTO, $serial);
        return "لطفاً عکس محصول مربوط به سریال $serial را ارسال کنید.";
    }
    if ($state === PHOTO_STATE_PHOTO) {
        $pending = (string) ($session['pending_serial'] ?? '');
        return "در انتظار دریافت عکس برای سریال $pending هستید. عکس را ارسال کنید یا «انصراف» بزنید.";
    }
    return null;
}

/**
 * @return array{reply:string,submissionId:?int}
 */
function photo_save_bytes(
    mysqli $conn,
    string $channel,
    string $chatId,
    string $phone,
    string $bytes,
    ?string $mimeType,
    ?string $remoteFileId
): array {
    photo_ensure_schema($conn);
    $session = photo_session_find($conn, $channel, $chatId);
    if (!$session) {
        return ['reply' => 'ابتدا از منو «ارسال عکس محصول» را انتخاب کنید.', 'submissionId' => null];
    }
    if ((string) ($session['state'] ?? '') !== PHOTO_STATE_PHOTO) {
        return ['reply' => 'ابتدا سریال را انتخاب کنید، سپس عکس را بفرستید.', 'submissionId' => null];
    }
    $serial = trim((string) ($session['pending_serial'] ?? ''));
    if ($serial === '') {
        return ['reply' => 'ابتدا سریال را انتخاب کنید، سپس عکس را بفرستید.', 'submissionId' => null];
    }
    $phone = comms_normalize_phone($phone);
    $table = serials_table($conn);
    $stmt = $conn->prepare("SELECT * FROM `$table` WHERE UPPER(serial)=? LIMIT 1");
    if (!$stmt) {
        return ['reply' => 'خطا در بررسی سریال.', 'submissionId' => null];
    }
    $stmt->bind_param('s', $serial);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    $stmt->close();
    $rowPhone = $row ? comms_normalize_phone((string) ($row['phone'] ?? '')) : '';
    if (!$row || $rowPhone === '' || $rowPhone !== $phone) {
        photo_session_clear($conn, $channel, $chatId);
        return ['reply' => 'سریال با این شماره مطابقت ندارد.', 'submissionId' => null];
    }
    if (!photo_group_requires($conn, $row)) {
        photo_session_clear($conn, $channel, $chatId);
        return ['reply' => 'این سریال نیاز به تأیید عکس ندارد.', 'submissionId' => null];
    }
    if (photo_count_active($conn, $serial, $phone) > 0) {
        photo_session_clear($conn, $channel, $chatId);
        return ['reply' => 'برای این سریال قبلاً عکس ارسال شده است.', 'submissionId' => null];
    }
    if ($bytes === '') {
        return ['reply' => 'دریافت تصویر ناموفق بود. دوباره ارسال کنید.', 'submissionId' => null];
    }

    $dir = photo_storage_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $ext = photo_ext_for_mime($mimeType);
    $safeSerial = preg_replace('/[^A-Za-z0-9_-]/', '_', $serial) ?? 'serial';
    $filename = 'photo_' . time() . '_' . $safeSerial . $ext;
    $path = $dir . '/' . $filename;
    if (@file_put_contents($path, $bytes) === false) {
        return ['reply' => 'ذخیره تصویر ناموفق بود.', 'submissionId' => null];
    }

    $now = (int) round(microtime(true) * 1000);
    $serialRowId = isset($row['id']) ? (int) $row['id'] : 0;
    $groupId = isset($row['lan_group_id']) ? (int) $row['lan_group_id'] : 0;
    if ($groupId < 0) {
        $groupId = 0;
    }
    $status = 'pending';
    $scoreAwarded = 0;
    $mimeStore = $mimeType;
    $remoteStore = $remoteFileId;
    $stmt = $conn->prepare(
        'INSERT INTO serial_photo_submissions
            (serial, serial_row_id, group_id, phone, channel, chat_id, status, local_path, mime_type, remote_file_id, score_awarded, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        @unlink($path);
        return ['reply' => 'خطا در ثبت ارسال.', 'submissionId' => null];
    }
    $stmt->bind_param(
        'siisssssssii',
        $serial,
        $serialRowId,
        $groupId,
        $phone,
        $channel,
        $chatId,
        $status,
        $path,
        $mimeStore,
        $remoteStore,
        $scoreAwarded,
        $now
    );
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    photo_session_clear($conn, $channel, $chatId);

    if (is_file(__DIR__ . '/activity_store.php')) {
        require_once __DIR__ . '/activity_store.php';
        crm_activity_record($conn, 'photo_submitted', [
            'serial' => $serial,
            'phone' => $phone,
            'channel' => $channel,
            'submissionId' => $id,
            'message' => 'ارسال عکس محصول برای سریال ' . $serial,
        ]);
    }

    return [
        'reply' => "عکس سریال $serial دریافت شد و در صف بررسی قرار گرفت.",
        'submissionId' => $id,
    ];
}

function photo_row_to_api(mysqli $conn, array $row): array
{
    $id = (int) ($row['id'] ?? 0);
    $groupIdRaw = isset($row['group_id']) ? (int) $row['group_id'] : 0;
    $groupId = $groupIdRaw > 0 ? $groupIdRaw : null;
    $group = photo_load_group($conn, $groupId);
    $score = $group ? max(0, (int) ($group['score'] ?? 0)) : 0;
    $groupLabel = null;
    if ($group) {
        $desc = trim((string) ($group['description'] ?? ''));
        $prefix = trim((string) ($group['prefix'] ?? ''));
        $groupLabel = $desc !== '' ? $desc : ($prefix !== '' ? $prefix : ('گروه #' . $groupId));
    }
    return [
        'id' => $id,
        'serial' => (string) ($row['serial'] ?? ''),
        'serialRowId' => isset($row['serial_row_id']) ? (int) $row['serial_row_id'] : null,
        'groupId' => $groupId,
        'groupLabel' => $groupLabel,
        'phone' => (string) ($row['phone'] ?? ''),
        'channel' => (string) ($row['channel'] ?? ''),
        'status' => (string) ($row['status'] ?? 'pending'),
        'imageUrl' => '/api/serial-photo-submissions/' . $id . '/image',
        'mimeType' => $row['mime_type'] ?? null,
        'score' => $score,
        'scoreAwarded' => (int) ($row['score_awarded'] ?? 0),
        'createdAt' => (int) ($row['created_at'] ?? 0),
        'reviewedAt' => isset($row['reviewed_at']) ? (int) $row['reviewed_at'] : null,
        'reviewNote' => $row['review_note'] ?? null,
    ];
}

function photo_list_submissions(mysqli $conn, string $status, int $page, int $limit): array
{
    photo_ensure_schema($conn);
    $page = max(1, $page);
    $limit = max(1, min(100, $limit));
    $offset = ($page - 1) * $limit;
    $status = trim($status);
    if ($status !== '' && !in_array($status, ['pending', 'approved', 'rejected'], true)) {
        $status = '';
    }
    if ($status !== '') {
        $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM serial_photo_submissions WHERE status=?');
        $stmt->bind_param('s', $status);
        $stmt->execute();
        $total = (int) (stmt_fetch_assoc($stmt)['c'] ?? 0);
        $stmt->close();
        $stmt = $conn->prepare(
            'SELECT * FROM serial_photo_submissions WHERE status=? ORDER BY created_at DESC LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('sii', $status, $limit, $offset);
    } else {
        $res = $conn->query('SELECT COUNT(*) AS c FROM serial_photo_submissions');
        $total = $res ? (int) ($res->fetch_assoc()['c'] ?? 0) : 0;
        $stmt = $conn->prepare(
            'SELECT * FROM serial_photo_submissions ORDER BY created_at DESC LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('ii', $limit, $offset);
    }
    $items = [];
    if ($stmt) {
        $stmt->execute();
        foreach (stmt_fetch_all_assoc($stmt) as $row) {
            $items[] = photo_row_to_api($conn, $row);
        }
        $stmt->close();
    }
    return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
}

function photo_find(mysqli $conn, int $id): ?array
{
    photo_ensure_schema($conn);
    $stmt = $conn->prepare('SELECT * FROM serial_photo_submissions WHERE id=? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    $stmt->close();
    return $row ?: null;
}

function photo_award_serial_score(mysqli $conn, string $serial, string $phone, int $score): void
{
    if ($score <= 0) {
        return;
    }
    $table = serials_table($conn);
    if (!column_exists($conn, $table, 'score')) {
        return;
    }
    $stmt = $conn->prepare("UPDATE `$table` SET score = ? WHERE UPPER(serial)=? AND phone=?");
    if ($stmt) {
        $stmt->bind_param('iss', $score, $serial, $phone);
        $stmt->execute();
        $stmt->close();
    }
}

function photo_notify_user(mysqli $conn, array $row, string $message): void
{
    $channel = (string) ($row['channel'] ?? '');
    $chatId = (string) ($row['chat_id'] ?? '');
    if ($channel === '' || $chatId === '' || $message === '') {
        return;
    }
    require_once __DIR__ . '/bots_store.php';
    require_once __DIR__ . '/bots/TelegramClient.php';
    require_once __DIR__ . '/bots/BaleClient.php';
    require_once __DIR__ . '/bots/RubikaClient.php';
    $runtime = bots_get_runtime($conn, $channel);
    $token = trim((string) ($runtime['token'] ?? ''));
    if ($token === '') {
        return;
    }
    try {
        if ($channel === 'telegram') {
            TelegramClient::sendMessage($token, $chatId, $message);
        } elseif ($channel === 'bale') {
            BaleClient::sendMessage($token, $chatId, $message);
        } elseif ($channel === 'rubika') {
            RubikaClient::sendMessage($token, $chatId, $message);
        }
        if (function_exists('comms_insert')) {
            require_once __DIR__ . '/comms_store.php';
            comms_insert($conn, (string) $row['phone'], 'outgoing', $message, $channel, 'sent');
        }
    } catch (Throwable $e) {
        error_log('[photo_notify] ' . $e->getMessage());
    }
}

function photo_approve(mysqli $conn, int $id, ?string $note): array
{
    $row = photo_find($conn, $id);
    if (!$row) {
        throw new InvalidArgumentException('ارسال یافت نشد');
    }
    if (($row['status'] ?? '') !== 'pending') {
        throw new InvalidArgumentException('این ارسال قبلاً بررسی شده است');
    }
    $group = photo_load_group($conn, isset($row['group_id']) ? (int) $row['group_id'] : null);
    $score = $group ? max(0, (int) ($group['score'] ?? 0)) : 0;
    if ($score <= 0 && column_exists($conn, serials_table($conn), 'score')) {
        // Fall back to serial's configured score if group missing
        $table = serials_table($conn);
        $serial = (string) $row['serial'];
        $stmt = $conn->prepare("SELECT score FROM `$table` WHERE UPPER(serial)=? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $serial);
            $stmt->execute();
            $srow = stmt_fetch_assoc($stmt);
            $stmt->close();
            // Prefer group score; if serial score was reserved as group copy use it only when group absent
            if (!$group && $srow) {
                $score = max(0, (int) ($srow['score'] ?? 0));
            }
        }
    }
    // Prefer group's intended award; serial.score may still be 0 until approve
    if ($group) {
        $score = max(0, (int) ($group['score'] ?? 0));
    }
    $now = (int) round(microtime(true) * 1000);
    $noteTrim = $note !== null ? trim($note) : '';
    $noteVal = $noteTrim !== '' ? $noteTrim : null;
    $status = 'approved';
    $stmt = $conn->prepare(
        'UPDATE serial_photo_submissions SET status=?, reviewed_at=?, review_note=?, score_awarded=? WHERE id=?'
    );
    $stmt->bind_param('sisii', $status, $now, $noteVal, $score, $id);
    $stmt->execute();
    $stmt->close();

    photo_award_serial_score($conn, (string) $row['serial'], (string) $row['phone'], $score);

    $updated = photo_find($conn, $id) ?: $row;
    $msg = 'عکس سریال ' . $row['serial'] . ' تأیید شد.' . ($score > 0 ? (' امتیاز: ' . $score) : '');
    photo_notify_user($conn, $updated, $msg);

    if (is_file(__DIR__ . '/activity_store.php')) {
        require_once __DIR__ . '/activity_store.php';
        crm_activity_record($conn, 'photo_approved', [
            'serial' => $row['serial'],
            'phone' => $row['phone'],
            'submissionId' => $id,
            'message' => 'تأیید عکس سریال ' . $row['serial'],
        ]);
    }
    return photo_row_to_api($conn, $updated);
}

function photo_reject_reason_normalize(?string $raw): string
{
    $v = strtolower(trim((string) $raw));
    if (in_array($v, ['unclear', 'not_clear', 'blurry'], true)) {
        return 'unclear';
    }
    if (in_array($v, ['not_related', 'unrelated'], true)) {
        return 'not_related';
    }
    if (in_array($v, ['wrong_serial', 'wrong_number', 'wrong_serial_number'], true)) {
        return 'wrong_serial';
    }
    return 'other';
}

function photo_reject_message(string $serial, string $reason, ?string $note): string
{
    switch ($reason) {
        case 'unclear':
            return "عکس سریال $serial رد شد: تصویر واضح نیست. لطفاً دوباره ارسال کنید.";
        case 'not_related':
            return "عکس سریال $serial رد شد: تصویر مرتبط با محصول نیست.";
        case 'wrong_serial':
            return "عکس سریال $serial رد شد: سریال در تصویر مطابقت ندارد.";
        default:
            $custom = trim((string) $note);
            if ($custom !== '') {
                return "عکس سریال $serial رد شد: $custom";
            }
            return "عکس سریال $serial رد شد. از منو دوباره عکس ارسال کنید.";
    }
}

function photo_reject(mysqli $conn, int $id, ?string $reasonCode, ?string $note): array
{
    $row = photo_find($conn, $id);
    if (!$row) {
        throw new InvalidArgumentException('ارسال یافت نشد');
    }
    if (($row['status'] ?? '') !== 'pending') {
        throw new InvalidArgumentException('این ارسال قبلاً بررسی شده است');
    }
    $reason = photo_reject_reason_normalize($reasonCode);
    $noteTrim = $note !== null ? trim($note) : '';
    $storedNote = $reason . ($noteTrim !== '' ? (' | ' . $noteTrim) : '');
    $now = (int) round(microtime(true) * 1000);
    $status = 'rejected';
    $score = 0;
    $stmt = $conn->prepare(
        'UPDATE serial_photo_submissions SET status=?, reviewed_at=?, review_note=?, score_awarded=? WHERE id=?'
    );
    $stmt->bind_param('sisii', $status, $now, $storedNote, $score, $id);
    $stmt->execute();
    $stmt->close();

    $updated = photo_find($conn, $id) ?: $row;
    photo_notify_user($conn, $updated, photo_reject_message((string) $row['serial'], $reason, $noteTrim !== '' ? $noteTrim : null));

    if (is_file(__DIR__ . '/activity_store.php')) {
        require_once __DIR__ . '/activity_store.php';
        crm_activity_record($conn, 'photo_rejected', [
            'serial' => $row['serial'],
            'phone' => $row['phone'],
            'submissionId' => $id,
            'message' => 'رد عکس سریال ' . $row['serial'],
        ]);
    }
    return photo_row_to_api($conn, $updated);
}

function photo_serial_needs_after_register(mysqli $conn, string $serial, string $phone): bool
{
    $table = serials_table($conn);
    $stmt = $conn->prepare("SELECT * FROM `$table` WHERE UPPER(serial)=? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $serial);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    $stmt->close();
    if (!$row) {
        return false;
    }
    $rowPhone = comms_normalize_phone((string) ($row['phone'] ?? ''));
    if ($rowPhone !== comms_normalize_phone($phone)) {
        return false;
    }
    if (!photo_registration_complete($row) || !photo_group_requires($conn, $row)) {
        return false;
    }
    return photo_count_active($conn, (string) $row['serial'], $rowPhone) === 0;
}
