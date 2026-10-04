<?php
/**
 * Dual-buffer sync helpers: crm_sync_buffer enqueue / list / ack.
 */
declare(strict_types=1);

function sync_ensure_buffer_table(mysqli $conn): void
{
    // Called on every enqueue; memoize per-request so bulk loops (backfill) don't
    // re-run 3 CREATE TABLE IF NOT EXISTS statements per row.
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;
    $conn->query(
        "CREATE TABLE IF NOT EXISTS crm_sync_buffer (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            entry_id VARCHAR(64) NOT NULL,
            serial VARCHAR(64) NULL,
            coalesce_key VARCHAR(128) NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            payload_json MEDIUMTEXT NOT NULL,
            created_at BIGINT NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            UNIQUE KEY uq_crm_sync_buffer_entry (entry_id),
            KEY idx_crm_sync_buffer_status (status, created_at),
            KEY idx_crm_sync_buffer_coalesce (status, coalesce_key),
            KEY idx_crm_sync_buffer_serial (status, serial)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS crm_sync_events (
            event_id VARCHAR(64) NOT NULL PRIMARY KEY,
            event_type VARCHAR(64) NOT NULL,
            applied_at BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS crm_sync_meta (
            meta_key VARCHAR(64) NOT NULL PRIMARY KEY,
            meta_value TEXT NULL,
            updated_at BIGINT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function sync_buffer_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Upsert a pending buffer entry. Coalesces by coalesce_key (one pending row per key).
 *
 * @param array<string,mixed> $payload
 */
function sync_buffer_enqueue(
    mysqli $conn,
    string $type,
    array $payload,
    ?string $serial = null,
    ?string $coalesceKey = null
): string {
    sync_ensure_buffer_table($conn);
    $now = (int) round(microtime(true) * 1000);
    $serialNorm = $serial !== null && trim($serial) !== '' ? strtoupper(trim($serial)) : null;
    if ($coalesceKey === null || $coalesceKey === '') {
        if ($serialNorm !== null) {
            $coalesceKey = 'serial:' . $serialNorm;
        } elseif ($type === 'group_upsert' || $type === 'group_delete') {
            $gid = (int) ($payload['syncOriginGroupId'] ?? $payload['localGroupId'] ?? 0);
            $coalesceKey = 'group:' . $gid . ':' . $type;
        } else {
            $coalesceKey = 'entry:' . sync_buffer_uuid();
        }
    }
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($payloadJson === false) {
        $payloadJson = '{}';
    }

    // Replace existing pending for same coalesce key.
    $del = $conn->prepare(
        "DELETE FROM crm_sync_buffer WHERE status='pending' AND coalesce_key=?"
    );
    if ($del) {
        $del->bind_param('s', $coalesceKey);
        $del->execute();
        $del->close();
    }

    $entryId = sync_buffer_uuid();
    $serialSql = $serialNorm === null ? 'NULL' : ("'" . $conn->real_escape_string($serialNorm) . "'");
    $sql = "INSERT INTO crm_sync_buffer
        (entry_id, serial, coalesce_key, event_type, payload_json, created_at, status)
        VALUES (
            '" . $conn->real_escape_string($entryId) . "',
            $serialSql,
            '" . $conn->real_escape_string($coalesceKey) . "',
            '" . $conn->real_escape_string($type) . "',
            '" . $conn->real_escape_string($payloadJson) . "',
            " . (int) $now . ",
            'pending'
        )";
    $conn->query($sql);
    return $entryId;
}

/**
 * Enqueue a serial row change (report edit / registration-style).
 *
 * @param array<string,mixed> $row DB row or field map
 */
function sync_buffer_enqueue_serial_row(mysqli $conn, array $row, ?string $regSource = null): void
{
    $serial = trim((string) ($row['serial'] ?? ''));
    if ($serial === '') {
        return;
    }
    $phoneRaw = isset($row['phone']) ? trim((string) $row['phone']) : '';
    $phone = ($phoneRaw === '' || $phoneRaw === '0') ? null : $phoneRaw;
    $dateJalali = null;
    if (function_exists('serial_date_jalali')) {
        $dateJalali = serial_date_jalali($row);
    } else {
        $raw = $row['date_jalali'] ?? ($row['dateJalali'] ?? null);
        if ($raw !== null && trim((string) $raw) !== '') {
            $dateJalali = trim((string) $raw);
        }
    }
    $payload = [
        'serial' => $serial,
        'phone' => $phone,
        'city' => $row['city'] ?? null,
        'km' => isset($row['km']) && $row['km'] !== null && $row['km'] !== '' ? (int) $row['km'] : null,
        'dateJalali' => $dateJalali,
        'regSource' => $regSource ?? ($row['reg_source'] ?? null),
        'category' => $row['category'] ?? null,
        'syncOriginGroupId' => isset($row['lan_group_id']) && (int) $row['lan_group_id'] > 0
            ? (int) $row['lan_group_id'] : null,
        'lanGroupId' => isset($row['lan_group_id']) && (int) $row['lan_group_id'] > 0
            ? (int) $row['lan_group_id'] : null,
        'syncUpdatedMs' => isset($row['sync_updated_ms'])
            ? (int) $row['sync_updated_ms']
            : (int) round(microtime(true) * 1000),
    ];
    sync_buffer_enqueue($conn, 'serial_upsert', $payload, $serial);
}

/**
 * Look up a serial row and enqueue it for phone pull (remote registrations / city-km updates).
 * Safe no-op if helpers/tables are missing or serial not found.
 */
function sync_buffer_enqueue_serial_lookup(
    mysqli $conn,
    string $serial,
    ?string $table = null,
    ?string $regSource = null
): void {
    $serial = strtoupper(trim($serial));
    if ($serial === '') {
        return;
    }
    if ($table === null || $table === '') {
        if (function_exists('serials_table')) {
            $table = serials_table($conn);
        } else {
            $table = 'old_serials';
        }
    }
    $check = @$conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    if (!$check || $check->num_rows === 0) {
        return;
    }
    $stmt = $conn->prepare("SELECT * FROM `$table` WHERE UPPER(serial) = ? LIMIT 1");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('s', $serial);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return;
    }
    sync_buffer_enqueue_serial_row($conn, $row, $regSource);
}

/**
 * One-time / catch-up: enqueue currently registered serials so phones can pull them.
 * Safe to re-run (coalesces pending by serial). Count-based, not date-based, so it
 * covers a gap of any age as long as fewer than $limit registrations happened since.
 * Returns number of rows enqueued.
 */
function sync_buffer_backfill_registrations(mysqli $conn, int $limit = 5000): int
{
    // Shared hosts kill long-running scripts; each row does a DELETE+INSERT, so this
    // must stay well under the default execution window even for the max $limit.
    @set_time_limit(90);
    sync_ensure_buffer_table($conn);
    $limit = max(1, min(5000, $limit));
    $table = function_exists('serials_table') ? serials_table($conn) : 'old_serials';
    $check = @$conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    if (!$check || $check->num_rows === 0) {
        return 0;
    }
    $sql = "SELECT * FROM `$table`
            WHERE phone IS NOT NULL AND TRIM(phone) <> '' AND TRIM(phone) <> '0'
            ORDER BY id DESC
            LIMIT " . (int) $limit;
    $res = $conn->query($sql);
    if (!$res) {
        return 0;
    }
    $n = 0;
    // One transaction for the whole batch — per-statement autocommit (fsync per
    // DELETE+INSERT) is what made large batches slow enough to hit the host's
    // execution-time limit and return a 500 (same issue fixed for ack() below).
    $conn->begin_transaction();
    while ($row = $res->fetch_assoc()) {
        sync_buffer_enqueue_serial_row($conn, $row, isset($row['reg_source']) ? (string) $row['reg_source'] : null);
        $n++;
    }
    $conn->commit();
    return $n;
}

/**
 * After remote generation: enqueue group + serial batch (not used for CSV import).
 *
 * @param list<string> $serials
 */
function sync_buffer_enqueue_generation(
    mysqli $conn,
    int $groupId,
    array $serials,
    string $category,
    array $groupRow = []
): void {
    if ($groupId <= 0 || $serials === []) {
        return;
    }
    $now = (int) round(microtime(true) * 1000);
    $groupPayload = [
        'syncOriginGroupId' => $groupId,
        'localGroupId' => isset($groupRow['sync_origin_lan_id'])
            ? (int) $groupRow['sync_origin_lan_id'] : null,
        'prefix' => (string) ($groupRow['prefix'] ?? ''),
        'startNumber' => (int) ($groupRow['start_number'] ?? 0),
        'endNumber' => (int) ($groupRow['end_number'] ?? count($serials)),
        'padWidth' => (int) ($groupRow['pad_width'] ?? 0),
        'description' => $groupRow['description'] ?? null,
        'score' => (int) ($groupRow['score'] ?? 1),
        'category' => $category,
        'generationMode' => (string) ($groupRow['generation_mode'] ?? 'range'),
        'warrantyMonths' => isset($groupRow['warranty_months']) ? (int) $groupRow['warranty_months'] : null,
        'warrantyKm' => isset($groupRow['warranty_km']) ? (int) $groupRow['warranty_km'] : null,
        'imageVerificationRequired' => !empty($groupRow['image_verification_required']),
        'createdAt' => (int) ($groupRow['created_at'] ?? $now),
        'syncUpdatedMs' => (int) ($groupRow['sync_updated_ms'] ?? $now),
    ];
    sync_buffer_enqueue(
        $conn,
        'group_upsert',
        $groupPayload,
        null,
        'group:' . $groupId . ':group_upsert'
    );

    $chunkSize = 200;
    $chunks = array_chunk(array_values($serials), $chunkSize);
    foreach ($chunks as $i => $chunk) {
        $items = [];
        foreach ($chunk as $s) {
            $items[] = [
                'serial' => $s,
                'phone' => null,
                'city' => null,
                'km' => null,
                'dateJalali' => null,
                'regSource' => 'remote_panel',
                'syncOriginGroupId' => $groupId,
                'lanGroupId' => $groupId,
                'category' => $category,
                'syncUpdatedMs' => $now,
            ];
        }
        $batch = [
            'serials' => $items,
            'syncOriginGroupId' => $groupId,
            'category' => $category,
            'syncUpdatedMs' => $now,
        ];
        sync_buffer_enqueue(
            $conn,
            'serial_batch_upsert',
            $batch,
            null,
            'group:' . $groupId . ':batch:' . $i
        );
    }
}

/**
 * @return list<array<string,mixed>>
 */
function sync_buffer_list_pending(mysqli $conn, int $limit = 5000): array
{
    sync_ensure_buffer_table($conn);
    $limit = max(1, min(5000, $limit));
    $res = $conn->query(
        "SELECT entry_id, serial, event_type, payload_json, created_at
         FROM crm_sync_buffer
         WHERE status='pending'
         ORDER BY created_at ASC
         LIMIT " . (int) $limit
    );
    $out = [];
    if (!$res) {
        return $out;
    }
    while ($row = $res->fetch_assoc()) {
        $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $out[] = [
            'entryId' => (string) $row['entry_id'],
            'type' => (string) $row['event_type'],
            'serial' => $row['serial'] !== null ? (string) $row['serial'] : null,
            'payload' => $payload,
            'createdAt' => (int) ($row['created_at'] ?? 0),
        ];
    }
    return $out;
}

function sync_buffer_count_pending(mysqli $conn): int
{
    sync_ensure_buffer_table($conn);
    $res = $conn->query("SELECT COUNT(*) AS c FROM crm_sync_buffer WHERE status='pending'");
    if ($res && ($row = $res->fetch_assoc())) {
        return (int) ($row['c'] ?? 0);
    }
    return 0;
}

/**
 * @param list<string> $entryIds
 */
function sync_buffer_ack(mysqli $conn, array $entryIds): int
{
    sync_ensure_buffer_table($conn);
    $acked = 0;
    $now = (int) round(microtime(true) * 1000);
    // One prepared statement for the whole batch; re-preparing per id made large
    // ACKs slow enough for the host to drop the connection.
    $stmt = $conn->prepare(
        "UPDATE crm_sync_buffer SET status='acked' WHERE entry_id=? AND status='pending'"
    );
    if ($stmt) {
        $conn->begin_transaction();
        foreach ($entryIds as $id) {
            $id = trim((string) $id);
            if ($id === '') {
                continue;
            }
            $stmt->bind_param('s', $id);
            $stmt->execute();
            $acked += $stmt->affected_rows;
        }
        $conn->commit();
        $stmt->close();
    }
    $conn->query(
        "INSERT INTO crm_sync_meta (meta_key, meta_value, updated_at)
         VALUES ('last_buffer_ack_ms', '" . $now . "', $now)
         ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value), updated_at=VALUES(updated_at)"
    );
    return $acked;
}
