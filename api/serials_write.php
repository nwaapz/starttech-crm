<?php
/**
 * Remote serial generation for the CRM web panel.
 *
 * Implements the same contract as the Android LAN SerialGenerator:
 *   POST /serials/batch          (range)
 *   POST /serials/complex-batch  (random letters+digits)
 *   POST /serials/import-batch   (explicit list / Excel)
 *
 * Also owns the remote `serial_groups` table used by the cards UI:
 *   GET    /serial-groups
 *   GET    /serial-groups/{id}/serials
 *   PUT    /serial-groups/{id}
 *   DELETE /serial-groups/{id}
 *
 * Serials land in old_serials with lan_group_id pointing at the group card.
 *
 * Requires helpers from bootstrap.php: json_out, json_error, body_json,
 * table_exists, column_exists, serials_table, is_registered_sql, stmt_fetch_assoc.
 */
declare(strict_types=1);

const SW_MAX_SERIAL_LENGTH = 32;
const SW_MIN_COMPLEX_LENGTH = 6;
const SW_MAX_BATCH = 500000;
const SW_CHUNK = 2000;
const SW_COMPLEX_LETTERS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
const SW_COMPLEX_DIGITS = '23456789';
const SW_STOM_MCODE_SCORE = 10;
const SW_COMPLEX_PREFIX = 'RND';
const SW_IMPORT_PREFIX = 'IMP';
/** Synthetic group ids for legacy inventory not linked to a remote serial_groups card. */
const SW_LEGACY_CLIENT_GROUP_ID = 900001;
const SW_LEGACY_SELLER_GROUP_ID = 900002;

/** Uppercase + trim, matching SerialNormalizer. */
function sw_norm(string $value): string
{
    return strtoupper(trim($value));
}

/** @return array{0:int|null,1:int|null} normalized [months, km]; throws on both empty. */
function sw_require_warranty($months, $km): array
{
    $m = (is_numeric($months) && (int) $months > 0) ? (int) $months : null;
    $k = (is_numeric($km) && (int) $km > 0) ? (int) $km : null;
    if ($m === null && $k === null) {
        json_error('حداقل یکی از مدت گارانتی (ماه) یا کیلومتر گارانتی باید وارد شود.', 400);
    }
    return [$m, $k];
}

function sw_require_category($value): string
{
    $trimmed = trim((string) $value);
    if ($trimmed === '') {
        return 'end_user_client';
    }
    if ($trimmed !== 'end_user_client' && $trimmed !== 'seller_to_end_user') {
        json_error('Category must be "end_user_client" or "seller_to_end_user".', 400);
    }
    return $trimmed;
}

function sw_require_score($value): int
{
    $score = is_numeric($value) ? (int) $value : 1;
    if ($score < 1) {
        json_error('Score must be at least 1.', 400);
    }
    return $score;
}

function sw_build_serial(string $prefix, int $number, int $padWidth): string
{
    $numberPart = $padWidth > 0
        ? str_pad((string) $number, $padWidth, '0', STR_PAD_LEFT)
        : (string) $number;
    $serial = $prefix . $numberPart;
    if (strlen($serial) > SW_MAX_SERIAL_LENGTH) {
        json_error("Serial \"$serial\" exceeds " . SW_MAX_SERIAL_LENGTH . ' characters.', 400);
    }
    if (!preg_match('/^[A-Z0-9]+$/', $serial)) {
        json_error("Serial \"$serial\" contains invalid characters.", 400);
    }
    return $serial;
}

