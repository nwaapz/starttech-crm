<?php
/**
 * Apply buffer entries onto remote MySQL (force overwrite, no LWW).
 * Shared by POST /api/sync/buffer/apply.
 */
declare(strict_types=1);

require_once __DIR__ . '/../serials_write.php';

function sync_apply_resolve_remote_group_id(mysqli $conn, array $p): ?int
{
    sw_ensure_serial_groups_table($conn);
    $originRemote = isset($p['syncOriginGroupId']) ? (int) $p['syncOriginGroupId'] : 0;
    $localGroupId = isset($p['localGroupId']) ? (int) $p['localGroupId'] : 0;
    if ($originRemote > 0) {
        $stmt = $conn->prepare('SELECT id FROM serial_groups WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $originRemote);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return (int) $row['id'];
        }
    }
    if ($localGroupId > 0) {
        $stmt = $conn->prepare('SELECT id FROM serial_groups WHERE sync_origin_lan_id=? LIMIT 1');
        $stmt->bind_param('i', $localGroupId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return (int) $row['id'];
        }
    }
    return null;
}

function sync_apply_upsert_group(mysqli $conn, array $p): int
{
    sw_ensure_serial_groups_table($conn);
    $syncMs = (int) ($p['syncUpdatedMs'] ?? (int) (microtime(true) * 1000));
    $localGroupId = isset($p['localGroupId']) ? (int) $p['localGroupId'] : 0;
    $existingId = sync_apply_resolve_remote_group_id($conn, $p);

    $prefix = (string) ($p['prefix'] ?? '');
    $start = (int) ($p['startNumber'] ?? 0);
    $end = (int) ($p['endNumber'] ?? 0);
    $pad = (int) ($p['padWidth'] ?? 0);
    $description = $p['description'] ?? null;
    $score = (int) ($p['score'] ?? 1);
    $category = (string) ($p['category'] ?? 'end_user_client');
    if ($category !== 'seller_to_end_user' && $category !== 'end_user_client') {
        $category = 'end_user_client';
    }
    $mode = (string) ($p['generationMode'] ?? 'range');
    $warrantyMonths = array_key_exists('warrantyMonths', $p) && $p['warrantyMonths'] !== null
        ? (int) $p['warrantyMonths'] : null;
    $warrantyKm = array_key_exists('warrantyKm', $p) && $p['warrantyKm'] !== null
        ? (int) $p['warrantyKm'] : null;
    $iv = !empty($p['imageVerificationRequired']) ? 1 : 0;
    $createdAt = (int) ($p['createdAt'] ?? $syncMs);
    $originLanVal = $localGroupId > 0 ? $localGroupId : 0;
    $descVal = $description;

    $wmSql = $warrantyMonths === null ? 'NULL' : (string) (int) $warrantyMonths;
    $wkSql = $warrantyKm === null ? 'NULL' : (string) (int) $warrantyKm;
    $descEsc = $descVal === null
        ? 'NULL'
        : ("'" . $conn->real_escape_string((string) $descVal) . "'");

    if ($existingId !== null) {
        $originSql = $originLanVal > 0
            ? (string) $originLanVal
            : 'sync_origin_lan_id';
        $sql = 'UPDATE serial_groups SET prefix=\'' . $conn->real_escape_string($prefix) . '\',
            start_number=' . (int) $start . ', end_number=' . (int) $end . ', pad_width=' . (int) $pad . ',
            description=' . $descEsc . ', score=' . (int) $score . ',
            category=\'' . $conn->real_escape_string($category) . '\',
            generation_mode=\'' . $conn->real_escape_string($mode) . '\',
            warranty_months=' . $wmSql . ', warranty_km=' . $wkSql . ',
            image_verification_required=' . (int) $iv . ',
            sync_updated_ms=' . (int) $syncMs . ',
            sync_origin_lan_id=' . $originSql . '
            WHERE id=' . (int) $existingId;
        $conn->query($sql);
        return $existingId;
    }

    $originSql = $originLanVal > 0 ? (string) $originLanVal : 'NULL';
    $sql = 'INSERT INTO serial_groups
        (prefix, start_number, end_number, pad_width, description, score, category,
         generation_mode, warranty_months, warranty_km, image_verification_required,
         created_at, sync_updated_ms, sync_origin_lan_id)
        VALUES (
            \'' . $conn->real_escape_string($prefix) . '\',
            ' . (int) $start . ', ' . (int) $end . ', ' . (int) $pad . ',
            ' . $descEsc . ', ' . (int) $score . ',
            \'' . $conn->real_escape_string($category) . '\',
            \'' . $conn->real_escape_string($mode) . '\',
            ' . $wmSql . ', ' . $wkSql . ', ' . (int) $iv . ',
            ' . (int) $createdAt . ', ' . (int) $syncMs . ', ' . $originSql . '
        )';
    $conn->query($sql);
    return (int) $conn->insert_id;
}

