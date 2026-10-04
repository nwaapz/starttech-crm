<?php
/**
 * POST /api/sync/serials/dates — dedicated date lookup for null-date repair.
 * Not part of dual-buffer get/send/backfill.
 *
 * Body: { "serials": ["ABC", ...] }
 * Response: { "items": [ { "serial": "...", "dateJalali": "YYYY/MM/DD", "date": "..." } ] }
 * dateJalali prefers date_jalali, else converts Gregorian `time`.
 */
declare(strict_types=1);

try {
    require_once dirname(__DIR__, 3) . '/config/database.php';
    require_once dirname(__DIR__, 2) . '/bootstrap.php';
    require_once dirname(__DIR__) . '/sync_auth.php';
    require_once dirname(__DIR__, 2) . '/serials_write.php';

    sync_json_headers();
    require_sync_token();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($body)) {
        $body = [];
    }
    $rawSerials = $body['serials'] ?? [];
    if (!is_array($rawSerials)) {
        $rawSerials = [];
    }

    $serials = [];
    foreach ($rawSerials as $s) {
        $n = strtoupper(trim((string) $s));
        if ($n !== '') {
            $serials[$n] = true;
        }
    }
    $serials = array_keys($serials);
    if (count($serials) > 200) {
        $serials = array_slice($serials, 0, 200);
    }

    $conn = payamesh_mysqli();
    ensure_remote_schema($conn);

    $items = [];
    if ($serials === []) {
        echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tables = [];
    foreach (['old_serials', 'new_serials'] as $table) {
        $check = $conn->query("SHOW TABLES LIKE '$table'");
        if ($check && $check->num_rows > 0) {
            sw_ensure_columns($conn, $table);
            $tables[] = $table;
        }
    }

    $found = [];
    foreach ($tables as $table) {
        $remaining = [];
        foreach ($serials as $s) {
            if (!isset($found[$s])) {
                $remaining[] = $s;
            }
        }
        if ($remaining === []) {
            break;
        }
        $placeholders = implode(',', array_fill(0, count($remaining), '?'));
        $types = str_repeat('s', count($remaining));
        $hasJalali = column_exists($conn, $table, 'date_jalali');
        $cols = 'serial, time' . ($hasJalali ? ', date_jalali' : '');
        $sql = "SELECT $cols FROM `$table` WHERE UPPER(serial) IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param($types, ...$remaining);
        $stmt->execute();
        $res = method_exists($stmt, 'get_result') ? $stmt->get_result() : null;
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $serial = strtoupper(trim((string) ($row['serial'] ?? '')));
                if ($serial === '' || isset($found[$serial])) {
                    continue;
                }
                $date = serial_date_jalali($row);
                if ($date === null || trim((string) $date) === '') {
                    continue;
                }
                $found[$serial] = true;
                $items[] = [
                    'serial' => (string) $row['serial'],
                    'dateJalali' => $date,
                    'date' => $date,
                ];
            }
        }
        $stmt->close();
    }

    echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