/** Create remote serial_groups table (LAN-compatible metadata for cards). */
function sw_ensure_serial_groups_table(mysqli $conn): void
{
    $ok = $conn->query(
        "CREATE TABLE IF NOT EXISTS serial_groups (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            prefix VARCHAR(64) NOT NULL DEFAULT '',
            start_number INT NOT NULL DEFAULT 0,
            end_number INT NOT NULL DEFAULT 0,
            pad_width INT NOT NULL DEFAULT 0,
            description VARCHAR(255) DEFAULT NULL,
            score INT NOT NULL DEFAULT 1,
            category VARCHAR(32) NOT NULL DEFAULT 'end_user_client',
            generation_mode VARCHAR(16) NOT NULL DEFAULT 'range',
            warranty_months INT DEFAULT NULL,
            warranty_km INT DEFAULT NULL,
            image_verification_required TINYINT(1) NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL,
            sync_updated_ms BIGINT DEFAULT NULL,
            sync_origin_lan_id BIGINT DEFAULT NULL,
            INDEX idx_serial_groups_created (created_at),
            INDEX idx_serial_groups_sync_origin (sync_origin_lan_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if ($ok === false) {
        json_error('DB schema error (serial_groups): ' . $conn->error, 500);
    }
    // Additive columns for older installs
    $adds = [
        'sync_updated_ms' => 'BIGINT DEFAULT NULL',
        'sync_origin_lan_id' => 'BIGINT DEFAULT NULL',
    ];
    foreach ($adds as $col => $def) {
        if (!column_exists($conn, 'serial_groups', $col)) {
            $conn->query("ALTER TABLE serial_groups ADD COLUMN `$col` $def");
        }
    }
    $idx = $conn->query("SHOW INDEX FROM serial_groups WHERE Key_name = 'idx_serial_groups_sync_origin'");
    if ($idx && $idx->num_rows === 0) {
        @$conn->query('ALTER TABLE serial_groups ADD INDEX idx_serial_groups_sync_origin (sync_origin_lan_id)');
    }
    // Backfill so existing groups appear on first phone pull.
    @$conn->query(
        'UPDATE serial_groups SET sync_updated_ms = created_at
         WHERE sync_updated_ms IS NULL OR sync_updated_ms = 0'
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS sync_group_deletes (
            remote_group_id BIGINT NOT NULL PRIMARY KEY,
            sync_origin_lan_id BIGINT DEFAULT NULL,
            sync_updated_ms BIGINT NOT NULL,
            INDEX idx_sync_group_deletes_ms (sync_updated_ms)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/** Record a group deletion tombstone for phone pull. */
function sw_record_group_delete_tombstone(
    mysqli $conn,
    int $remoteGroupId,
    ?int $syncOriginLanId,
    ?int $syncUpdatedMs = null
): void {
    sw_ensure_serial_groups_table($conn);
    $ms = $syncUpdatedMs ?? (int) round(microtime(true) * 1000);
    $origin = $syncOriginLanId ?? 0;
    $stmt = $conn->prepare(
        'INSERT INTO sync_group_deletes (remote_group_id, sync_origin_lan_id, sync_updated_ms)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
           sync_origin_lan_id = IF(VALUES(sync_origin_lan_id) = 0, sync_origin_lan_id, VALUES(sync_origin_lan_id)),
           sync_updated_ms = GREATEST(sync_updated_ms, VALUES(sync_updated_ms))'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('iii', $remoteGroupId, $origin, $ms);
    $stmt->execute();
    $stmt->close();
}

/** Ensure inventory columns used by remote serial generation exist. */
function sw_ensure_columns(mysqli $conn, string $table): void
{
    sw_ensure_serial_groups_table($conn);
    $adds = [
        'city' => "VARCHAR(100) DEFAULT NULL",
        'km' => "INT DEFAULT NULL",
        'description' => "VARCHAR(255) DEFAULT NULL",
        'category' => "VARCHAR(32) NOT NULL DEFAULT 'end_user_client'",
        'score' => "INT NOT NULL DEFAULT 0",
        'warranty_months' => "INT DEFAULT NULL",
        'warranty_km' => "INT DEFAULT NULL",
        'image_verification_required' => "TINYINT(1) NOT NULL DEFAULT 0",
        'lan_group_id' => "BIGINT DEFAULT NULL",
        'reg_source' => "VARCHAR(16) DEFAULT NULL",
        'sync_updated_ms' => "BIGINT DEFAULT NULL",
    ];
    foreach ($adds as $col => $def) {
        if (!column_exists($conn, $table, $col)) {
            $conn->query("ALTER TABLE `$table` ADD COLUMN `$col` $def");
        }
    }
    serial_ensure_perf_indexes($conn, $table);

    // Backfill timestamps so unused + registered rows are pullable on first sync.
    // Invalid/zero MySQL dates make UNIX_TIMESTAMP NULL — fall back to row id.
    if (column_exists($conn, $table, 'sync_updated_ms')) {
        @$conn->query(
            "UPDATE `$table` SET sync_updated_ms = COALESCE(
                NULLIF(UNIX_TIMESTAMP(NULLIF(time, '0000-00-00 00:00:00')), 0) * 1000,
                id
             )
             WHERE sync_updated_ms IS NULL OR sync_updated_ms = 0"
        );
        // Unused stock: stable low timestamps (1e12+id) so a full sync pulls
        // inventory before old registrations. Also fixes rows wrongly stamped with NOW().
        @$conn->query(
            "UPDATE `$table` SET sync_updated_ms = (1000000000000 + id)
             WHERE (phone IS NULL OR TRIM(phone) = '')
               AND (
                 sync_updated_ms IS NULL OR sync_updated_ms = 0
                 OR sync_updated_ms > 1700000000000
               )"
        );
    }

    // One-time: attach previously generated remote serials (no group) to a card.
    sw_backfill_ungrouped_remote_serials($conn, $table);
}

/**
 * Serials created before serial_groups existed have lan_group_id NULL.
 * Bundle them into one import-style card so they appear in the UI.
 */
function sw_backfill_ungrouped_remote_serials(mysqli $conn, string $table): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $hasLan = column_exists($conn, $table, 'lan_group_id');
    if (!$hasLan) {
        return;
    }

    $countSql = "SELECT COUNT(*) AS c FROM `$table`
                 WHERE lan_group_id IS NULL AND reg_source = 'remote_panel'";
    $r = $conn->query($countSql);
    $c = $r ? (int) ($r->fetch_assoc()['c'] ?? 0) : 0;
    if ($c <= 0) {
        return;
    }

    // Prefer a single category if homogeneous; otherwise end_user_client.
    $cat = 'end_user_client';
    if (column_exists($conn, $table, 'category')) {
        $cr = $conn->query(
            "SELECT category, COUNT(*) AS n FROM `$table`
             WHERE lan_group_id IS NULL AND reg_source = 'remote_panel'
             GROUP BY category ORDER BY n DESC LIMIT 1"
        );
        if ($cr && ($row = $cr->fetch_assoc())) {
            $maybe = (string) ($row['category'] ?? '');
            if ($maybe === 'end_user_client' || $maybe === 'seller_to_end_user') {
                $cat = $maybe;
            }
        }
    }

    $groupId = sw_create_group(
        $conn,
        SW_IMPORT_PREFIX,
        0,
        $c,
        0,
        'سریال‌های قبلی بدون کارت (بازسازی خودکار)',
        1,
        $cat,
        'import',
        null,
        null,
        false
    );

    $conn->query(
        "UPDATE `$table` SET lan_group_id = " . (int) $groupId . "
         WHERE lan_group_id IS NULL AND reg_source = 'remote_panel'"
    );
    $affected = $conn->affected_rows;
    if ($affected > 0) {
        sw_update_group_end($conn, $groupId, $affected);
    } else {
        sw_delete_group_row($conn, $groupId);
    }
}

function sw_build_group_label(
    string $mode,
    string $prefix,
    int $start,
    int $end,
    int $padWidth
): string {
    if ($mode === 'complex') {
        $userPrefix = ($prefix !== '' && strcasecmp($prefix, SW_COMPLEX_PREFIX) !== 0) ? $prefix : null;
        if ($userPrefix !== null) {
            return "تصادفی پیشوند {$userPrefix} • {$padWidth} کاراکتر × {$end}";
        }
        return "تصادفی {$padWidth} کاراکتر × {$end}";
    }
    if ($mode === 'import') {
        return "وارداتی × {$end}";
    }
    return sw_build_serial($prefix, $start, $padWidth) . ' – ' . sw_build_serial($prefix, $end, $padWidth);
}

function sw_create_group(
    mysqli $conn,
    string $prefix,
    int $start,
    int $end,
    int $padWidth,
    ?string $description,
    int $score,
    string $category,
    string $mode,
    ?int $warrantyMonths,
    ?int $warrantyKm,
    bool $imageVerification
): int {
    sw_ensure_serial_groups_table($conn);
    $createdAt = (int) round(microtime(true) * 1000);
    $ivFlag = $imageVerification ? 1 : 0;
    $sql = "INSERT INTO serial_groups
        (prefix, start_number, end_number, pad_width, description, score, category,
         generation_mode, warranty_months, warranty_km, image_verification_required, created_at,
         sync_updated_ms)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        json_error('DB prepare failed (serial_groups): ' . $conn->error, 500);
    }
    $stmt->bind_param(
        'siiisissiiiii',
        $prefix,
        $start,
        $end,
        $padWidth,
        $description,
        $score,
        $category,
        $mode,
        $warrantyMonths,
        $warrantyKm,
        $ivFlag,
        $createdAt,
        $createdAt
    );
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        json_error('Failed to create serial group: ' . $err, 500);
    }
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function sw_delete_group_row(mysqli $conn, int $groupId): void
{
    $stmt = $conn->prepare('DELETE FROM serial_groups WHERE id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $groupId);
        $stmt->execute();
        $stmt->close();
    }
}

function sw_update_group_end(mysqli $conn, int $groupId, int $endNumber): void
{
    $syncMs = (int) round(microtime(true) * 1000);
    $stmt = $conn->prepare('UPDATE serial_groups SET end_number = ?, sync_updated_ms = ? WHERE id = ?');
    if ($stmt) {
        $stmt->bind_param('iii', $endNumber, $syncMs, $groupId);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * @param string[] $serials
 * @return array<string,bool>
 */
function sw_existing_serials(mysqli $conn, string $table, array $serials): array
{
    $existing = [];
    $chunks = array_chunk(array_values($serials), SW_CHUNK);
    foreach ($chunks as $chunk) {
        if ($chunk === []) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $types = str_repeat('s', count($chunk));
        $sql = "SELECT UPPER(serial) AS s FROM `$table` WHERE UPPER(serial) IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            json_error('DB prepare failed: ' . $conn->error, 500);
        }
        $stmt->bind_param($types, ...$chunk);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            json_error('DB execute failed: ' . $err, 500);
        }
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $existing[(string) $row['s']] = true;
        }
        $stmt->close();
    }
    return $existing;
}

/**
 * @param string[] $serials
 */
function sw_insert_serials(
    mysqli $conn,
    string $table,
    array $serials,
    int $groupId,
    string $category,
    int $score,
    ?int $warrantyMonths,
    ?int $warrantyKm,
    bool $imageVerification,
    ?string $city,
    ?int $km,
    ?string $description
): int {
    if ($serials === []) {
        return 0;
    }
    $nowMs = (int) round(microtime(true) * 1000);
    $ivFlag = $imageVerification ? 1 : 0;
    $inserted = 0;

    $sql = "INSERT IGNORE INTO `$table`
        (serial, category, score, warranty_months, warranty_km, image_verification_required,
         city, km, description, lan_group_id, reg_source, sync_updated_ms)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'remote_panel', ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        json_error('DB prepare failed: ' . $conn->error, 500);
    }

    $conn->begin_transaction();
    try {
        foreach ($serials as $serial) {
            $stmt->bind_param(
                'ssiiiisisii',
                $serial,
                $category,
                $score,
                $warrantyMonths,
                $warrantyKm,
                $ivFlag,
                $city,
                $km,
                $description,
                $groupId,
                $nowMs
            );
            if (!$stmt->execute()) {
                throw new RuntimeException('Insert failed: ' . $stmt->error);
            }
            $inserted += $stmt->affected_rows > 0 ? 1 : 0;
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        $stmt->close();
        json_error($e->getMessage(), 500);
    }
    $stmt->close();

    sw_mcode_preregister($conn, $serials);

    return $inserted;
}

/** @param string[] $serials */
function sw_mcode_preregister(mysqli $conn, array $serials): void
{
    if (!table_exists($conn, 'Mcode')) {
        return;
    }
    $targets = [];
    foreach ($serials as $serial) {
        if (preg_match('/^S(\d+)$/', $serial, $m)) {
            $num = (int) $m[1];
            if ($num >= 440000 && $num <= 450000) {
                $targets[] = $serial;
            }
        }
    }
    if ($targets === []) {
        return;
    }
    $sql = "INSERT INTO Mcode (serial, phone, time, score, description)
            VALUES (?, '0', NOW(), ?, 'S440-S450')
            ON DUPLICATE KEY UPDATE score = VALUES(score), description = VALUES(description)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }
    $score = SW_STOM_MCODE_SCORE;
    foreach ($targets as $serial) {
        $stmt->bind_param('si', $serial, $score);
        $stmt->execute();
    }
    $stmt->close();
}

function sw_compress_ranges(array $numbers): array
{
    sort($numbers);
    $numbers = array_values(array_unique($numbers));
    $ranges = [];
    if ($numbers === []) {
        return $ranges;
    }
    $rangeStart = $numbers[0];
    $rangeEnd = $numbers[0];
    $count = count($numbers);
    for ($i = 1; $i < $count; $i++) {
        if ($numbers[$i] === $rangeEnd + 1) {
            $rangeEnd = $numbers[$i];
        } else {
            $ranges[] = [$rangeStart, $rangeEnd];
            $rangeStart = $numbers[$i];
            $rangeEnd = $numbers[$i];
        }
    }
    $ranges[] = [$rangeStart, $rangeEnd];
    return $ranges;
}

function sw_group_to_api(array $row, int $total, int $used): array
{
    $prefix = (string) ($row['prefix'] ?? '');
    $start = (int) ($row['start_number'] ?? 0);
    $end = (int) ($row['end_number'] ?? 0);
    $pad = (int) ($row['pad_width'] ?? 0);
    $mode = (string) ($row['generation_mode'] ?? 'range');
    $unused = max(0, $total - $used);
    return [
        'id' => (int) $row['id'],
        'prefix' => $prefix,
        'startNumber' => $start,
        'endNumber' => $end,
        'padWidth' => $pad,
        'rangeLabel' => sw_build_group_label($mode, $prefix, $start, $end, $pad),
        'description' => isset($row['description']) && $row['description'] !== '' ? (string) $row['description'] : null,
        'score' => (int) ($row['score'] ?? 1),
        'category' => (string) ($row['category'] ?? 'end_user_client'),
        'generationMode' => $mode,
        'warrantyMonths' => isset($row['warranty_months']) && $row['warranty_months'] !== null ? (int) $row['warranty_months'] : null,
        'warrantyKm' => isset($row['warranty_km']) && $row['warranty_km'] !== null ? (int) $row['warranty_km'] : null,
        'imageVerificationRequired' => !empty($row['image_verification_required']),
        'total' => $total,
        'used' => $used,
        'unused' => $unused,
        'salePercentage' => $total > 0 ? round(100.0 * $used / $total, 1) : 0,
        'createdAt' => (int) ($row['created_at'] ?? 0),
    ];
}

/** Serials that are not members of a remote serial_groups card (legacy S/M inventory). */
function sw_ungrouped_sql(string $table): string
{
    return "(lan_group_id IS NULL OR lan_group_id NOT IN (SELECT id FROM serial_groups))";
}

function sw_legacy_group_id_for_category(string $category): int
{
    return $category === 'seller_to_end_user'
        ? SW_LEGACY_SELLER_GROUP_ID
        : SW_LEGACY_CLIENT_GROUP_ID;
}

function sw_legacy_category_for_group_id(int $id): ?string
{
    if ($id === SW_LEGACY_SELLER_GROUP_ID) {
        return 'seller_to_end_user';
    }
    if ($id === SW_LEGACY_CLIENT_GROUP_ID) {
        return 'end_user_client';
    }
    return null;
}

function sw_is_legacy_group_id(int $id): bool
{
    return $id === SW_LEGACY_CLIENT_GROUP_ID || $id === SW_LEGACY_SELLER_GROUP_ID;
}

/**
 * Category buckets for inventory that has no remote group card
 * (the previous ~400k S/M serials that lived only in old_serials).
 */
function sw_append_legacy_inventory_cards(mysqli $conn, string $table, array &$items): void
{
    $hasCategory = column_exists($conn, $table, 'category');
    $hasLan = column_exists($conn, $table, 'lan_group_id');
    if (!$hasLan) {
        // No lan_group_id column → treat entire table as legacy by category/all.
        if ($hasCategory) {
            $res = $conn->query(
                "SELECT category,
                        COUNT(*) AS total,
                        SUM(CASE WHEN " . is_registered_sql() . " THEN 1 ELSE 0 END) AS used
                 FROM `$table` GROUP BY category"
            );
        } else {
            $res = $conn->query(
                "SELECT 'end_user_client' AS category,
                        COUNT(*) AS total,
                        SUM(CASE WHEN " . is_registered_sql() . " THEN 1 ELSE 0 END) AS used
                 FROM `$table`"
            );
        }
    } else {
        $ungrouped = sw_ungrouped_sql($table);
        if ($hasCategory) {
            $res = $conn->query(
                "SELECT category,
                        COUNT(*) AS total,
                        SUM(CASE WHEN " . is_registered_sql() . " THEN 1 ELSE 0 END) AS used
                 FROM `$table`
                 WHERE $ungrouped
                 GROUP BY category"
            );
        } else {
            $res = $conn->query(
                "SELECT 'end_user_client' AS category,
                        COUNT(*) AS total,
                        SUM(CASE WHEN " . is_registered_sql() . " THEN 1 ELSE 0 END) AS used
                 FROM `$table`
                 WHERE $ungrouped"
            );
        }
    }

    if (!$res) {
        return;
    }

    while ($row = $res->fetch_assoc()) {
        $total = (int) ($row['total'] ?? 0);
        if ($total <= 0) {
            continue;
        }
        $used = (int) ($row['used'] ?? 0);
        $cat = (string) ($row['category'] ?: 'end_user_client');
        if ($cat !== 'seller_to_end_user' && $cat !== 'end_user_client') {
            $cat = 'end_user_client';
        }
        $isSeller = $cat === 'seller_to_end_user';
        $items[] = [
            'id' => sw_legacy_group_id_for_category($cat),
            'prefix' => $isSeller ? 'SELLER' : 'CLIENT',
            'startNumber' => 0,
            'endNumber' => 0,
            'padWidth' => 0,
            'rangeLabel' => $isSeller ? 'موجودی فروشنده (S/M)' : 'موجودی مشتری نهایی',
            'description' => $isSeller
                ? 'سریال‌های قبلی فروشنده / M و S۴۴۰–S۴۵۰ بدون کارت تولید'
                : 'سریال‌های قبلی مشتری نهایی بدون کارت تولید',
            'score' => 0,
            'category' => $cat,
            'generationMode' => 'import',
            'warrantyMonths' => null,
            'warrantyKm' => null,
            'imageVerificationRequired' => false,
            'total' => $total,
            'used' => $used,
            'unused' => max(0, $total - $used),
            'salePercentage' => $total > 0 ? round(100.0 * $used / $total, 1) : 0,
            'createdAt' => 0,
        ];
    }
}

function sw_list_groups(mysqli $conn): void
{
    $table = serials_table($conn);
    sw_ensure_columns($conn, $table);

    $res = $conn->query('SELECT * FROM serial_groups ORDER BY created_at DESC, id DESC');
    if (!$res) {
        json_error('Failed to list serial groups: ' . $conn->error, 500);
    }

    $countsByGroup = [];
    $registeredSql = is_registered_sql();
    $countRes = $conn->query(
        "SELECT lan_group_id AS gid,
                COUNT(*) AS total,
                SUM(CASE WHEN $registeredSql THEN 1 ELSE 0 END) AS used
         FROM `$table`
         WHERE lan_group_id IS NOT NULL
         GROUP BY lan_group_id"
    );
    if ($countRes) {
        while ($cRow = $countRes->fetch_assoc()) {
            $countsByGroup[(int) ($cRow['gid'] ?? 0)] = $cRow;
        }
    }
    if (function_exists('crm_debug_mark')) {
        crm_debug_mark('serial_groups_counts', count($countsByGroup) . ' groups');
    }

    $items = [];
    while ($row = $res->fetch_assoc()) {
        $gid = (int) $row['id'];
        $c = $countsByGroup[$gid] ?? null;
        $total = (int) ($c['total'] ?? 0);
        $used = (int) ($c['used'] ?? 0);
        $items[] = sw_group_to_api($row, $total, $used);
    }

    // Keep the old category inventory cards for S/M stock that has no remote group.
    sw_append_legacy_inventory_cards($conn, $table, $items);
    if (function_exists('crm_debug_mark')) {
        crm_debug_mark('serial_groups_list', count($items) . ' cards');
    }

    json_out(['items' => $items]);
}

function sw_list_group_serials(mysqli $conn, int $groupId): void
{
    $table = serials_table($conn);
    sw_ensure_columns($conn, $table);

    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    $serials = [];

    if (sw_is_legacy_group_id($groupId)) {
        $category = sw_legacy_category_for_group_id($groupId);
        $ungrouped = sw_ungrouped_sql($table);
        $hasCategory = column_exists($conn, $table, 'category');
        if ($hasCategory && $category !== null) {
            $sql = "SELECT serial FROM `$table` WHERE $ungrouped AND category = ? ORDER BY serial ASC";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                json_error('DB prepare failed: ' . $conn->error, 500);
            }
            $stmt->bind_param('s', $category);
        } else {
            $sql = "SELECT serial FROM `$table` WHERE $ungrouped ORDER BY serial ASC";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                json_error('DB prepare failed: ' . $conn->error, 500);
            }
        }
        $stmt->execute();
        if (method_exists($stmt, 'get_result')) {
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $s = trim((string) ($row['serial'] ?? ''));
                if ($s !== '') {
                    $serials[] = $s;
                }
            }
        } else {
            $stmt->bind_result($serialCol);
            while ($stmt->fetch()) {
                $s = trim((string) $serialCol);
                if ($s !== '') {
                    $serials[] = $s;
                }
            }
        }
        $stmt->close();
        json_out(['groupId' => $groupId, 'serials' => $serials]);
    }

    $chk = $conn->prepare('SELECT id FROM serial_groups WHERE id = ? LIMIT 1');
    if (!$chk) {
        json_error('DB prepare failed: ' . $conn->error, 500);
    }
    $chk->bind_param('i', $groupId);
    $chk->execute();
    $found = stmt_fetch_assoc($chk);
    $chk->close();
    if (!$found) {
        json_error('Group not found', 404);
    }

    $stmt = $conn->prepare("SELECT serial FROM `$table` WHERE lan_group_id = ? ORDER BY serial ASC");
    if (!$stmt) {
        json_error('DB prepare failed: ' . $conn->error, 500);
    }
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    if (method_exists($stmt, 'get_result')) {
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $s = trim((string) ($row['serial'] ?? ''));
            if ($s !== '') {
                $serials[] = $s;
            }
        }
    } else {
        $stmt->bind_result($serialCol);
        while ($stmt->fetch()) {
            $s = trim((string) $serialCol);
            if ($s !== '') {
                $serials[] = $s;
            }
        }
    }
    $stmt->close();
    json_out(['groupId' => $groupId, 'serials' => $serials]);
}