function sync_apply_delete_group(mysqli $conn, array $p): void
{
    sw_ensure_serial_groups_table($conn);
    $table = serials_table($conn);
    sw_ensure_columns($conn, $table);
    $syncMs = (int) ($p['syncUpdatedMs'] ?? (int) (microtime(true) * 1000));
    $remoteId = sync_apply_resolve_remote_group_id($conn, $p);
    $localGroupId = isset($p['localGroupId']) ? (int) $p['localGroupId'] : null;
    if ($remoteId === null) {
        if ($localGroupId) {
            sw_record_group_delete_tombstone($conn, -$localGroupId, $localGroupId, $syncMs);
        }
        return;
    }
    $delSerials = $conn->prepare("DELETE FROM `$table` WHERE lan_group_id = ?");
    if ($delSerials) {
        $delSerials->bind_param('i', $remoteId);
        $delSerials->execute();
        $delSerials->close();
    }
    $delGroup = $conn->prepare('DELETE FROM serial_groups WHERE id = ?');
    if ($delGroup) {
        $delGroup->bind_param('i', $remoteId);
        $delGroup->execute();
        $delGroup->close();
    }
    sw_record_group_delete_tombstone($conn, $remoteId, $localGroupId, $syncMs);
}

/** Force overwrite upsert (no LWW skip). */
function sync_apply_upsert_serial(mysqli $conn, array $p): void
{
    $serial = trim((string) ($p['serial'] ?? ''));
    if ($serial === '') {
        return;
    }
    $phone = $p['phone'] ?? null;
    if ($phone === '') {
        $phone = null;
    }
    $km = array_key_exists('km', $p) ? $p['km'] : null;
    $city = $p['city'] ?? null;
    $dateJalali = $p['dateJalali'] ?? ($p['date'] ?? null);
    $regSource = $p['regSource'] ?? null;
    $category = $p['category'] ?? null;
    if ($category !== null) {
        $category = trim((string) $category);
        if ($category !== 'seller_to_end_user' && $category !== 'end_user_client') {
            $category = 'end_user_client';
        }
    }
    $score = array_key_exists('score', $p) ? (int) $p['score'] : null;
    $syncMs = (int) ($p['syncUpdatedMs'] ?? (int) (microtime(true) * 1000));

    $lanGroupId = sync_apply_resolve_remote_group_id($conn, $p);
    if ($lanGroupId === null && isset($p['lanGroupId']) && (int) $p['lanGroupId'] > 0) {
        $maybe = (int) $p['lanGroupId'];
        $chk = $conn->prepare('SELECT id FROM serial_groups WHERE id=? OR sync_origin_lan_id=? LIMIT 1');
        $chk->bind_param('ii', $maybe, $maybe);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        $chk->close();
        if ($row) {
            $lanGroupId = (int) $row['id'];
        }
    }

    $table = 'old_serials';
    $stmt = $conn->prepare('SELECT id FROM old_serials WHERE UPPER(serial)=UPPER(?) LIMIT 1');
    $stmt->bind_param('s', $serial);
    $stmt->execute();
    $existsOld = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$existsOld) {
        $check = $conn->query("SHOW TABLES LIKE 'new_serials'");
        if ($check && $check->num_rows > 0) {
            $stmt = $conn->prepare('SELECT id FROM new_serials WHERE UPPER(serial)=UPPER(?) LIMIT 1');
            $stmt->bind_param('s', $serial);
            $stmt->execute();
            $existsNew = (bool) $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($existsNew) {
                $table = 'new_serials';
            }
        }
    }

    sw_ensure_columns($conn, $table);
    $hasDateJalali = column_exists($conn, $table, 'date_jalali');
    $hasSync = column_exists($conn, $table, 'sync_updated_ms');
    $hasCity = column_exists($conn, $table, 'city');
    $hasReg = column_exists($conn, $table, 'reg_source');
    $hasGroup = column_exists($conn, $table, 'lan_group_id');
    $hasCategory = column_exists($conn, $table, 'category');
    $hasScore = column_exists($conn, $table, 'score');
    $timeSql = 'NOW()';

    $stmt = $conn->prepare("SELECT id FROM `$table` WHERE UPPER(serial)=UPPER(?) LIMIT 1");
    $stmt->bind_param('s', $serial);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $doUpdate = (bool) $existing;

    if ($doUpdate) {
        $sets = ['phone=?', 'km=?', "time=$timeSql"];
        $types = 'si';
        $params = [$phone, $km];
        if ($hasCity) {
            $sets[] = 'city=?';
            $types .= 's';
            $params[] = $city;
        }
        if ($hasDateJalali) {
            $sets[] = 'date_jalali=?';
            $types .= 's';
            $params[] = $dateJalali;
        }
        if ($hasSync) {
            $sets[] = 'sync_updated_ms=?';
            $types .= 'i';
            $params[] = $syncMs;
        }
        if ($hasReg) {
            $sets[] = 'reg_source=?';
            $types .= 's';
            $params[] = $regSource;
        }
        if ($hasGroup && $lanGroupId !== null) {
            $sets[] = 'lan_group_id=?';
            $types .= 'i';
            $params[] = $lanGroupId;
        }
        if ($hasCategory && $category !== null) {
            $sets[] = 'category=?';
            $types .= 's';
            $params[] = $category;
        }
        if ($hasScore && $score !== null) {
            $sets[] = 'score=?';
            $types .= 'i';
            $params[] = $score;
        }
        $types .= 's';
        $params[] = $serial;
        $sql = 'UPDATE `' . $table . '` SET ' . implode(',', $sets) . ' WHERE UPPER(serial)=UPPER(?)';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    } else {
        $cols = ['serial', 'phone', 'km', 'time'];
        $vals = ['?', '?', '?', $timeSql];
        $types = 'ssi';
        $params = [$serial, $phone, $km];
        if ($hasCity) {
            $cols[] = 'city';
            $vals[] = '?';
            $types .= 's';
            $params[] = $city;
        }
        if ($hasDateJalali) {
            $cols[] = 'date_jalali';
            $vals[] = '?';
            $types .= 's';
            $params[] = $dateJalali;
        }
        if ($hasSync) {
            $cols[] = 'sync_updated_ms';
            $vals[] = '?';
            $types .= 'i';
            $params[] = $syncMs;
        }
        if ($hasReg) {
            $cols[] = 'reg_source';
            $vals[] = '?';
            $params[] = $regSource;
            $types .= 's';
        }
        if ($hasGroup && $lanGroupId !== null) {
            $cols[] = 'lan_group_id';
            $vals[] = '?';
            $types .= 'i';
            $params[] = $lanGroupId;
        }
        if ($hasCategory) {
            $cols[] = 'category';
            $vals[] = '?';
            $types .= 's';
            $params[] = $category ?? 'end_user_client';
        }
        if ($hasScore && $score !== null) {
            $cols[] = 'score';
            $vals[] = '?';
            $types .= 'i';
            $params[] = $score;
        }
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', $vals) . ')';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Apply one buffer entry. Returns true if applied (or already seen).
 *
 * @param array<string,mixed> $entry {entryId,type,payload}
 */
