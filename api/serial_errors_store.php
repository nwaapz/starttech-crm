<?php
/**
 * Serial registration error storage for remote CRM dashboard.
 * Shared by /api/serial-errors and SMS.php / sync push.
 */
declare(strict_types=1);

function sre_ensure_table(mysqli $conn): void
{
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
}

function sre_normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D/', '', trim($phone)) ?? '';
    if ($digits === '') {
        return '';
    }
    if (strpos($digits, '98') === 0 && strlen($digits) >= 12) {
        $digits = '0' . substr($digits, 2);
    }
    if (strlen($digits) === 10 && isset($digits[0]) && $digits[0] === '9') {
        $digits = '0' . $digits;
    }
    return $digits;
}

function sre_normalize_reason(string $reason): string
{
    $r = strtolower(trim($reason));
    if ($r === 'already_registered' || $r === 'already-used' || $r === 'used') {
        return 'already_used';
    }
    if ($r === 'serial_not_found' || $r === 'invalid' || $r === 'not-found') {
        return 'not_found';
    }
    if ($r === 'already_used' || $r === 'not_found') {
        return $r;
    }
    return $r !== '' ? $r : 'not_found';
}

/**
 * @return bool true if a row was inserted
 */
function sre_insert(mysqli $conn, string $phone, string $serial, string $reason, ?int $atMs = null): bool
{
    sre_ensure_table($conn);
    $phone = sre_normalize_phone($phone);
    $serial = strtoupper(trim($serial));
    $reason = sre_normalize_reason($reason);
    if ($phone === '' || $serial === '') {
        return false;
    }
    $at = $atMs !== null && $atMs > 0 ? $atMs : (int) round(microtime(true) * 1000);
    $stmt = $conn->prepare(
        'INSERT INTO serial_registration_errors (phone, serial, reason, at_ms) VALUES (?, ?, ?, ?)'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('sssi', $phone, $serial, $reason, $at);
    $ok = $stmt->execute();
    $stmt->close();
    return (bool) $ok;
}

/**
 * @return array{items: list<array{phone:string,latestAt:int,latestSerial:string,latestReason:string,attemptCount:int}>, total:int}
 */
function sre_list_clients(mysqli $conn, int $page, int $limit): array
{
    sre_ensure_table($conn);
    $page = max(1, $page);
    $limit = max(1, min(100, $limit));
    $offset = ($page - 1) * $limit;

    $total = 0;
    $r = $conn->query('SELECT COUNT(DISTINCT phone) AS c FROM serial_registration_errors');
    if ($r && ($row = $r->fetch_assoc())) {
        $total = (int) ($row['c'] ?? 0);
    }

    $sql = "SELECT
                phone,
                MAX(at_ms) AS latestAt,
                SUBSTRING_INDEX(
                    GROUP_CONCAT(serial ORDER BY at_ms DESC, id DESC SEPARATOR '\n'),
                    '\n',
                    1
                ) AS latestSerial,
                SUBSTRING_INDEX(
                    GROUP_CONCAT(reason ORDER BY at_ms DESC, id DESC SEPARATOR '\n'),
                    '\n',
                    1
                ) AS latestReason,
                COUNT(*) AS attemptCount
            FROM serial_registration_errors
            GROUP BY phone
            ORDER BY latestAt DESC
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['items' => [], 'total' => $total];
    }
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $res = method_exists($stmt, 'get_result') ? $stmt->get_result() : null;
    $items = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $items[] = [
                'phone' => (string) $row['phone'],
                'latestAt' => (int) $row['latestAt'],
                'latestSerial' => (string) $row['latestSerial'],
                'latestReason' => (string) $row['latestReason'],
                'attemptCount' => (int) $row['attemptCount'],
            ];
        }
    }
    $stmt->close();
    return ['items' => $items, 'total' => $total];
}

/**
 * @return list<array{phone:string,serial:string,reason:string,at:int}>
 */
function sre_list_by_phone(mysqli $conn, string $phone): array
{
    sre_ensure_table($conn);
    $phone = sre_normalize_phone($phone);
    if ($phone === '') {
        return [];
    }
    $stmt = $conn->prepare(
        'SELECT phone, serial, reason, at_ms FROM serial_registration_errors
         WHERE phone = ? ORDER BY at_ms DESC, id DESC LIMIT 200'
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('s', $phone);
    $stmt->execute();
    $res = method_exists($stmt, 'get_result') ? $stmt->get_result() : null;
    $items = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $items[] = [
                'phone' => (string) $row['phone'],
                'serial' => (string) $row['serial'],
                'reason' => (string) $row['reason'],
                'at' => (int) $row['at_ms'],
            ];
        }
    }
    $stmt->close();
    return $items;
}