/**
 * Update group card metadata (description, score, category, warranty, photo flag).
 * Matches Android SerialGenerator.updateGroup.
 */
function sw_update_group(mysqli $conn, int $groupId, array $body): void
{
    if (sw_is_legacy_group_id($groupId)) {
        json_error('ویرایش موجودی قدیمی از این کارت مجاز نیست. فقط گروه‌های تولیدشده از پنل را می‌توان ویرایش کرد.', 400);
    }

    $table = serials_table($conn);
    sw_ensure_columns($conn, $table);

    $chk = $conn->prepare('SELECT * FROM serial_groups WHERE id = ? LIMIT 1');
    if (!$chk) {
        json_error('DB prepare failed: ' . $conn->error, 500);
    }
    $chk->bind_param('i', $groupId);
    $chk->execute();
    $group = stmt_fetch_assoc($chk);
    $chk->close();
    if (!$group) {
        json_error('Group not found', 404);
    }

    $score = array_key_exists('score', $body) ? (int) $body['score'] : (int) ($group['score'] ?? 1);
    if ($score < 1) {
        json_error('امتیاز باید حداقل ۱ باشد', 400);
    }

    if (array_key_exists('description', $body)) {
        $descRaw = $body['description'];
        if ($descRaw === null) {
            $description = null;
        } else {
            $trimmed = trim((string) $descRaw);
            $description = $trimmed === '' ? null : substr($trimmed, 0, 255);
        }
    } else {
        $description = isset($group['description']) && $group['description'] !== ''
            ? (string) $group['description']
            : null;
    }

    if (array_key_exists('category', $body) && $body['category'] !== null && $body['category'] !== '') {
        $category = trim((string) $body['category']);
        if ($category !== 'end_user_client' && $category !== 'seller_to_end_user') {
            json_error('دسته سریال نامعتبر است', 400);
        }
    } else {
        $category = (string) ($group['category'] ?? 'end_user_client');
        if ($category !== 'end_user_client' && $category !== 'seller_to_end_user') {
            $category = 'end_user_client';
        }
    }

    $warrantyTouched = array_key_exists('warrantyMonths', $body) || array_key_exists('warrantyKm', $body);
    if ($warrantyTouched) {
        $monthsIn = array_key_exists('warrantyMonths', $body) ? $body['warrantyMonths'] : null;
        $kmIn = array_key_exists('warrantyKm', $body) ? $body['warrantyKm'] : null;
        $warrantyMonths = ($monthsIn !== null && (int) $monthsIn > 0) ? (int) $monthsIn : null;
        $warrantyKm = ($kmIn !== null && (int) $kmIn > 0) ? (int) $kmIn : null;
    } else {
        $warrantyMonths = isset($group['warranty_months']) && $group['warranty_months'] !== null && (int) $group['warranty_months'] > 0
            ? (int) $group['warranty_months']
            : null;
        $warrantyKm = isset($group['warranty_km']) && $group['warranty_km'] !== null && (int) $group['warranty_km'] > 0
            ? (int) $group['warranty_km']
            : null;
    }
    if ($warrantyMonths === null && $warrantyKm === null) {
        json_error('حداقل یکی از مدت گارانتی (ماه) یا کیلومتر گارانتی باید وارد شود.', 400);
    }

    if (array_key_exists('imageVerificationRequired', $body)) {
        $imageVerification = !empty($body['imageVerificationRequired']) ? 1 : 0;
    } else {
        $imageVerification = !empty($group['image_verification_required']) ? 1 : 0;
    }

    $syncMs = (int) round(microtime(true) * 1000);
    $descSql = $description === null ? 'NULL' : ("'" . $conn->real_escape_string($description) . "'");
    $monthsSql = $warrantyMonths === null ? 'NULL' : (string) (int) $warrantyMonths;
    $kmSql = $warrantyKm === null ? 'NULL' : (string) (int) $warrantyKm;
    $ok = $conn->query(
        'UPDATE serial_groups SET
            description = ' . $descSql . ',
            score = ' . (int) $score . ',
            category = \'' . $conn->real_escape_string($category) . '\',
            warranty_months = ' . $monthsSql . ',
            warranty_km = ' . $kmSql . ',
            image_verification_required = ' . (int) $imageVerification . ',
            sync_updated_ms = ' . $syncMs . '
         WHERE id = ' . (int) $groupId
    );
    if ($ok === false) {
        json_error('Update failed: ' . $conn->error, 500);
    }

    $freshRes = $conn->query('SELECT * FROM serial_groups WHERE id = ' . (int) $groupId . ' LIMIT 1');
    $fresh = $freshRes ? $freshRes->fetch_assoc() : null;
    if (!$fresh) {
        json_error('Group missing after update', 500);
    }
    $counts = $conn->query(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN " . is_registered_sql() . " THEN 1 ELSE 0 END) AS used
         FROM `$table` WHERE lan_group_id = " . (int) $groupId
    );
    $c = $counts ? $counts->fetch_assoc() : null;
    $total = (int) ($c['total'] ?? 0);
    $used = (int) ($c['used'] ?? 0);

    if (function_exists('crm_activity_record') || is_file(__DIR__ . '/activity_store.php')) {
        require_once __DIR__ . '/activity_store.php';
        crm_activity_record($conn, 'settings_changed', [
            'key' => 'serial_group',
            'groupId' => $groupId,
            'message' => 'ویرایش گروه سریال #' . $groupId,
        ]);
    }

    json_out(sw_group_to_api($fresh, $total, $used));
}

