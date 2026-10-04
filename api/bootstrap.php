<?php
/**
 * Shared bootstrap for remote CRM REST API.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

// Always return JSON on fatal errors so the CRM banner / Network tab show a usable message.
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int) $err['type'], $fatalTypes, true)) {
        return;
    }
    if (headers_sent()) {
        return;
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode([
        'error' => 'PHP fatal: ' . (string) ($err['message'] ?? 'unknown'),
        'file' => basename((string) ($err['file'] ?? '')),
        'line' => (int) ($err['line'] ?? 0),
    ], JSON_UNESCAPED_UNICODE);
});

set_exception_handler(static function (Throwable $e): void {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
    }
    echo json_encode([
        'error' => 'Uncaught: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

$dbConfig = dirname(__DIR__) . '/config/database.php';
if (!is_file($dbConfig)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['error' => 'Missing config/database.php — upload crm-php/config to /crm/config/']);
    exit;
}
require_once $dbConfig;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Sync-Token');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $code = 400): void {
    json_out(['error' => $message], $code);
}

function starts_with(string $haystack, string $needle): bool {
    if ($needle === '') {
        return true;
    }
    return substr($haystack, 0, strlen($needle)) === $needle;
}

function body_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function bearer_token(): ?string {
    $hdr = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if ($hdr === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $hdr = $v;
                break;
            }
        }
    }
    if (preg_match('/Bearer\s+(\S+)/i', $hdr, $m)) {
        return $m[1];
    }
    return null;
}

function table_exists(mysqli $conn, string $table): bool {
    $t = $conn->real_escape_string($table);
    $r = $conn->query("SHOW TABLES LIKE '$t'");
    return $r && $r->num_rows > 0;
}

function column_exists(mysqli $conn, string $table, string $col): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($col);
    $r = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $r && $r->num_rows > 0;
}

/** Ensure session + additive columns needed by the remote panel. */
function ensure_remote_schema(mysqli $conn): void {
    static $ready = false;
    if ($ready) {
        return;
    }
    $ok = $conn->query(
        "CREATE TABLE IF NOT EXISTS crm_sessions (
            token VARCHAR(64) NOT NULL PRIMARY KEY,
            user_id INT NOT NULL,
            username VARCHAR(100) NOT NULL,
            role INT NOT NULL DEFAULT 1,
            expires_at BIGINT NOT NULL,
            INDEX idx_crm_sessions_exp (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if ($ok === false) {
        json_error('DB schema error (crm_sessions): ' . $conn->error, 500);
    }
    if (!table_exists($conn, 'users')) {
        $ok = $conn->query(
            "CREATE TABLE users (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                pass VARCHAR(255) NOT NULL,
                role INT NOT NULL DEFAULT 5,
                UNIQUE KEY uq_users_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        if ($ok === false) {
            json_error('DB schema error (users): ' . $conn->error, 500);
        }
    }
    if (table_exists($conn, 'old_serials')) {
        $adds = [
            'date_jalali' => "VARCHAR(32) DEFAULT NULL",
            'category' => "VARCHAR(32) NOT NULL DEFAULT 'end_user_client'",
            'score' => "INT NOT NULL DEFAULT 0",
            'reg_source' => "VARCHAR(16) DEFAULT NULL",
            'sync_updated_ms' => "BIGINT DEFAULT NULL",
            'completion_reminder_sent_at' => "BIGINT DEFAULT NULL",
        ];
        foreach ($adds as $col => $def) {
            if (!column_exists($conn, 'old_serials', $col)) {
                $conn->query("ALTER TABLE old_serials ADD COLUMN `$col` $def");
            }
        }
    }
    if (table_exists($conn, 'new_serials') && !column_exists($conn, 'new_serials', 'completion_reminder_sent_at')) {
        $conn->query('ALTER TABLE new_serials ADD COLUMN `completion_reminder_sent_at` BIGINT DEFAULT NULL');
    }

    $optional = [
        'serial_errors_store.php' => 'sre_ensure_table',
        'activity_store.php' => 'crm_activity_ensure_table',
        'comms_store.php' => 'comms_ensure_schema',
        'bots_store.php' => 'bots_ensure_schema',
        'photo_verification.php' => 'photo_ensure_schema',
        'lottery_store.php' => 'lottery_ensure_schema',
    ];
    foreach ($optional as $file => $fn) {
        $path = __DIR__ . '/' . $file;
        if (!is_file($path)) {
            continue;
        }
        require_once $path;
        if (function_exists($fn)) {
            try {
                $fn($conn);
            } catch (Throwable $e) {
                json_error('Schema init failed (' . $file . '): ' . $e->getMessage(), 500);
            }
        }
    }

    $bufHelper = __DIR__ . '/sync/sync_buffer_helpers.php';
    if (is_file($bufHelper)) {
        require_once $bufHelper;
        if (function_exists('sync_ensure_buffer_table')) {
            sync_ensure_buffer_table($conn);
        }
    }

    $n = 0;
    $r = $conn->query('SELECT COUNT(*) AS c FROM users');
    if ($r) {
        $row = $r->fetch_assoc();
        $n = (int) ($row['c'] ?? 0);
    }
    if ($n === 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO users (name, pass, role) VALUES (?, ?, 5)');
        if ($stmt) {
            $name = 'admin';
            $stmt->bind_param('ss', $name, $hash);
            $stmt->execute();
            $stmt->close();
        }
    }

    if (table_exists($conn, 'old_serials')) {
        serial_ensure_perf_indexes($conn, 'old_serials');
    }
    if (table_exists($conn, 'new_serials')) {
        serial_ensure_perf_indexes($conn, 'new_serials');
    }

    $ready = true;
}

function verify_password(string $plain, string $stored): bool {
    if ($stored === '') {
        return false;
    }
    if (starts_with($stored, '$2y$') || starts_with($stored, '$2a$') || starts_with($stored, '$argon')) {
        return password_verify($plain, $stored);
    }
    return hash_equals($stored, $plain);
}

/** Fetch one assoc row without requiring mysqlnd get_result(). */
function stmt_fetch_assoc(mysqli_stmt $stmt): ?array {
    if (method_exists($stmt, 'get_result')) {
        $res = @$stmt->get_result();
        if ($res instanceof mysqli_result) {
            $row = $res->fetch_assoc();
            return $row ?: null;
        }
    }
    $meta = $stmt->result_metadata();
    if (!$meta) {
        return null;
    }
    $fields = [];
    $row = [];
    $bind = [];
    while ($field = $meta->fetch_field()) {
        $fields[] = $field->name;
        $row[$field->name] = null;
        $bind[] = &$row[$field->name];
    }
    $meta->free();
    call_user_func_array([$stmt, 'bind_result'], $bind);
    if (!$stmt->fetch()) {
        return null;
    }
    $out = [];
    foreach ($fields as $name) {
        $out[$name] = $row[$name];
    }
    return $out;
}

/** Fetch all assoc rows without requiring mysqlnd get_result(). */
function stmt_fetch_all_assoc(mysqli_stmt $stmt): array {
    if (method_exists($stmt, 'get_result')) {
        $res = @$stmt->get_result();
        if ($res instanceof mysqli_result) {
            $rows = [];
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            return $rows;
        }
    }
    $meta = $stmt->result_metadata();
    if (!$meta) {
        return [];
    }
    $fields = [];
    $row = [];
    $bind = [];
    while ($field = $meta->fetch_field()) {
        $fields[] = $field->name;
        $row[$field->name] = null;
        $bind[] = &$row[$field->name];
    }
    $meta->free();
    call_user_func_array([$stmt, 'bind_result'], $bind);
    $rows = [];
    while ($stmt->fetch()) {
        $copy = [];
        foreach ($fields as $name) {
            $copy[$name] = $row[$name];
        }
        $rows[] = $copy;
    }
    return $rows;
}

/** @return array{id:int,username:string,role:int} */
function require_auth(mysqli $conn): array {
    $token = bearer_token();
    if ($token === null || $token === '') {
        json_error('Missing token', 401);
    }
    ensure_remote_schema($conn);
    $now = (int) round(microtime(true) * 1000);
    $stmt = $conn->prepare('SELECT user_id, username, role, expires_at FROM crm_sessions WHERE token = ? LIMIT 1');
    if (!$stmt) {
        json_error('DB prepare failed: ' . $conn->error, 500);
    }
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    $stmt->close();
    if (!$row || (int) $row['expires_at'] < $now) {
        json_error('Invalid credentials', 401);
    }
    return [
        'id' => (int) $row['user_id'],
        'username' => (string) $row['username'],
        'role' => (int) $row['role'],
    ];
}

function serials_table(mysqli $conn): string {
    if (table_exists($conn, 'old_serials')) {
        return 'old_serials';
    }
    if (table_exists($conn, 'new_serials')) {
        return 'new_serials';
    }
    json_error('No serials table found — run install or alter first', 500);
    return 'old_serials';
}

function is_registered_sql(string $alias = ''): string {
    $p = $alias !== '' ? "$alias." : '';
    // Index-friendly: phones are stored normalized (no TRIM() in SQL).
    return "({$p}phone IS NOT NULL AND {$p}phone <> '' AND {$p}phone <> '0')";
}

/** Add indexes used by list/report/sync queries (idempotent). */
function serial_ensure_perf_indexes(mysqli $conn, string $table): void
{
    static $done = [];
    if (isset($done[$table])) {
        return;
    }
    $done[$table] = true;

    $indexes = [
        'idx_serials_phone' => 'phone',
        'idx_serials_date_jalali' => 'date_jalali(10)',
        'idx_serials_time' => 'time',
        'idx_serials_sync_updated_ms' => 'sync_updated_ms',
        'idx_serials_reg_source' => 'reg_source',
        'idx_serials_lan_group' => 'lan_group_id',
    ];
    foreach ($indexes as $name => $cols) {
        if ($cols === 'date_jalali(10)' && !column_exists($conn, $table, 'date_jalali')) {
            continue;
        }
        if ($cols === 'time' && !column_exists($conn, $table, 'time')) {
            continue;
        }
        if ($cols === 'sync_updated_ms' && !column_exists($conn, $table, 'sync_updated_ms')) {
            continue;
        }
        if ($cols === 'reg_source' && !column_exists($conn, $table, 'reg_source')) {
            continue;
        }
        if ($cols === 'lan_group_id' && !column_exists($conn, $table, 'lan_group_id')) {
            continue;
        }
        if ($cols === 'phone' && !column_exists($conn, $table, 'phone')) {
            continue;
        }
        $escaped = $conn->real_escape_string($name);
        $idx = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$escaped'");
        if ($idx && $idx->num_rows === 0) {
            @$conn->query("ALTER TABLE `$table` ADD INDEX `$name` ($cols)");
        }
    }
}

/** Fast ORDER BY for paginated serial lists (uses sync_updated_ms or time index). */
function serial_list_order_sql(mysqli $conn, string $table): string
{
    if (column_exists($conn, $table, 'sync_updated_ms')) {
        return 'ORDER BY sync_updated_ms DESC, id DESC';
    }
    if (column_exists($conn, $table, 'time')) {
        return 'ORDER BY time DESC, id DESC';
    }
    return 'ORDER BY id DESC';
}

/**
 * Cached total/registered counts for dashboard sync (30s TTL).
 *
 * @return array{total:int,registered:int,unused:int}
 */
function serial_cached_stats(mysqli $conn, string $table, bool $refresh = false): array
{
    static $cache = [];
    $now = time();
    if (
        !$refresh
        && isset($cache[$table])
        && ($now - (int) ($cache[$table]['at'] ?? 0)) < 30
    ) {
        return $cache[$table]['stats'];
    }

    serial_ensure_perf_indexes($conn, $table);
    $registeredSql = is_registered_sql();
    $res = $conn->query(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN $registeredSql THEN 1 ELSE 0 END) AS registered
         FROM `$table`"
    );
    $total = 0;
    $registered = 0;
    if ($res && ($row = $res->fetch_assoc())) {
        $total = (int) ($row['total'] ?? $row['c'] ?? 0);
        $registered = (int) ($row['registered'] ?? 0);
    }
    $stats = [
        'total' => $total,
        'registered' => $registered,
        'unused' => max(0, $total - $registered),
    ];
    $cache[$table] = ['at' => $now, 'stats' => $stats];
    return $stats;
}

/**
 * Use cached registered count when the list query is unfiltered except registered=true.
 */
function serial_list_uses_cached_total(
    ?string $registeredParam,
    string $search,
    string $from,
    string $to,
    array $sourceParams,
    array $categoryParams,
    array $groupIds,
    string $provinceId
): bool {
    return $registeredParam === 'true'
        && $search === ''
        && $from === ''
        && $to === ''
        && $sourceParams === []
        && $categoryParams === []
        && $groupIds === []
        && trim($provinceId) === '';
}

/** Millisecond event time for a serial row (sync_updated_ms preferred, else time). */
function serial_row_event_ms(array $row): int {
    if (isset($row['sync_updated_ms']) && $row['sync_updated_ms'] !== null && $row['sync_updated_ms'] !== '') {
        $ms = (int) $row['sync_updated_ms'];
        if ($ms > 0) {
            return $ms;
        }
    }
    $t = $row['time'] ?? null;
    if ($t !== null && $t !== '') {
        $ts = strtotime((string) $t);
        if ($ts !== false) {
            return (int) ($ts * 1000);
        }
    }
    return 0;
}

/**
 * Recent registration events for the remote dashboard (LAN /api/sync parity).
 * @return list<array<string,mixed>>
 */
function sync_changes_from_serials(mysqli $conn, int $since): array {
    $table = serials_table($conn);
    $hasSync = column_exists($conn, $table, 'sync_updated_ms');
    $hasJalali = column_exists($conn, $table, 'date_jalali');
    $hasCity = column_exists($conn, $table, 'city');

    $select = 'id, serial, phone, km, time';
    if ($hasCity) {
        $select .= ', city';
    }
    if ($hasJalali) {
        $select .= ', date_jalali';
    }
    if ($hasSync) {
        $select .= ', sync_updated_ms';
    }

    $order = $hasSync
        ? 'ORDER BY sync_updated_ms DESC, id DESC'
        : ($hasJalali ? 'ORDER BY `time` DESC, id DESC' : 'ORDER BY id DESC');

    $sql = "SELECT $select FROM `$table` WHERE " . is_registered_sql() . " $order LIMIT 120";
    $res = $conn->query($sql);
    if (!$res) {
        return [];
    }

    $changes = [];
    while ($row = $res->fetch_assoc()) {
        $at = serial_row_event_ms($row);
        if ($since > 0 && $at > 0 && $at <= $since) {
            continue;
        }
        $date = '';
        if ($hasJalali && !empty($row['date_jalali'])) {
            $date = (string) $row['date_jalali'];
        } elseif (!empty($row['time'])) {
            $date = (string) $row['time'];
        }
        $km = null;
        if (isset($row['km']) && $row['km'] !== null && $row['km'] !== '') {
            $km = (int) $row['km'];
        }
        $changes[] = [
            'type' => 'registered',
            'serial' => (string) ($row['serial'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'date' => $date !== '' ? $date : null,
            'city' => $hasCity && isset($row['city']) && trim((string) $row['city']) !== ''
                ? (string) $row['city']
                : null,
            'km' => $km,
            'at' => $at > 0 ? $at : (int) round(microtime(true) * 1000),
        ];
        if (count($changes) >= 50) {
            break;
        }
    }
    return $changes;
}

/**
 * @return list<string> missing field keys among 'city', 'km'
 */
function serial_missing_fields(array $row, ?string $knownCity = null): array {
    $city = isset($row['city']) ? trim((string) $row['city']) : '';
    if ($city === '' && $knownCity !== null && trim($knownCity) !== '') {
        $city = trim($knownCity);
    }
    $hasKm = isset($row['km']) && $row['km'] !== null && $row['km'] !== '';
    $missing = [];
    if ($city === '') {
        $missing[] = 'city';
    }
    if (!serial_is_seller($row) && !$hasKm) {
        $missing[] = 'km';
    }
    return $missing;
}

function serial_is_seller(array $row): bool {
    return trim((string) ($row['category'] ?? '')) === 'seller_to_end_user';
}

function serial_known_city_for_phone(mysqli $conn, string $phone, ?string $excludeSerial = null): ?string {
    $phone = trim($phone);
    if ($phone === '') {
        return null;
    }
    $table = serials_table($conn);
    if (!column_exists($conn, $table, 'city')) {
        return null;
    }
    if ($excludeSerial !== null && $excludeSerial !== '') {
        $stmt = $conn->prepare(
            "SELECT city FROM `$table`
             WHERE phone = ? AND city IS NOT NULL AND TRIM(city) <> ''
               AND UPPER(serial) <> UPPER(?)
             ORDER BY id DESC LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ss', $phone, $excludeSerial);
    } else {
        $stmt = $conn->prepare(
            "SELECT city FROM `$table`
             WHERE phone = ? AND city IS NOT NULL AND TRIM(city) <> ''
             ORDER BY id DESC LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $phone);
    }
    $stmt->execute();
    $res = method_exists($stmt, 'get_result') ? $stmt->get_result() : null;
    $city = null;
    if ($res && ($r = $res->fetch_assoc())) {
        $city = trim((string) ($r['city'] ?? ''));
        if ($city === '') {
            $city = null;
        }
    }
    $stmt->close();
    return $city;
}

/**
 * Batch known cities for phones on a report page (avoids N+1).
 *
 * @param list<string> $phones
 * @param array<string,string> $excludeSerialByPhone phone => serial to skip when inheriting city
 * @return array<string,string> phone => city
 */
function serial_known_cities_for_phones(mysqli $conn, array $phones, array $excludeSerialByPhone = []): array {
    $phones = array_values(array_unique(array_filter(array_map('trim', $phones))));
    if ($phones === []) {
        return [];
    }
    $table = serials_table($conn);
    if (!column_exists($conn, $table, 'city')) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($phones), '?'));
    $types = str_repeat('s', count($phones));
    $sql = "SELECT phone, city, serial FROM `$table`
            WHERE phone IN ($placeholders)
              AND city IS NOT NULL AND city <> ''
            ORDER BY id DESC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param($types, ...$phones);
    $stmt->execute();
    $res = method_exists($stmt, 'get_result') ? $stmt->get_result() : null;
    $out = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $p = trim((string) ($r['phone'] ?? ''));
            $c = trim((string) ($r['city'] ?? ''));
            if ($p === '' || $c === '' || isset($out[$p])) {
                continue;
            }
            $exclude = $excludeSerialByPhone[$p] ?? '';
            if ($exclude !== '' && strcasecmp((string) ($r['serial'] ?? ''), $exclude) === 0) {
                continue;
            }
            $out[$p] = $c;
        }
    }
    $stmt->close();
    return $out;
}

/**
 * Whether a registered serial still needs city and/or km under seller/known-city rules.
 */
function serial_needs_completion(array $row, ?array $smsProcessing = null, ?string $knownCity = null): bool {
    unset($smsProcessing);
    $phone = isset($row['phone']) ? trim((string) $row['phone']) : '';
    if ($phone === '') {
        return false;
    }
    return serial_missing_fields($row, $knownCity) !== [];
}

function row_to_serial(array $row, ?array $smsProcessing = null, ?string $knownCity = null): array {
    $phone = isset($row['phone']) ? trim((string) $row['phone']) : '';
    $date = serial_date_jalali($row);
    $storedCity = isset($row['city']) && trim((string) $row['city']) !== '' ? trim((string) $row['city']) : null;
    $known = $knownCity !== null && trim($knownCity) !== '' ? trim($knownCity) : null;
    $cityInherited = $storedCity === null && $known !== null;
    $city = $cityInherited ? $known : $storedCity;
    $km = isset($row['km']) && $row['km'] !== null && $row['km'] !== '' ? (int) $row['km'] : null;
    $sentAt = null;
    if (isset($row['completion_reminder_sent_at']) && $row['completion_reminder_sent_at'] !== null && $row['completion_reminder_sent_at'] !== '') {
        $sentAt = (int) $row['completion_reminder_sent_at'];
        if ($sentAt <= 0) {
            $sentAt = null;
        }
    }
    $category = isset($row['category']) && trim((string) $row['category']) !== ''
        ? (string) $row['category']
        : 'end_user_client';
    $missing = serial_missing_fields($row, $known);
    $missingCity = in_array('city', $missing, true);
    $missingKm = in_array('km', $missing, true);
    $needs = $phone !== '' && ($missingCity || $missingKm);
    $pairedRubika = false;
    $pairedTelegram = false;
    $pairedBale = false;
    if ($phone !== '' && is_file(__DIR__ . '/bots_store.php')) {
        require_once __DIR__ . '/bots_store.php';
        if (is_array($smsProcessing) && isset($smsProcessing['__pairing']) && is_array($smsProcessing['__pairing'])) {
            $map = $smsProcessing['__pairing'];
            $pn = function_exists('comms_normalize_phone') ? comms_normalize_phone($phone) : $phone;
            if (isset($map[$pn])) {
                $pairedRubika = !empty($map[$pn]['rubika']);
                $pairedTelegram = !empty($map[$pn]['telegram']);
                $pairedBale = !empty($map[$pn]['bale']);
            }
        } else {
            $db = payamesh_mysqli();
            $pairedRubika = bots_is_phone_paired($db, 'rubika', $phone);
            $pairedTelegram = bots_is_phone_paired($db, 'telegram', $phone);
            $pairedBale = bots_is_phone_paired($db, 'bale', $phone);
        }
    }
    return [
        'id' => (int) $row['id'],
        'serial' => (string) $row['serial'],
        'phone' => $phone !== '' ? $phone : null,
        'date' => $date,
        'city' => $city,
        'km' => $km,
        'needsCompletion' => $needs,
        'missingCity' => $missingCity,
        'missingKm' => $missingKm,
        'cityInherited' => $cityInherited,
        'category' => $category,
        'completionReminderSent' => $sentAt !== null,
        'completionReminderSentAt' => $sentAt,
        'registrationSource' => $row['reg_source'] ?? null,
        'pairedRubika' => $pairedRubika,
        'pairedTelegram' => $pairedTelegram,
        'pairedBale' => $pairedBale,
    ];
}

/** Resolve an SMS message template by id from stored/default catalog. */
function crm_resolve_sms_template(mysqli $conn, string $messageId, array $values): string {
    require_once __DIR__ . '/settings_store.php';
    $catalog = function_exists('crm_sms_messages_effective')
        ? crm_sms_messages_effective($conn)
        : crm_settings_get_or_default($conn, 'sms_messages', crm_default_sms_messages());
    $template = null;
    foreach (($catalog['messages'] ?? []) as $msg) {
        if (($msg['id'] ?? '') === $messageId) {
            $template = (string) ($msg['template'] ?? '');
            break;
        }
    }
    if ($template === null || $template === '') {
        foreach (crm_default_sms_messages()['messages'] as $msg) {
            if (($msg['id'] ?? '') === $messageId) {
                $template = (string) ($msg['template'] ?? '');
                break;
            }
        }
    }
    if ($template === null) {
        $template = '';
    }
    foreach ($values as $token => $value) {
        $template = str_replace((string) $token, (string) $value, $template);
    }
    return $template;
}

/** Format warranty label for SMS templates (mirrors SerialGroupWarranty.formatFa). */
function crm_format_warranty_fa(?int $months, ?int $km): string {
    $parts = [];
    if ($months !== null && $months > 0) {
        $parts[] = $months . ' ماه';
    }
    if ($km !== null && $km > 0) {
        $parts[] = $km . ' کیلومتر';
    }
    return $parts !== [] ? implode(' یا ', $parts) : 'طبق شرایط محصول';
}

/**
 * Build registration-confirmation SMS (mirrors RegistrationConfirmationSmsService on Android).
 */
function crm_build_registration_confirmation_message(mysqli $conn, array $row): string {
    $serial = (string) ($row['serial'] ?? '');
    $warrantyLabel = 'طبق شرایط محصول';
    $groupId = isset($row['lan_group_id']) ? (int) $row['lan_group_id'] : 0;
    if ($groupId > 0 && table_exists($conn, 'serial_groups')) {
        $stmt = $conn->prepare('SELECT warranty_months, warranty_km, image_verification_required FROM serial_groups WHERE id=? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $groupId);
            $stmt->execute();
            $group = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($group) {
                $warrantyLabel = crm_format_warranty_fa(
                    isset($group['warranty_months']) ? (int) $group['warranty_months'] : null,
                    isset($group['warranty_km']) ? (int) $group['warranty_km'] : null,
                );
                $base = crm_resolve_sms_template($conn, 'reply_valid', ['@1' => $warrantyLabel]);
                $withSerial = $base . "\nسریال: " . $serial;
                if (!empty($group['image_verification_required'])) {
                    return $withSerial . "\n\nبرای شرکت در قرعه‌کشی / دریافت امتیاز، لطفاً عکس محصول سریال "
                        . $serial
                        . ' را از منوی «ارسال عکس محصول» ارسال کنید.';
                }
                return $withSerial;
            }
        }
    }
    $base = crm_resolve_sms_template($conn, 'reply_valid', ['@1' => $warrantyLabel]);
    return $base . "\nسریال: " . $serial;
}

/**
 * Build completion-reminder SMS body (mirrors CompletionReminderService on Android).
 *
 * @return string|null null when registration does not need completion
 */
function crm_build_completion_reminder_message(mysqli $conn, array $row): ?string {
    require_once __DIR__ . '/settings_store.php';
    $config = crm_settings_get_or_default($conn, 'sms_processing', crm_default_sms_processing());
    $phone = isset($row['phone']) ? trim((string) $row['phone']) : '';
    $knownCity = $phone !== '' ? serial_known_city_for_phone($conn, $phone, (string) ($row['serial'] ?? '')) : null;
    if (!serial_needs_completion($row, $config, $knownCity)) {
        return null;
    }
    $serial = (string) $row['serial'];
    $missing = serial_missing_fields($row, $knownCity);
    $missingCity = in_array('city', $missing, true);
    $missingKm = in_array('km', $missing, true);
    $exampleCity = 'تبریز';
    $exampleKm = '3350';
    $mode = (string) ($config['mode'] ?? 'SERIAL_ONLY');
    $stepFlow = (string) ($config['stepFlowType'] ?? 'SEQUENTIAL');

    if ($mode === 'STEP_BY_STEP' && $stepFlow === 'COMBINED_SECOND' && $missingCity && $missingKm) {
        return crm_resolve_sms_template($conn, 'completion_reminder_step_combined', [
            '@1' => $serial,
            '@2' => $exampleCity,
            '@3' => $exampleKm,
        ]);
    }
    if ($mode === 'STEP_BY_STEP' && $stepFlow === 'SEQUENTIAL') {
        $steps = ["سریال: $serial"];
        if ($missingCity) {
            $steps[] = "شهر: $exampleCity";
        }
        if ($missingKm) {
            $steps[] = "کیلومتر: $exampleKm";
        }
        return crm_resolve_sms_template($conn, 'completion_reminder_step_sequential', [
            '@1' => implode('، ', $steps),
        ]);
    }

    if ($missingCity && $missingKm) {
        $example = "$serial $exampleCity $exampleKm";
        $missingText = 'شهر و کیلومتر';
    } elseif ($missingCity) {
        $example = "$serial $exampleCity";
        $missingText = 'شهر';
    } elseif ($missingKm) {
        $example = "$serial $exampleKm";
        $missingText = 'کیلومتر';
    } else {
        $example = $serial;
        $missingText = 'اطلاعات';
    }
    return crm_resolve_sms_template($conn, 'completion_reminder', [
        '@1' => $missingText,
        '@2' => $example,
    ]);
}

/**
 * Send SMS via Melipayamak panel (same path as legacy CRM SMS.php / lottery helper).
 * @return true|string true on success, error message on failure
 */
function crm_send_panel_sms(string $phone, string $message) {
    $digits = preg_replace('/\D/', '', trim($phone));
    if ($digits !== '' && strpos($digits, '98') === 0 && strlen($digits) >= 12) {
        $digits = '0' . substr($digits, 2);
    }
    if ($digits !== '' && strlen($digits) === 10 && isset($digits[0]) && $digits[0] === '9') {
        $digits = '0' . $digits;
    }
    if ($digits === '' || trim($message) === '') {
        return 'Invalid phone or empty message';
    }
    if (!class_exists('SoapClient')) {
        return 'SOAP extension not available on server';
    }
    try {
        ini_set('soap.wsdl_cache_enabled', '0');
        $sms = new SoapClient('http://api.payamak-panel.com/post/Send.asmx?wsdl', [
            'encoding' => 'UTF-8',
            'connection_timeout' => 15,
            'default_socket_timeout' => 20,
        ]);
        $data = [
            'username' => '09121777039',
            'password' => '4thvahdati@FB',
            'to' => [$digits],
            'from' => '20001390',
            'text' => $message,
            'isflash' => false,
        ];
        $sms->SendSimpleSMS($data)->SendSimpleSMSResult;
        return true;
    } catch (Throwable $e) {
        error_log('[crm_send_panel_sms] ' . $e->getMessage());
        return 'SMS panel error: ' . $e->getMessage();
    }
}

/** Prefer date_jalali; otherwise convert Gregorian `time` → Jalali YYYY/MM/DD. */
function serial_date_jalali(array $row): ?string {
    if (!empty($row['date_jalali'])) {
        $raw = trim((string) $row['date_jalali']);
        // Already Jalali-like YYYY/MM/DD
        if (preg_match('#^\d{4}/\d{2}/\d{2}#', $raw)) {
            return substr($raw, 0, 10);
        }
    }
    if (!empty($row['time'])) {
        return gregorian_value_to_jalali((string) $row['time']);
    }
    return null;
}

/** Accept `Y-m-d`, `Y-m-d H:i:s`, or similar → Jalali `YYYY/MM/DD`. */
function gregorian_value_to_jalali(string $value): ?string {
    $value = trim($value);
    if ($value === '' || str_starts_with_compat($value, '0000')) {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
        $parts = jalali_from_gregorian((int) $m[1], (int) $m[2], (int) $m[3]);
        return format_jalali($parts[0], $parts[1], $parts[2]);
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    $parts = jalali_from_gregorian((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));
    return format_jalali($parts[0], $parts[1], $parts[2]);
}

function str_starts_with_compat(string $haystack, string $needle): bool {
    return starts_with($haystack, $needle);
}

function jalali_from_gregorian(int $gy, int $gm, int $gd): array {
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = $gm > 2 ? $gy + 1 : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
        + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

function jalali_to_gregorian(int $jy, int $jm, int $jd): array {
    $jy += 1595;
    $days = -355668 + (365 * $jy) + intdiv($jy, 33) * 8 + intdiv(($jy % 33) + 3, 4) + $jd
        + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30 + 186));
    $gy = 400 * intdiv($days, 146097);
    $days %= 146097;
    if ($days > 36524) {
        $gy += 100 * intdiv(--$days, 36524);
        $days %= 36524;
        if ($days >= 365) {
            $days++;
        }
    }
    $gy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $sal_a = [0, 31, ($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0) ? 29 : 28,
        31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $gm = 0;
    for ($gm = 1; $gm <= 12 && $gd > $sal_a[$gm]; $gm++) {
        $gd -= $sal_a[$gm];
    }
    return [$gy, $gm, $gd];
}

function jalali_days_in_month(int $jy, int $jm): int {
    if ($jm <= 6) {
        return 31;
    }
    if ($jm <= 11) {
        return 30;
    }
    $mod = $jy % 33;
    $leap = in_array($mod, [1, 5, 9, 13, 17, 22, 26, 30], true);
    return $leap ? 30 : 29;
}

function format_jalali(int $y, int $m, int $d): string {
    return sprintf('%04d/%02d/%02d', $y, $m, $d);
}

/**
 * Inclusive list of Jalali YYYY/MM/DD keys from $from through $to (capped ~3 years).
 * @return list<string>
 */
function jalali_each_day(string $from, string $to): array
{
    $fp = parse_jalali_date($from);
    $tp = parse_jalali_date($to);
    if ($fp === null || $tp === null) {
        return [];
    }
    $startKey = format_jalali($fp[0], $fp[1], $fp[2]);
    $endKey = format_jalali($tp[0], $tp[1], $tp[2]);
    if ($startKey > $endKey) {
        return [];
    }
    $y = $fp[0];
    $m = $fp[1];
    $d = $fp[2];
    $out = [];
    for ($i = 0; $i < 1200; $i++) {
        $key = format_jalali($y, $m, $d);
        if ($key > $endKey) {
            break;
        }
        $out[] = $key;
        $d++;
        $dim = jalali_days_in_month($y, $m);
        if ($d > $dim) {
            $d = 1;
            $m++;
            if ($m > 12) {
                $m = 1;
                $y++;
            }
        }
    }
    return $out;
}

function format_gregorian(int $y, int $m, int $d): string {
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

function today_jalali_date(): string {
    $parts = jalali_from_gregorian((int) date('Y'), (int) date('n'), (int) date('j'));
    return format_jalali($parts[0], $parts[1], $parts[2]);
}

/**
 * Normalize a Jalali date query param to YYYY/MM/DD (or '' if empty/invalid).
 */
function normalize_jalali_date_param(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $parsed = parse_jalali_date($raw);
    if ($parsed === null) {
        return '';
    }
    return format_jalali($parsed[0], $parsed[1], $parsed[2]);
}

/**
 * Build WHERE fragments for a Jalali registration-date range.
 *
 * Uses date_jalali when present (YYYY/MM/DD); otherwise falls back to Gregorian `time`.
 * Rows with neither a usable date_jalali nor time are EXCLUDED when a range is set
 * (unlike the old bug that kept NULL dates in every range).
 *
 * @return array{0:string[],1:string,2:array} [whereParts, types, params]
 */
function serial_date_range_filters(mysqli $conn, string $table, string $from, string $to): array {
    $where = [];
    $types = '';
    $params = [];
    if ($from === '' && $to === '') {
        return [$where, $types, $params];
    }

    $hasJalali = column_exists($conn, $table, 'date_jalali');
    $hasTime = column_exists($conn, $table, 'time');

    $gFrom = null;
    $gTo = null;
    if ($from !== '') {
        $fp = parse_jalali_date($from);
        if ($fp !== null) {
            $g = jalali_to_gregorian($fp[0], $fp[1], $fp[2]);
            $gFrom = format_gregorian($g[0], $g[1], $g[2]);
        }
    }
    if ($to !== '') {
        $tp = parse_jalali_date($to);
        if ($tp !== null) {
            $g = jalali_to_gregorian($tp[0], $tp[1], $tp[2]);
            $gTo = format_gregorian($g[0], $g[1], $g[2]);
        }
    }

    // Prefer date_jalali (index-friendly string compare); fall back to Gregorian time range.
    $jalaliOk = 'date_jalali IS NOT NULL AND date_jalali <> \'\'';
    $jalaliMissing = $hasJalali ? '(date_jalali IS NULL OR date_jalali = \'\')' : '1=1';
    $timeOk = $hasTime ? "(time IS NOT NULL AND time <> '0000-00-00 00:00:00')" : '0=1';

    if ($from !== '') {
        $parts = [];
        if ($hasJalali) {
            $parts[] = "($jalaliOk AND date_jalali >= ?)";
            $types .= 's';
            $params[] = $from;
        }
        if ($hasTime && $gFrom !== null) {
            $parts[] = "($jalaliMissing AND $timeOk AND time >= ?)";
            $types .= 's';
            $params[] = $gFrom . ' 00:00:00';
        }
        if ($parts === []) {
            // No usable date columns — force empty result when a from filter is requested.
            $where[] = '0=1';
        } else {
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
    }

    if ($to !== '') {
        $parts = [];
        if ($hasJalali) {
            $parts[] = "($jalaliOk AND date_jalali <= ?)";
            $types .= 's';
            $params[] = $to;
        }
        if ($hasTime && $gTo !== null) {
            $parts[] = "($jalaliMissing AND $timeOk AND time < DATE_ADD(?, INTERVAL 1 DAY))";
            $types .= 's';
            $params[] = $gTo;
        }
        if ($parts === []) {
            $where[] = '0=1';
        } else {
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
    }

    return [$where, $types, $params];
}

function current_jalali_month_range(): array {
    $now = jalali_from_gregorian((int) date('Y'), (int) date('n'), (int) date('j'));
    $y = $now[0];
    $m = $now[1];
    $last = jalali_days_in_month($y, $m);
    return [format_jalali($y, $m, 1), format_jalali($y, $m, $last)];
}

/** Parse YYYY/MM/DD Jalali → [y,m,d] or null. */
function parse_jalali_date(string $s): ?array {
    $s = trim($s);
    if (!preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})#', $s, $m)) {
        return null;
    }
    return [(int) $m[1], (int) $m[2], (int) $m[3]];
}

/** Stable synthetic group ids for category buckets (remote has no LAN group table). */
function synthetic_group_id_for_category(string $category): int {
    return $category === 'seller_to_end_user' ? 2 : 1;
}

function synthetic_category_for_group_id(int $id): ?string {
    if ($id === 2) {
        return 'seller_to_end_user';
    }
    if ($id === 1) {
        return 'end_user_client';
    }
    return null;
}