function sync_apply_entry(mysqli $conn, array $entry): bool
{
    $eventId = (string) ($entry['entryId'] ?? $entry['eventId'] ?? '');
    $type = (string) ($entry['type'] ?? '');
    $payload = $entry['payload'] ?? [];
    if ($eventId === '' || $type === '' || !is_array($payload)) {
        return false;
    }

    $chk = $conn->prepare('SELECT event_id FROM crm_sync_events WHERE event_id=? LIMIT 1');
    $chk->bind_param('s', $eventId);
    $chk->execute();
    if ($chk->get_result()->fetch_assoc()) {
        $chk->close();
        return true;
    }
    $chk->close();

    if ($type === 'serial_upsert') {
        sync_apply_upsert_serial($conn, $payload);
        require_once dirname(__DIR__) . '/activity_store.php';
        $serial = trim((string) ($payload['serial'] ?? ''));
        $phone = (string) ($payload['phone'] ?? '');
        if ($serial !== '' && $phone !== '') {
            crm_activity_record($conn, 'registered', [
                'serial' => $serial,
                'phone' => $phone,
                'date' => (string) ($payload['dateJalali'] ?? $payload['date'] ?? ''),
                'city' => $payload['city'] ?? null,
                'km' => $payload['km'] ?? null,
            ], isset($payload['syncUpdatedMs']) ? (int) $payload['syncUpdatedMs'] : null);
        }
    } elseif ($type === 'serial_batch_upsert') {
        $list = $payload['serials'] ?? [];
        if (is_array($list)) {
            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (!isset($item['localGroupId']) && isset($payload['localGroupId'])) {
                    $item['localGroupId'] = $payload['localGroupId'];
                }
                if (!isset($item['category']) && isset($payload['category'])) {
                    $item['category'] = $payload['category'];
                }
                if (!isset($item['syncOriginGroupId']) && isset($payload['syncOriginGroupId'])) {
                    $item['syncOriginGroupId'] = $payload['syncOriginGroupId'];
                }
                sync_apply_upsert_serial($conn, $item);
            }
        }
    } elseif ($type === 'group_upsert') {
        sync_apply_upsert_group($conn, $payload);
    } elseif ($type === 'group_delete') {
        sync_apply_delete_group($conn, $payload);
    } else {
        return false;
    }

    $now = (int) (microtime(true) * 1000);
    $ins = $conn->prepare('INSERT INTO crm_sync_events (event_id, event_type, applied_at) VALUES (?,?,?)');
    $ins->bind_param('ssi', $eventId, $type, $now);
    $ins->execute();
    $ins->close();
    return true;
}