function sw_delete_group(mysqli $conn, int $groupId): void
{
    if (sw_is_legacy_group_id($groupId)) {
        json_error('حذف موجودی قدیمی از این کارت مجاز نیست. فقط گروه‌های تولیدشده از پنل را می‌توان حذف کرد.', 400);
    }

    $table = serials_table($conn);
    sw_ensure_columns($conn, $table);

    $chk = $conn->prepare('SELECT id, sync_origin_lan_id FROM serial_groups WHERE id = ? LIMIT 1');
    if (!$chk) {
        json_error('DB prepare failed: ' . $conn->error, 500);
    }
    $chk->bind_param('i', $groupId);
    $chk->execute();
    $found = stmt_fetch_assoc($chk);
    $chk->close();
    if (!$found) {
        json_error('Group not found', 404);
    }
    $originLan = isset($found['sync_origin_lan_id']) && $found['sync_origin_lan_id'] !== null
        ? (int) $found['sync_origin_lan_id']
        : null;

    $conn->begin_transaction();
    $removed = 0;
    try {
        $cntStmt = $conn->prepare("SELECT COUNT(*) AS c FROM `$table` WHERE lan_group_id = ?");
        if ($cntStmt) {
            $cntStmt->bind_param('i', $groupId);
            $cntStmt->execute();
            $cntRow = stmt_fetch_assoc($cntStmt);
            $cntStmt->close();
            $removed = (int) ($cntRow['c'] ?? 0);
        }

        $delSerials = $conn->prepare("DELETE FROM `$table` WHERE lan_group_id = ?");
        if (!$delSerials) {
            throw new RuntimeException($conn->error);
        }
        $delSerials->bind_param('i', $groupId);
        $delSerials->execute();
        $delSerials->close();

        $delGroup = $conn->prepare('DELETE FROM serial_groups WHERE id = ?');
        if (!$delGroup) {
            throw new RuntimeException($conn->error);
        }
        $delGroup->bind_param('i', $groupId);
        $delGroup->execute();
        $delGroup->close();
        sw_record_group_delete_tombstone($conn, $groupId, $originLan);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        json_error('Failed to delete group: ' . $e->getMessage(), 500);
    }

    // Remove warranty-linked club auto rules for this group (remote + local cleanliness).
    if (table_exists($conn, 'club_auto_message_rules')) {
        $conn->query(
            'DELETE FROM club_auto_message_rules WHERE linked_serial_group_id = ' . (int) $groupId
        );
    }

    require_once __DIR__ . '/activity_store.php';
    crm_activity_record($conn, 'serials_removed', [
        'groupId' => $groupId,
        'removed' => $removed,
        'message' => 'حذف گروه سریال — ' . $removed . ' سریال',
    ]);

    http_response_code(204);
    exit;
}

