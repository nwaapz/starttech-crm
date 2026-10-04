<?php
/**
 * Shared helpers for additive schema changes (Mode A + unify scripts).
 */
require_once __DIR__ . '/../config/database.php';

/** LAN-compatible category values (SerialGroupCategory). */
const PAYAMESH_CATEGORY_END_USER = 'end_user_client';
const PAYAMESH_CATEGORY_SELLER = 'seller_to_end_user';

function payamesh_column_exists(mysqli $conn, string $table, string $column): bool
{
    $db = $conn->query('SELECT DATABASE()')->fetch_row()[0];
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->bind_param('sss', $db, $table, $column);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return (int) $count > 0;
}

function payamesh_add_column_if_missing(mysqli $conn, string $table, string $column, string $ddl): bool
{
    if (payamesh_column_exists($conn, $table, $column)) {
        return false;
    }
    if (!$conn->query("ALTER TABLE `$table` ADD COLUMN $ddl")) {
        throw new RuntimeException("ADD COLUMN $table.$column: " . $conn->error);
    }
    return true;
}

function payamesh_ensure_serial_unique(mysqli $conn, string $table): void
{
    $db = $conn->query('SELECT DATABASE()')->fetch_row()[0];
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND NON_UNIQUE = 0 AND COLUMN_NAME = 'serial'"
    );
    $stmt->bind_param('ss', $db, $table);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    if ((int) $count > 0) {
        return;
    }
    // Only add unique if no duplicate serials
    $dup = $conn->query("SELECT serial FROM `$table` GROUP BY serial HAVING COUNT(*) > 1 LIMIT 1");
    if ($dup && $dup->num_rows > 0) {
        echo "WARN: `$table` has duplicate serials — skip UNIQUE; dedupe first\n";
        return;
    }
    $idx = 'uq_' . $table . '_serial';
    if (!$conn->query("ALTER TABLE `$table` ADD UNIQUE KEY `$idx` (serial)")) {
        echo "WARN: could not add unique on $table.serial: {$conn->error}\n";
    }
}

/**
 * Ensure sync + category columns on old_serials (and optionally new_serials).
 * category = end_user_client | seller_to_end_user (LAN parity). No lottery flag.
 */
function payamesh_ensure_unified_serial_columns(mysqli $conn, bool $alsoNewSerials = true): void
{
    $tables = ['old_serials'];
    if ($alsoNewSerials) {
        $tables[] = 'new_serials';
    }
    foreach ($tables as $table) {
        $check = $conn->query("SHOW TABLES LIKE '$table'");
        if (!$check || $check->num_rows === 0) {
            continue;
        }
        payamesh_add_column_if_missing($conn, $table, 'date_jalali', 'date_jalali VARCHAR(32) NULL');
        payamesh_add_column_if_missing($conn, $table, 'sync_updated_ms', 'sync_updated_ms BIGINT NULL');
        payamesh_add_column_if_missing($conn, $table, 'reg_source', 'reg_source VARCHAR(16) NULL');
        payamesh_add_column_if_missing($conn, $table, 'lan_group_id', 'lan_group_id BIGINT NULL');
        payamesh_add_column_if_missing(
            $conn,
            $table,
            'category',
            "category VARCHAR(32) NOT NULL DEFAULT '" . PAYAMESH_CATEGORY_END_USER . "'"
        );
        payamesh_add_column_if_missing($conn, $table, 'score', 'score INT NOT NULL DEFAULT 0');
        payamesh_ensure_serial_unique($conn, $table);
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS crm_sync_meta (
            meta_key VARCHAR(64) NOT NULL PRIMARY KEY,
            meta_value TEXT NULL,
            updated_at BIGINT NULL
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
        "CREATE TABLE IF NOT EXISTS serial_registration_errors (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            phone VARCHAR(32) NOT NULL,
            serial VARCHAR(64) NOT NULL,
            reason VARCHAR(32) NOT NULL,
            at_ms BIGINT NOT NULL,
            INDEX idx_sre_phone_at (phone, at_ms),
            INDEX idx_sre_at (at_ms)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
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

function payamesh_normalize_category(?string $value): string
{
    $v = trim((string) $value);
    if ($v === PAYAMESH_CATEGORY_SELLER || $v === 'seller') {
        return PAYAMESH_CATEGORY_SELLER;
    }
    return PAYAMESH_CATEGORY_END_USER;
}

/** Same rule as serial-utils isSToMRange — S440000..S450000 treated as seller series. */
function payamesh_is_s440_s450(string $serial): bool
{
    $serial = strtoupper(trim($serial));
    if (!preg_match('/^S(\d+)$/', $serial, $m)) {
        return false;
    }
    $num = (int) $m[1];
    return $num >= 440000 && $num <= 450000;
}
