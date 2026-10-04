<?php
/**
 * Shared serial row mapping for cloud sync pull endpoints.
 */
declare(strict_types=1);

/** @return list<string> Tables that exist and have sync_updated_ms. */
function sync_serial_tables(mysqli $conn): array {
    $out = [];
    foreach (['old_serials', 'new_serials'] as $table) {
        $check = $conn->query("SHOW TABLES LIKE '$table'");
        if (!$check || $check->num_rows === 0) {
            continue;
        }
        sw_ensure_columns($conn, $table);
        if (!column_exists($conn, $table, 'sync_updated_ms')) {
            continue;
        }
        $out[] = $table;
    }
    return $out;
}

function sync_row_to_pull_serial(array $row, int $now): array {
    $ms = isset($row['sync_updated_ms']) ? (int) $row['sync_updated_ms'] : $now;
    $category = (string) ($row['category'] ?? 'end_user_client');
    if ($category !== 'seller_to_end_user' && $category !== 'end_user_client') {
        $category = 'end_user_client';
    }
    $lanGroupId = isset($row['lan_group_id']) && $row['lan_group_id'] !== null
        ? (int) $row['lan_group_id'] : null;
    if ($lanGroupId === null || $lanGroupId <= 0) {
        $lanGroupId = sw_legacy_group_id_for_category($category);
    }
    $rawPhone = isset($row['phone']) ? trim((string) $row['phone']) : '';
    $phoneOut = ($rawPhone === '' || $rawPhone === '0') ? null : $rawPhone;
    return [
        'serial' => $row['serial'],
        'phone' => $phoneOut,
        'km' => isset($row['km']) && $row['km'] !== null ? (int) $row['km'] : null,
        'city' => $row['city'] ?? null,
        'dateJalali' => serial_date_jalali($row),
        'regSource' => $row['reg_source'] ?? null,
        'lanGroupId' => $lanGroupId,
        'category' => $category,
        'score' => isset($row['score']) ? (int) $row['score'] : 0,
        'syncUpdatedMs' => $ms,
    ];
}

function sync_table_extra_columns(mysqli $conn, string $table): string {
    $hasJalali = column_exists($conn, $table, 'date_jalali');
    $hasCity = column_exists($conn, $table, 'city');
    $hasReg = column_exists($conn, $table, 'reg_source');
    $hasGroup = column_exists($conn, $table, 'lan_group_id');
    $hasCategory = column_exists($conn, $table, 'category');
    $hasScore = column_exists($conn, $table, 'score');
    return ($hasCity ? ', city' : '')
        . ($hasJalali ? ', date_jalali' : '')
        . ($hasReg ? ', reg_source' : '')
        . ($hasGroup ? ', lan_group_id' : '')
        . ($hasCategory ? ', category' : '')
        . ($hasScore ? ', score' : '');
}

function sync_count_serials_all_tables(mysqli $conn): int {
    $total = 0;
    foreach (sync_serial_tables($conn) as $table) {
        $res = $conn->query("SELECT COUNT(*) AS c FROM `$table`");
        if ($res && ($row = $res->fetch_assoc())) {
            $total += (int) ($row['c'] ?? 0);
        }
    }
    return $total;
}

function sync_cursor_watermark(mysqli $conn): array {
    $maxMs = 0;
    $maxSrc = 0;
    $maxId = 0;
    $tables = sync_serial_tables($conn);
    foreach ($tables as $src => $table) {
        $syncExpr = 'COALESCE(NULLIF(sync_updated_ms, 0), id)';
        $res = $conn->query(
            "SELECT MAX($syncExpr) AS ms, MAX(id) AS mid FROM `$table`"
        );
        if ($res && ($row = $res->fetch_assoc())) {
            $ms = (int) ($row['ms'] ?? 0);
            $mid = (int) ($row['mid'] ?? 0);
            if ($ms > $maxMs || ($ms === $maxMs && $src >= $maxSrc && $mid > $maxId)) {
                $maxMs = $ms;
                $maxSrc = $src;
                $maxId = $mid;
            }
        }
    }
    return ['ms' => $maxMs, 'src' => $maxSrc, 'id' => $maxId];
}