function sw_handle_range(mysqli $conn, array $body): void
{
    $table = serials_table($conn);
    sw_ensure_columns($conn, $table);

    $prefix = sw_norm((string) ($body['prefix'] ?? ''));
    if ($prefix !== '' && !preg_match('/^[A-Z0-9]+$/', $prefix)) {
        json_error('Prefix may only contain letters and digits.', 400);
    }
    $start = (int) ($body['start'] ?? 0);
    $end = (int) ($body['end'] ?? 0);
    $padWidth = (int) ($body['padWidth'] ?? 0);
    if ($start > $end) {
        json_error('Start number must be less than or equal to end number.', 400);
    }
    if ($start < 0 || $end < 0) {
        json_error('Start and end numbers must be non-negative.', 400);
    }
    if ($padWidth < 0) {
        json_error('Pad width must be a non-negative integer.', 400);
    }
    $score = sw_require_score($body['score'] ?? 1);
    $category = sw_require_category($body['category'] ?? '');
    [$wMonths, $wKm] = sw_require_warranty($body['warrantyMonths'] ?? null, $body['warrantyKm'] ?? null);

    $count = $end - $start + 1;
    if ($count <= 0) {
        json_error('Invalid range.', 400);
    }
    if ($count > SW_MAX_BATCH) {
        json_error('Count exceeds maximum of ' . SW_MAX_BATCH . ' serials.', 400);
    }

    $iv = (bool) ($body['imageVerificationRequired'] ?? false);
    $city = isset($body['city']) && trim((string) $body['city']) !== '' ? trim((string) $body['city']) : null;
    $km = isset($body['km']) && is_numeric($body['km']) ? (int) $body['km'] : null;
    $description = isset($body['description']) && trim((string) $body['description']) !== ''
        ? trim((string) $body['description']) : null;

    $serials = [];
    $numberBySerial = [];
    for ($n = $start; $n <= $end; $n++) {
        $serial = sw_build_serial($prefix, $n, $padWidth);
        $serials[] = $serial;
        $numberBySerial[$serial] = $n;
    }

    $existing = sw_existing_serials($conn, $table, $serials);
    if ($existing !== []) {
        $dupNumbers = [];
        foreach ($existing as $serial => $_) {
            if (isset($numberBySerial[$serial])) {
                $dupNumbers[] = $numberBySerial[$serial];
            }
        }
        $ranges = sw_compress_ranges($dupNumbers);
        $duplicateRanges = array_map(static function ($r) use ($prefix, $padWidth) {
            return [
                'rangeLabel' => sw_build_serial($prefix, $r[0], $padWidth) . ' – ' . sw_build_serial($prefix, $r[1], $padWidth),
                'count' => $r[1] - $r[0] + 1,
            ];
        }, $ranges);
        json_out([
            'error' => 'تولید انجام نشد — ' . count($dupNumbers) . ' سریال تکراری یافت شد.',
            'duplicateCount' => count($dupNumbers),
            'duplicateRanges' => $duplicateRanges,
        ], 409);
    }

    $groupId = sw_create_group(
        $conn, $prefix, $start, $end, $padWidth, $description, $score, $category,
        'range', $wMonths, $wKm, $iv
    );

    $inserted = sw_insert_serials(
        $conn, $table, $serials, $groupId, $category, $score,
        $wMonths, $wKm, $iv, $city, $km, $description
    );

    if ($inserted <= 0) {
        sw_delete_group_row($conn, $groupId);
        $groupId = 0;
    }

    if ($inserted > 0) {
        require_once __DIR__ . '/activity_store.php';
        crm_activity_record($conn, 'serials_added', [
            'inserted' => $inserted,
            'skipped' => $count - $inserted,
            'total' => $count,
            'groupId' => $groupId,
            'message' => 'افزودن ' . $inserted . ' سریال (بازه)',
        ]);
        require_once __DIR__ . '/sync/sync_buffer_helpers.php';
        $gRow = $conn->query('SELECT * FROM serial_groups WHERE id=' . (int) $groupId)->fetch_assoc() ?: [];
        sync_buffer_enqueue_generation($conn, $groupId, $serials, $category, $gRow);
    }

    json_out([
        'inserted' => $inserted,
        'skipped' => $count - $inserted,
        'total' => $count,
        'groupId' => $inserted > 0 ? $groupId : null,
    ]);
}

function sw_random_complex_serial(string $prefix, int $letterCount, int $digitCount, ?string $pattern = null): string
{
    if ($pattern !== null && $pattern !== '') {
        $body = '';
        $len = strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            $slot = $pattern[$i];
            if ($slot === 'C') {
                $body .= SW_COMPLEX_LETTERS[random_int(0, strlen(SW_COMPLEX_LETTERS) - 1)];
            } elseif ($slot === 'D') {
                $body .= SW_COMPLEX_DIGITS[random_int(0, strlen(SW_COMPLEX_DIGITS) - 1)];
            }
        }
        return $prefix . $body;
    }

    $length = $letterCount + $digitCount;
    $positions = range(0, $length - 1);
    shuffle($positions);
    $letterPositions = array_fill_keys(array_slice($positions, 0, $letterCount), true);

    $body = '';
    for ($i = 0; $i < $length; $i++) {
        if (isset($letterPositions[$i])) {
            $body .= SW_COMPLEX_LETTERS[random_int(0, strlen(SW_COMPLEX_LETTERS) - 1)];
        } else {
            $body .= SW_COMPLEX_DIGITS[random_int(0, strlen(SW_COMPLEX_DIGITS) - 1)];
        }
    }
    return $prefix . $body;
}

function sw_estimate_max_distinct_fixed(int $letterCount, int $digitCount): float
{
    $letterAlpha = strlen(SW_COMPLEX_LETTERS);
    $digitAlpha = strlen(SW_COMPLEX_DIGITS);
    return ($letterAlpha ** $letterCount) * ($digitAlpha ** $digitCount);
}

function sw_estimate_max_distinct(int $letterCount, int $digitCount): float
{
    $length = $letterCount + $digitCount;
    if ($length <= 0) {
        return 0.0;
    }
    $letterAlpha = strlen(SW_COMPLEX_LETTERS);
    $digitAlpha = strlen(SW_COMPLEX_DIGITS);
    $choose = min($letterCount, $length - $letterCount);
    $combinations = 1.0;
    for ($i = 0; $i < $choose; $i++) {
        $combinations = $combinations * ($length - $i) / ($i + 1);
        if ($combinations > SW_MAX_BATCH) {
            return INF;
        }
    }
    return $combinations * ($letterAlpha ** $letterCount) * ($digitAlpha ** $digitCount);
}

function sw_handle_complex(mysqli $conn, array $body): void
{
    $table = serials_table($conn);
    sw_ensure_columns($conn, $table);

    $count = (int) ($body['count'] ?? 0);
    if ($count < 1) {
        json_error('Count must be at least 1.', 400);
    }
    if ($count > SW_MAX_BATCH) {
        json_error('Count exceeds maximum of ' . SW_MAX_BATCH . ' serials.', 400);
    }
    $letterCount = (int) ($body['letterCount'] ?? 0);
    $digitCount = (int) ($body['digitCount'] ?? 0);
    if ($letterCount < 0 || $digitCount < 0) {
        json_error('Letter and digit counts must be non-negative.', 400);
    }
    if ($letterCount === 0 && $digitCount === 0) {
        json_error('At least one letter or digit is required.', 400);
    }
    $patternRaw = isset($body['pattern']) ? trim((string) $body['pattern']) : '';
    $pattern = $patternRaw !== '' ? strtoupper($patternRaw) : null;
    if ($pattern !== null && !preg_match('/^[CD]+$/', $pattern)) {
        json_error('Pattern may only contain C (letter) and D (digit).', 400);
    }
    $prefix = sw_norm((string) ($body['prefix'] ?? ''));
    if ($prefix !== '' && !preg_match('/^[A-Z0-9]+$/', $prefix)) {
        json_error('Prefix may only contain letters and digits.', 400);
    }
    $bodyLength = $letterCount + $digitCount;
    if ($pattern !== null) {
        if (strlen($pattern) !== $bodyLength) {
            json_error('Pattern length must match body length (letters + digits).', 400);
        }
        $patternLetters = substr_count($pattern, 'C');
        $patternDigits = substr_count($pattern, 'D');
        if ($patternLetters !== $letterCount || $patternDigits !== $digitCount) {
            json_error(
                'Pattern must contain exactly ' . $letterCount . ' letters (C) and '
                . $digitCount . ' digits (D).',
                400
            );
        }
    }
    if ($bodyLength < SW_MIN_COMPLEX_LENGTH || $bodyLength > SW_MAX_SERIAL_LENGTH) {
        json_error('Random body length (letters + digits) must be between ' . SW_MIN_COMPLEX_LENGTH . ' and ' . SW_MAX_SERIAL_LENGTH . '.', 400);
    }
    if (strlen($prefix) + $bodyLength > SW_MAX_SERIAL_LENGTH) {
        json_error('Prefix plus random body exceeds ' . SW_MAX_SERIAL_LENGTH . ' characters.', 400);
    }
    $score = sw_require_score($body['score'] ?? 1);
    $category = sw_require_category($body['category'] ?? '');
    [$wMonths, $wKm] = sw_require_warranty($body['warrantyMonths'] ?? null, $body['warrantyKm'] ?? null);

    $maxDistinct = $pattern !== null
        ? sw_estimate_max_distinct_fixed($letterCount, $digitCount)
        : sw_estimate_max_distinct($letterCount, $digitCount);
    if ($count > $maxDistinct) {
        json_error('Requested count exceeds maximum unique serials for this composition (' . (int) $maxDistinct . ').', 400);
    }

    $iv = (bool) ($body['imageVerificationRequired'] ?? false);
    $city = isset($body['city']) && trim((string) $body['city']) !== '' ? trim((string) $body['city']) : null;
    $km = isset($body['km']) && is_numeric($body['km']) ? (int) $body['km'] : null;
    $description = isset($body['description']) && trim((string) $body['description']) !== ''
        ? trim((string) $body['description']) : null;

    $accepted = [];
    $skipped = 0;
    $attempts = 0;
    $maxAttempts = max($count + 100, $count * 40);
    $maxAttempts = min($maxAttempts, 25000000);

    while (count($accepted) < $count && $attempts < $maxAttempts) {
        $remaining = $count - count($accepted);
        $batchSize = max(min($remaining * 3, 500), 1);
        $candidates = [];
        $guard = 0;
        while (count($candidates) < $batchSize && $guard < $batchSize * 20) {
            $candidates[sw_random_complex_serial($prefix, $letterCount, $digitCount, $pattern)] = true;
            $guard++;
            $attempts++;
        }
        $candidateList = array_keys($candidates);
        $existing = sw_existing_serials($conn, $table, $candidateList);
        foreach ($candidateList as $candidate) {
            if (isset($existing[$candidate]) || isset($accepted[$candidate])) {
                $skipped++;
                continue;
            }
            $accepted[$candidate] = true;
            if (count($accepted) >= $count) {
                break;
            }
        }
    }

    if (count($accepted) < $count) {
        json_error(
            'Could not generate ' . $count . ' unique serials after skipping duplicates. Generated '
            . count($accepted) . '; try a longer length or different character mix.',
            400
        );
    }

    $groupPrefix = $prefix !== '' ? $prefix : SW_COMPLEX_PREFIX;
    $groupId = sw_create_group(
        $conn, $groupPrefix, 0, $count, $bodyLength, $description, $score, $category,
        'complex', $wMonths, $wKm, $iv
    );

    $serials = array_keys($accepted);
    $inserted = sw_insert_serials(
        $conn, $table, $serials, $groupId, $category, $score,
        $wMonths, $wKm, $iv, $city, $km, $description
    );

    if ($inserted <= 0) {
        sw_delete_group_row($conn, $groupId);
        $groupId = 0;
    } else {
        sw_update_group_end($conn, $groupId, $inserted);
        require_once __DIR__ . '/activity_store.php';
        crm_activity_record($conn, 'serials_added', [
            'inserted' => $inserted,
            'skipped' => $skipped,
            'total' => $count,
            'groupId' => $groupId,
            'message' => 'افزودن ' . $inserted . ' سریال (پیچیده)',
        ]);
        require_once __DIR__ . '/sync/sync_buffer_helpers.php';
        $gRow = $conn->query('SELECT * FROM serial_groups WHERE id=' . (int) $groupId)->fetch_assoc() ?: [];
        sync_buffer_enqueue_generation($conn, $groupId, $serials, $category, $gRow);
    }

    json_out([
        'inserted' => $inserted,
        'skipped' => $skipped,
        'total' => $count,
        'groupId' => $inserted > 0 ? $groupId : null,
    ]);
}

function sw_handle_import(mysqli $conn, array $body): void
{
    $table = serials_table($conn);
    sw_ensure_columns($conn, $table);

    $rawSerials = $body['serials'] ?? null;
    if (!is_array($rawSerials) || $rawSerials === []) {
        json_error('At least one serial is required.', 400);
    }
    if (count($rawSerials) > SW_MAX_BATCH) {
        json_error('Import exceeds maximum of ' . SW_MAX_BATCH . ' serials.', 400);
    }
    $score = sw_require_score($body['score'] ?? 1);
    $category = sw_require_category($body['category'] ?? '');
    [$wMonths, $wKm] = sw_require_warranty($body['warrantyMonths'] ?? null, $body['warrantyKm'] ?? null);

    $normalized = [];
    $invalid = [];
    foreach ($rawSerials as $raw) {
        $serial = sw_norm((string) $raw);
        if ($serial === '') {
            continue;
        }
        if (strlen($serial) > SW_MAX_SERIAL_LENGTH || !preg_match('/^[A-Z0-9]+$/', $serial)) {
            $invalid[] = trim((string) $raw) !== '' ? trim((string) $raw) : $serial;
            if (count($invalid) >= 5) {
                break;
            }
        } else {
            $normalized[] = $serial;
        }
    }
    if ($invalid !== []) {
        json_error(
            'Invalid serial format (letters/digits only, max ' . SW_MAX_SERIAL_LENGTH . '): ' . implode(', ', $invalid),
            400
        );
    }
    if ($normalized === []) {
        json_error('No valid serials found in the import list.', 400);
    }

    $totalNormalized = count($normalized);
    $uniqueOrdered = [];
    $skippedInFile = 0;
    foreach ($normalized as $serial) {
        if (isset($uniqueOrdered[$serial])) {
            $skippedInFile++;
        } else {
            $uniqueOrdered[$serial] = true;
        }
    }
    $uniqueList = array_keys($uniqueOrdered);

    $existing = sw_existing_serials($conn, $table, $uniqueList);
    $toInsert = array_values(array_filter($uniqueList, static function ($s) use ($existing) {
        return !isset($existing[$s]);
    }));
    $skippedExisting = count($uniqueList) - count($toInsert);
    $skipped = $skippedInFile + $skippedExisting;

    if ($toInsert === []) {
        json_error('No new serials to import — all ' . count($uniqueList) . ' were duplicates.', 400);
    }

    $iv = (bool) ($body['imageVerificationRequired'] ?? false);
    $city = isset($body['city']) && trim((string) $body['city']) !== '' ? trim((string) $body['city']) : null;
    $km = isset($body['km']) && is_numeric($body['km']) ? (int) $body['km'] : null;
    $description = isset($body['description']) && trim((string) $body['description']) !== ''
        ? trim((string) $body['description']) : null;

    $maxLen = 0;
    foreach ($toInsert as $s) {
        $maxLen = max($maxLen, strlen($s));
    }

    $groupId = sw_create_group(
        $conn, SW_IMPORT_PREFIX, 0, count($toInsert), $maxLen, $description, $score, $category,
        'import', $wMonths, $wKm, $iv
    );

    $inserted = sw_insert_serials(
        $conn, $table, $toInsert, $groupId, $category, $score,
        $wMonths, $wKm, $iv, $city, $km, $description
    );

    if ($inserted <= 0) {
        sw_delete_group_row($conn, $groupId);
        $groupId = 0;
    } else {
        sw_update_group_end($conn, $groupId, $inserted);
        require_once __DIR__ . '/activity_store.php';
        crm_activity_record($conn, 'serials_added', [
            'inserted' => $inserted,
            'skipped' => $skipped,
            'total' => $totalNormalized,
            'groupId' => $groupId,
            'message' => 'افزودن ' . $inserted . ' سریال (ورود)',
        ]);
    }

    json_out([
        'inserted' => $inserted,
        'skipped' => $skipped,
        'total' => $totalNormalized,
        'groupId' => $inserted > 0 ? $groupId : null,
    ]);
}
