<?php
/**
 * Front controller for remote CRM REST API (LAN-compatible paths).
 * Deploy under /crm/api/ with sibling config/ and Vue at /crm/.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
crm_debug_init($path, $method);

// Strip /crm/api or /api prefix
$path = preg_replace('#^.*?/api(?:/index\.php)?#', '', $path) ?? '';
$path = '/' . trim($path, '/');
if ($path === '/') {
    $path = '';
}

// Lightweight public probes — no DB / schema (survives partial deploys).
if ($path === '/ping' && $method === 'GET') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'CRM OK';
    exit;
}

if ($path === '/health' && $method === 'GET') {
    json_out([
        'ok' => true,
        'version' => 'remote-mvp-1',
        'serverTime' => (int) (microtime(true) * 1000),
        'php' => PHP_VERSION,
        'hasDbConfig' => is_file(dirname(__DIR__) . '/config/database.php'),
    ]);
}

// Device cloud-sync (Bearer PAYAMESH_SYNC_TOKEN) — before CRM session auth
if ($path === '/sync/buffer' && $method === 'GET') {
    require __DIR__ . '/sync/buffer/index.php';
    exit;
}
if ($path === '/sync/buffer/apply' && $method === 'POST') {
    require __DIR__ . '/sync/buffer/apply.php';
    exit;
}
if ($path === '/sync/buffer/ack' && $method === 'POST') {
    require __DIR__ . '/sync/buffer/ack.php';
    exit;
}
if ($path === '/sync/buffer/backfill' && $method === 'POST') {
    require __DIR__ . '/sync/buffer/backfill.php';
    exit;
}
// Dedicated date lookup for null-date repair (not buffer sync).
if ($path === '/sync/serials/dates' && $method === 'POST') {
    require __DIR__ . '/sync/serials/dates.php';
    exit;
}
// Legacy hybrid endpoints retired in favor of dual-buffer exchange.
if (
    ($path === '/sync/push' && $method === 'POST')
    || ($path === '/sync/pull' && $method === 'GET')
    || ($path === '/sync/manifest' && $method === 'GET')
    || ($path === '/sync/pull/group' && $method === 'GET')
) {
    http_response_code(410);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'Gone',
        'message' => 'Use /api/sync/buffer, /api/sync/buffer/apply, /api/sync/buffer/ack',
    ]);
    exit;
}

crm_debug_mark('bootstrap_loaded');
$conn = payamesh_mysqli();
crm_debug_mark('db_connect');
if (starts_with($path, '/debug/')) {
    require __DIR__ . '/debug_db.php';
    exit;
}
ensure_remote_schema($conn);
crm_debug_mark('ensure_remote_schema');

// --- Public (kept for older clients that expected health after schema) ---
if ($path === '/ping' && $method === 'GET') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'CRM OK';
    exit;
}

if ($path === '/health' && $method === 'GET') {
    json_out([
        'ok' => true,
        'version' => 'remote-mvp-1',
        'serverTime' => (int) (microtime(true) * 1000),
    ]);
}

if ($path === '/auth/login' && $method === 'POST') {
    $body = body_json();
    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    if ($username === '' || $password === '') {
        json_error('Invalid credentials', 401);
    }
    $stmt = $conn->prepare('SELECT id, name, pass, role FROM users WHERE name = ? LIMIT 1');
    if (!$stmt) {
        json_error('DB prepare failed: ' . $conn->error, 500);
    }
    $stmt->bind_param('s', $username);
    if (!$stmt->execute()) {
        json_error('DB execute failed: ' . $stmt->error, 500);
    }
    $user = stmt_fetch_assoc($stmt);
    $stmt->close();
    if (!$user || !verify_password($password, (string) $user['pass'])) {
        json_error('Invalid credentials', 401);
    }
    $token = bin2hex(random_bytes(24));
    $expiresStr = (string) ((int) round(microtime(true) * 1000) + (7 * 24 * 60 * 60 * 1000));
    $uid = (int) $user['id'];
    $uname = (string) $user['name'];
    $role = (int) $user['role'];
    $ins = $conn->prepare(
        'INSERT INTO crm_sessions (token, user_id, username, role, expires_at) VALUES (?, ?, ?, ?, ?)'
    );
    if (!$ins) {
        json_error('Session prepare failed: ' . $conn->error, 500);
    }
    // expires as string avoids 32-bit int overflow on some hosts
    $ins->bind_param('sisis', $token, $uid, $uname, $role, $expiresStr);
    if (!$ins->execute()) {
        json_error('Session save failed: ' . $ins->error, 500);
    }
    $ins->close();
    json_out(['token' => $token, 'username' => $uname, 'role' => $role]);
}

// Everything below requires auth
$user = require_auth($conn);
crm_debug_mark('auth_ok', $user['username'] ?? '');
crm_schedule_perf_index_build($conn);

if ($path === '/auth/me' && $method === 'GET') {
    json_out(['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role']]);
}

if ($path === '/sync' && $method === 'GET') {
    $table = serials_table($conn);
    $since = (int) ($_GET['since'] ?? 0);
    $stats = serial_cached_stats($conn, $table, $since === 0);
    crm_debug_mark('sync_cached_stats', 'since=' . $since);
    $now = (int) (microtime(true) * 1000);
    require_once __DIR__ . '/activity_store.php';
    $activity = crm_activity_list_since($conn, $since, 80);
    crm_debug_mark('sync_activity_list', count($activity) . ' rows');
    $serialChanges = sync_changes_from_serials($conn, $since);
    crm_debug_mark('sync_serial_changes', count($serialChanges) . ' rows');
    $changes = crm_activity_merge_changes($activity, $serialChanges, 50);
    crm_debug_mark('sync_merge_changes', count($changes) . ' merged');
    json_out([
        'serverTime' => $now,
        'cursor' => $now,
        'stats' => [
            'total' => $stats['total'],
            'unused' => $stats['unused'],
            'registered' => $stats['registered'],
        ],
        'changes' => $changes,
        'sims' => [],
    ]);
}

if ($path === '/stats/registrations-by-day' && $method === 'GET') {
    [$defaultFrom, $defaultTo] = current_jalali_month_range();
    $from = normalize_jalali_date_param(trim((string) ($_GET['from'] ?? '')));
    $to = normalize_jalali_date_param(trim((string) ($_GET['to'] ?? '')));
    if ($from === '') {
        $from = $defaultFrom;
    }
    if ($to === '') {
        $to = $defaultTo;
    }
    if ($from > $to) {
        $tmp = $from;
        $from = $to;
        $to = $tmp;
    }
    $table = serials_table($conn);
    $hasJalali = column_exists($conn, $table, 'date_jalali');
    $counts = [];

    // 1) Rows that already have Jalali date_jalali
    if ($hasJalali) {
        $sql = "SELECT LEFT(date_jalali, 10) AS day, COUNT(*) AS c
                FROM `$table`
                WHERE " . is_registered_sql() . "
                  AND date_jalali IS NOT NULL AND date_jalali <> ''
                  AND date_jalali >= ? AND date_jalali <= ?
                GROUP BY LEFT(date_jalali, 10)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ss', $from, $to);
            $stmt->execute();
            $res = method_exists($stmt, 'get_result') ? $stmt->get_result() : null;
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $day = (string) $row['day'];
                    $counts[$day] = ($counts[$day] ?? 0) + (int) $row['c'];
                }
            }
            $stmt->close();
        }
        crm_debug_mark('chart_jalali_group_by', count($counts) . ' days');
    }

    // 2) Legacy rows: only `time` (Gregorian) — convert day → Jalali for the chart
    $fp = parse_jalali_date($from);
    $tp = parse_jalali_date($to);
    if ($fp && $tp) {
        $gFrom = jalali_to_gregorian($fp[0], $fp[1], $fp[2]);
        $gTo = jalali_to_gregorian($tp[0], $tp[1], $tp[2]);
        $gFromStr = format_gregorian($gFrom[0], $gFrom[1], $gFrom[2]);
        $gToStr = format_gregorian($gTo[0], $gTo[1], $gTo[2]);
        $jalaliFilter = $hasJalali
            ? "AND (date_jalali IS NULL OR date_jalali = '')"
            : '';
        $sql = "SELECT DATE(`time`) AS gday, COUNT(*) AS c
                FROM `$table`
                WHERE " . is_registered_sql() . "
                  AND `time` IS NOT NULL
                  AND `time` >= ? AND `time` < DATE_ADD(?, INTERVAL 1 DAY)
                  $jalaliFilter
                GROUP BY DATE(`time`)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ss', $gFromStr, $gToStr);
            $stmt->execute();
            $res = method_exists($stmt, 'get_result') ? $stmt->get_result() : null;
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $gday = (string) ($row['gday'] ?? '');
                    $j = gregorian_value_to_jalali($gday);
                    if ($j === null) {
                        continue;
                    }
                    if ($j < $from || $j > $to) {
                        continue;
                    }
                    $counts[$j] = ($counts[$j] ?? 0) + (int) $row['c'];
                }
            }
            $stmt->close();
        }
        crm_debug_mark('chart_time_group_by', count($counts) . ' days total');
    }

    $days = [];
    $axis = jalali_each_day($from, $to);
    if ($axis !== []) {
        foreach ($axis as $key) {
            $days[] = ['day' => $key, 'count' => $counts[$key] ?? 0];
        }
    } else {
        $keys = array_keys($counts);
        sort($keys);
        foreach ($keys as $key) {
            if ($key < $from || $key > $to) {
                continue;
            }
            $days[] = ['day' => $key, 'count' => $counts[$key]];
        }
    }
    crm_debug_mark('chart_axis_build', count($days) . ' axis points');
    json_out(['from' => $from, 'to' => $to, 'days' => $days]);
}

if ($path === '/serials' && $method === 'GET') {
    $table = serials_table($conn);
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(500, max(1, (int) ($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;
    $registeredParam = $_GET['registered'] ?? null;
    $search = trim((string) ($_GET['search'] ?? ''));
    $from = normalize_jalali_date_param((string) ($_GET['from'] ?? ''));
    $to = normalize_jalali_date_param((string) ($_GET['to'] ?? ''));
    $defaultEndToday = isset($_GET['defaultEndToday']) && (string) $_GET['defaultEndToday'] === 'true';
    if ($to === '' && $defaultEndToday) {
        $to = today_jalali_date();
    }

    $categoryParams = [];
    if (isset($_GET['category'])) {
        $raw = $_GET['category'];
        if (is_array($raw)) {
            foreach ($raw as $c) {
                foreach (explode(',', (string) $c) as $p) {
                    $categoryParams[] = trim($p);
                }
            }
        } else {
            foreach (explode(',', (string) $raw) as $p) {
                $categoryParams[] = trim($p);
            }
        }
    }
    $categoryParams = array_values(array_filter($categoryParams));

    $groupIds = [];
    if (isset($_GET['groupIds'])) {
        $rawGroups = $_GET['groupIds'];
        $rawList = is_array($rawGroups) ? $rawGroups : [$rawGroups];
        foreach ($rawList as $g) {
            foreach (explode(',', (string) $g) as $p) {
                $p = trim($p);
                if ($p !== '' && ctype_digit($p)) {
                    $groupIds[] = (int) $p;
                }
            }
        }
    }
    $groupIds = array_values(array_unique($groupIds));

    $sourceParams = [];
    if (isset($_GET['source'])) {
        $rawSource = $_GET['source'];
        $rawList = is_array($rawSource) ? $rawSource : [$rawSource];
        foreach ($rawList as $s) {
            foreach (explode(',', (string) $s) as $p) {
                $p = strtolower(trim($p));
                if ($p !== '') {
                    $sourceParams[] = $p;
                }
            }
        }
    }
    $sourceParams = array_values(array_unique($sourceParams));

    $provinceId = trim((string) ($_GET['provinceId'] ?? ''));
    $cityName = trim((string) ($_GET['cityName'] ?? ''));
    if ($provinceId === '') {
        $cityName = '';
    }

    $where = ['1=1'];
    $types = '';
    $params = [];

    if ($registeredParam === 'true') {
        $where[] = is_registered_sql();
    } elseif ($registeredParam === 'false') {
        $where[] = 'NOT ' . is_registered_sql();
    }

    [$dateWhere, $dateTypes, $dateParams] = serial_date_range_filters($conn, $table, $from, $to);
    foreach ($dateWhere as $w) {
        $where[] = $w;
    }
    $types .= $dateTypes;
    foreach ($dateParams as $p) {
        $params[] = $p;
    }

    $hasCategory = column_exists($conn, $table, 'category');
    if ($hasCategory && $categoryParams !== []) {
        $placeholders = implode(',', array_fill(0, count($categoryParams), '?'));
        $where[] = "category IN ($placeholders)";
        $types .= str_repeat('s', count($categoryParams));
        foreach ($categoryParams as $c) {
            $params[] = $c;
        }
    }

    $hasRegSource = column_exists($conn, $table, 'reg_source');
    if ($hasRegSource && $sourceParams !== []) {
        $sourceClauses = [];
        foreach ($sourceParams as $src) {
            if ($src === 'sms') {
                $sourceClauses[] = "(reg_source = 'sms' OR reg_source IS NULL OR reg_source = '')";
            } else {
                $sourceClauses[] = 'reg_source = ?';
                $types .= 's';
                $params[] = $src;
            }
        }
        if ($sourceClauses !== []) {
            $where[] = '(' . implode(' OR ', $sourceClauses) . ')';
        }
    }

    // groupIds: real serial_groups ids and/or legacy inventory bucket ids (900001/900002).
    if ($groupIds !== []) {
        require_once __DIR__ . '/serials_write.php';
        sw_ensure_columns($conn, $table);
        $groupClauses = [];
        $realIds = [];
        foreach ($groupIds as $gid) {
            if (sw_is_legacy_group_id($gid)) {
                $cat = sw_legacy_category_for_group_id($gid);
                $ungrouped = sw_ungrouped_sql($table);
                if ($hasCategory && $cat !== null) {
                    $groupClauses[] = "($ungrouped AND category = ?)";
                    $types .= 's';
                    $params[] = $cat;
                } else {
                    $groupClauses[] = "($ungrouped)";
                }
            } else {
                $realIds[] = $gid;
            }
        }
        if ($realIds !== []) {
            $placeholders = implode(',', array_fill(0, count($realIds), '?'));
            $groupClauses[] = "lan_group_id IN ($placeholders)";
            $types .= str_repeat('i', count($realIds));
            foreach ($realIds as $gid) {
                $params[] = $gid;
            }
        }
        if ($groupClauses !== []) {
            $where[] = '(' . implode(' OR ', $groupClauses) . ')';
        }
    }

    require_once __DIR__ . '/iran_locations.php';
    [$locWhere, $locTypes, $locParams] = serial_location_filters($provinceId, $cityName);
    foreach ($locWhere as $w) {
        $where[] = $w;
    }
    $types .= $locTypes;
    foreach ($locParams as $p) {
        $params[] = $p;
    }

    $order = serial_list_order_sql($conn, $table, $registeredParam === 'true');
    // Optimizer picks idx_serials_phone (~50k row lookups + filesort, ~20s here). For broad lists,
    // walking the ORDER BY index backwards stops after LIMIT rows. Selective filters keep the optimizer's choice.
    $indexHint = '';
    if (
        $registeredParam === 'true'
        && $search === ''
        && $groupIds === []
        && $provinceId === ''
        && serial_table_has_index($conn, $table, 'idx_serials_reg_date')
    ) {
        $indexHint = 'FORCE INDEX (idx_serials_reg_date)';
    }
    $baseWhere = $where;
    $baseTypes = $types;
    $baseParams = $params;
    $applySearch = static function (?array $filter) use ($baseWhere, $baseTypes, $baseParams): array {
        $w = $baseWhere;
        $t = $baseTypes;
        $p = $baseParams;
        if ($filter !== null) {
            $w[] = $filter[0];
            $t .= $filter[1];
            foreach ($filter[2] as $v) {
                $p[] = $v;
            }
        }
        return [implode(' AND ', $w), $t, $p];
    };
    $runList = static function (string $whereSql, string $types, array $params) use ($conn, $table, $order, $limit, $offset, $indexHint): array {
        $stmt = $conn->prepare("SELECT * FROM `$table` $indexHint WHERE $whereSql $order LIMIT ? OFFSET ?");
        if (!$stmt) {
            json_error('DB prepare failed: ' . $conn->error, 500);
        }
        $types2 = $types . 'ii';
        $params2 = array_merge($params, [$limit, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        if (crm_debug_enabled()) {
            crm_debug_mark('serials_list_plan', trim($indexHint . ' ' . $order) . ' | ' . crm_debug_explain($conn, "SELECT * FROM `$table` $indexHint WHERE $whereSql $order LIMIT ? OFFSET ?", $types2, $params2));
        }
        return $rows;
    };

    $searchMode = 'none';
    if ($search === '') {
        [$whereSql, $types, $params] = $applySearch(null);
        $rawRows = $runList($whereSql, $types, $params);
    } else {
        $fastFilter = serial_search_filter($search, true);
        $rawRows = [];
        if ($fastFilter !== null) {
            [$whereSql, $types, $params] = $applySearch($fastFilter);
            $rawRows = $runList($whereSql, $types, $params);
            $searchMode = 'prefix';
            crm_debug_mark('serials_search_prefix', count($rawRows) . ' rows');
        }
        // A full serial ("S410390") can't match mid-string, so the full-scan fallback is pointless.
        $looksLikeFullSerial = (bool) preg_match('/^[A-Za-z]+[0-9۰-۹٠-٩]{4,}$/u', trim($search));
        $useSubstring = $rawRows === [] && !$looksLikeFullSerial;
        if ($useSubstring && $fastFilter !== null && $page > 1) {
            // Empty later page: only switch modes if the prefix search had no matches at all.
            $probe = $conn->prepare("SELECT 1 FROM `$table` WHERE $whereSql LIMIT 1");
            if ($probe) {
                if ($types !== '') {
                    $probe->bind_param($types, ...$params);
                }
                $probe->execute();
                $useSubstring = stmt_fetch_assoc($probe) === null;
                $probe->close();
            }
        }
        if ($useSubstring) {
            [$whereSql, $types, $params] = $applySearch(serial_search_filter($search, false));
            $rawRows = $runList($whereSql, $types, $params);
            $searchMode = 'substring';
            crm_debug_mark('serials_search_substring', count($rawRows) . ' rows');
        }
    }
    require_once __DIR__ . '/settings_store.php';
    $smsProcessing = crm_settings_get_or_default($conn, 'sms_processing', crm_default_sms_processing());
    crm_debug_mark('serials_list_query', count($rawRows) . ' rows page=' . $page . ' search=' . $searchMode);

    $pageNotFull = count($rawRows) < $limit && ($rawRows !== [] || $page === 1);
    if ($pageNotFull) {
        $total = $offset + count($rawRows);
        crm_debug_mark('serials_count_skipped', 'total=' . $total . ' (page not full)');
    } elseif (
        serial_list_uses_cached_total(
            $registeredParam,
            $search,
            $from,
            $to,
            $sourceParams,
            $categoryParams,
            $groupIds,
            $provinceId
        )
    ) {
        $total = serial_cached_stats($conn, $table)['registered'];
        crm_debug_mark('serials_count_cached', 'total=' . $total);
    } else {
        $countSql = "SELECT COUNT(*) AS c FROM `$table` WHERE $whereSql";
        if ($types !== '') {
            $countStmt = $conn->prepare($countSql);
            $countStmt->bind_param($types, ...$params);
            $countStmt->execute();
            $total = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
            $countStmt->close();
        } else {
            $total = (int) ($conn->query($countSql)->fetch_assoc()['c'] ?? 0);
        }
        crm_debug_mark('serials_count_sql', 'total=' . $total);
    }

    $phones = [];
    $excludeSerialByPhone = [];
    foreach ($rawRows as $row) {
        $p = isset($row['phone']) ? trim((string) $row['phone']) : '';
        if ($p !== '') {
            $phones[] = $p;
            if (empty($row['city']) || trim((string) $row['city']) === '') {
                $excludeSerialByPhone[$p] = (string) ($row['serial'] ?? '');
            }
        }
    }
    $knownByPhone = serial_known_cities_for_phones($conn, $phones, $excludeSerialByPhone);
    crm_debug_mark('serials_known_cities', count($knownByPhone) . ' phones');
    require_once __DIR__ . '/bots_store.php';
    $pairing = bots_pairing_map_for_phones($conn, $phones);
    crm_debug_mark('serials_bot_pairing', count($phones) . ' phones');
    $smsProcessing['__pairing'] = $pairing;
    $items = [];
    foreach ($rawRows as $row) {
        $p = isset($row['phone']) ? trim((string) $row['phone']) : '';
        $known = $p !== '' ? ($knownByPhone[$p] ?? null) : null;
        $items[] = row_to_serial($row, $smsProcessing, $known);
    }
    crm_debug_mark('serials_row_map', count($items) . ' items');
    json_out(['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit]);
}

/**
 * Persist missing date_jalali from Gregorian `time` for registered rows that show
 * null dates in reports (display already falls back via serial_date_jalali).
 * Does not touch dual-buffer get/send/backfill.
 */
if ($path === '/serials/repair-null-dates' && $method === 'POST') {
    require_once __DIR__ . '/serials_write.php';
    $table = serials_table($conn);
    sw_ensure_columns($conn, $table);
    $hasJalali = column_exists($conn, $table, 'date_jalali');
    if (!$hasJalali) {
        json_out([
            'ok' => true,
            'scanned' => 0,
            'fixedLocal' => 0,
            'fixedRemote' => 0,
            'unfixed' => 0,
            'message' => 'ستون date_jalali در دسترس نیست',
        ]);
    }

    $registeredSql = is_registered_sql();
    $nullJalali = "(date_jalali IS NULL OR TRIM(date_jalali) = '' OR LOWER(TRIM(date_jalali)) = 'null')";
    $countRes = $conn->query(
        "SELECT COUNT(*) AS c FROM `$table` WHERE $registeredSql AND $nullJalali"
    );
    $scanned = ($countRes && ($cr = $countRes->fetch_assoc())) ? (int) ($cr['c'] ?? 0) : 0;

    $fixedLocal = 0;
    $unfixed = 0;
    $res = $conn->query(
        "SELECT * FROM `$table` WHERE $registeredSql AND $nullJalali ORDER BY id ASC LIMIT 5000"
    );
    require_once __DIR__ . '/sync/sync_buffer_helpers.php';
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $date = serial_date_jalali($row);
            if ($date === null || trim($date) === '') {
                $unfixed++;
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                $unfixed++;
                continue;
            }
            $syncMs = (int) round(microtime(true) * 1000);
            $sets = ['date_jalali=?'];
            $types = 's';
            $bind = [$date];
            if (column_exists($conn, $table, 'sync_updated_ms')) {
                $sets[] = 'sync_updated_ms=?';
                $types .= 'i';
                $bind[] = $syncMs;
            }
            $types .= 'i';
            $bind[] = $id;
            $sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE id=? AND $nullJalali";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $unfixed++;
                continue;
            }
            $stmt->bind_param($types, ...$bind);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected <= 0) {
                $unfixed++;
                continue;
            }
            $fixedLocal++;
            $row['date_jalali'] = $date;
            if (column_exists($conn, $table, 'sync_updated_ms')) {
                $row['sync_updated_ms'] = $syncMs;
            }
            // Notify phones of the repaired date without running a full buffer get/send.
            sync_buffer_enqueue_serial_row($conn, $row);
        }
    }

    $message = 'بررسی ' . $scanned . ' ردیف — محلی: ' . $fixedLocal;
    if ($unfixed > 0) {
        $message .= '، بدون تاریخ: ' . $unfixed;
    }
    json_out([
        'ok' => true,
        'scanned' => $scanned,
        'fixedLocal' => $fixedLocal,
        'fixedRemote' => 0,
        'unfixed' => $unfixed,
        'message' => $message,
    ]);
}

if (preg_match('#^/serials/(\d+)/send-completion-reminder$#', $path, $m) && $method === 'POST') {
    $id = (int) $m[1];
    $table = serials_table($conn);
    ensure_remote_schema($conn);
    require_once __DIR__ . '/settings_store.php';
    $smsProcessing = crm_settings_get_or_default($conn, 'sms_processing', crm_default_sms_processing());

    $got = $conn->query("SELECT * FROM `$table` WHERE id = " . (int) $id)->fetch_assoc();
    if (!$got) {
        json_error('Serial not found', 404);
    }
    $phone = isset($got['phone']) ? trim((string) $got['phone']) : '';
    if ($phone === '') {
        json_error('Serial has no phone number', 400);
    }
    $knownCity = serial_known_city_for_phone($conn, $phone, (string) ($got['serial'] ?? ''));
    if (!serial_needs_completion($got, $smsProcessing, $knownCity)) {
        json_error('Registration is already complete', 400);
    }
    if (!empty($got['completion_reminder_sent_at'])) {
        json_error('Completion reminder already sent', 409);
    }

    $message = crm_build_completion_reminder_message($conn, $got);
    if ($message === null || $message === '') {
        json_error('Registration is already complete', 400);
    }

    $sendResult = crm_send_panel_sms($phone, $message);
    if ($sendResult !== true) {
        json_error(
            is_string($sendResult) && $sendResult !== ''
                ? $sendResult
                : 'SMS send failed',
            502
        );
    }

    $sentAt = (string) ((int) round(microtime(true) * 1000));
    if (column_exists($conn, $table, 'completion_reminder_sent_at')) {
        $upd = $conn->prepare("UPDATE `$table` SET completion_reminder_sent_at = ? WHERE id = ?");
        if ($upd) {
            $upd->bind_param('si', $sentAt, $id);
            $upd->execute();
            $upd->close();
        }
    }

    $refreshed = $conn->query("SELECT * FROM `$table` WHERE id = " . (int) $id)->fetch_assoc();
    if (!$refreshed) {
        json_error('Serial not found', 404);
    }
    $knownCity = serial_known_city_for_phone(
        $conn,
        trim((string) ($refreshed['phone'] ?? '')),
        (string) ($refreshed['serial'] ?? '')
    );
    json_out(row_to_serial($refreshed, $smsProcessing, $knownCity));
}

if (preg_match('#^/serials/(\d+)$#', $path, $m) && $method === 'PUT') {
    $id = (int) $m[1];
    $body = body_json();
    $table = serials_table($conn);
    require_once __DIR__ . '/comms_store.php';
    $existing = $conn->query("SELECT * FROM `$table` WHERE id = " . (int) $id)->fetch_assoc();
    if (!$existing) {
        json_error('Not found', 404);
    }
    $serial = trim((string) ($body['serial'] ?? ''));
    $date = trim((string) ($body['date'] ?? ''));
    if ($serial === '') {
        json_error('serial required');
    }
    $oldPhone = comms_normalize_phone((string) ($existing['phone'] ?? ''));
    $phoneInput = array_key_exists('phone', $body) ? trim((string) $body['phone']) : '';
    $newPhone = $phoneInput !== '' ? comms_normalize_phone($phoneInput) : $oldPhone;
    if ($newPhone === '' || !preg_match('/^09\d{9}$/', $newPhone)) {
        json_error('شماره موبایل نامعتبر است', 400);
    }
    $citySql = array_key_exists('city', $body)
        ? ($body['city'] !== null ? (string) $body['city'] : null)
        : null;
    $hasKm = array_key_exists('km', $body);
    $kmSql = $hasKm && $body['km'] !== null && $body['km'] !== '' ? (int) $body['km'] : null;
    $hasJalali = column_exists($conn, $table, 'date_jalali');
    $phoneChanged = $newPhone !== $oldPhone;

    $sets = ['serial=?', 'phone=?'];
    $types = 'ss';
    $bind = [$serial, $newPhone];
    if ($hasJalali) {
        $sets[] = 'date_jalali=?';
        $types .= 's';
        $bind[] = $date;
    }
    $sets[] = 'city=?';
    $types .= 's';
    $bind[] = $citySql;
    if ($hasKm) {
        if ($kmSql === null) {
            $sets[] = 'km=NULL';
        } else {
            $sets[] = 'km=?';
            $types .= 'i';
            $bind[] = $kmSql;
        }
    }
    if (column_exists($conn, $table, 'sync_updated_ms')) {
        $sets[] = 'sync_updated_ms=?';
        $types .= 'i';
        $bind[] = (int) round(microtime(true) * 1000);
    }
    $types .= 'i';
    $bind[] = $id;
    $sql = "UPDATE `$table` SET " . implode(', ', $sets) . ' WHERE id=?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$bind);
    $stmt->execute();
    $stmt->close();
    $got = $conn->query("SELECT * FROM `$table` WHERE id = " . (int) $id)->fetch_assoc();
    if (!$got) {
        json_error('Not found', 404);
    }
    if ($phoneChanged) {
        $message = crm_build_registration_confirmation_message($conn, $got);
        $sendResult = crm_send_panel_sms($newPhone, $message);
        if ($sendResult !== true) {
            comms_insert($conn, $newPhone, 'outgoing', $message, 'sms', 'failed');
            json_error(
                is_string($sendResult) && $sendResult !== ''
                    ? $sendResult
                    : 'SMS send failed',
                502
            );
        }
        comms_insert($conn, $newPhone, 'outgoing', $message, 'sms', 'sent');
    }
    require_once __DIR__ . '/sync/sync_buffer_helpers.php';
    sync_buffer_enqueue_serial_row($conn, $got);
    require_once __DIR__ . '/activity_store.php';
    crm_activity_record($conn, 'registration_updated', [
        'serial' => (string) ($got['serial'] ?? $serial),
        'phone' => isset($got['phone']) ? (string) $got['phone'] : null,
        'date' => $hasJalali ? (string) ($got['date_jalali'] ?? $date) : (string) ($got['time'] ?? $date),
        'city' => isset($got['city']) ? (string) $got['city'] : $citySql,
        'km' => isset($got['km']) && $got['km'] !== null && $got['km'] !== '' ? (int) $got['km'] : $kmSql,
        'message' => 'ویرایش سریال از پنل',
    ]);
    require_once __DIR__ . '/settings_store.php';
    $smsProcessing = crm_settings_get_or_default($conn, 'sms_processing', crm_default_sms_processing());
    $p = isset($got['phone']) ? trim((string) $got['phone']) : '';
    $known = $p !== '' ? serial_known_city_for_phone($conn, $p, (string) ($got['serial'] ?? '')) : null;
    json_out(row_to_serial($got, $smsProcessing, $known));
}

// Real serial group cards (created by batch/complex/import).
require_once __DIR__ . '/serials_write.php';
require_once __DIR__ . '/settings_store.php';

if ($path === '/serial-groups' && $method === 'GET') {
    sw_list_groups($conn);
}

if (preg_match('#^/serial-groups/(\d+)/serials$#', $path, $m) && $method === 'GET') {
    sw_list_group_serials($conn, (int) $m[1]);
}

if (preg_match('#^/serial-groups/(\d+)$#', $path, $m) && $method === 'DELETE') {
    sw_delete_group($conn, (int) $m[1]);
}

if (preg_match('#^/serial-groups/(\d+)$#', $path, $m) && $method === 'PUT') {
    sw_update_group($conn, (int) $m[1], body_json());
}

// Persistable CRM settings (GET/PUT) + club auto rules + admin users.
crm_handle_settings_routes($conn, $method, $path);
crm_handle_club_auto_routes($conn, $method, $path);
crm_handle_admin_user_routes($conn, $method, $path, $user);

require_once __DIR__ . '/comms_bots_routes.php';
crm_handle_comms_routes($conn, $method, $path);
crm_handle_bot_channel_routes($conn, $method, $path);

require_once __DIR__ . '/photo_routes.php';
crm_handle_photo_routes($conn, $method, $path);

require_once __DIR__ . '/lottery_routes.php';
crm_handle_lottery_routes($conn, $method, $path);

if ($path === '/serial-errors/clients' && $method === 'GET') {
    require_once __DIR__ . '/serial_errors_store.php';
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 9)));
    $result = sre_list_clients($conn, $page, $limit);
    crm_debug_mark('serial_errors_clients', count($result['items']) . ' items total=' . $result['total']);
    json_out([
        'items' => $result['items'],
        'total' => $result['total'],
        'page' => $page,
        'limit' => $limit,
    ]);
}

if ($path === '/serial-errors' && $method === 'GET') {
    require_once __DIR__ . '/serial_errors_store.php';
    $phone = trim((string) ($_GET['phone'] ?? ''));
    if ($phone === '') {
        json_error('phone is required', 400);
    }
    $normalized = sre_normalize_phone($phone);
    $items = sre_list_by_phone($conn, $normalized !== '' ? $normalized : $phone);
    json_out([
        'phone' => $normalized !== '' ? $normalized : $phone,
        'items' => $items,
        'total' => count($items),
    ]);
}

if ($path === '/mass-sms/provinces' && $method === 'GET') {
    require_once __DIR__ . '/iran_locations.php';
    json_out(['items' => iran_provinces_load()]);
}

$stubsGet = [
    '/mass-sms/campaigns' => ['items' => []],
    '/referral/customers' => ['items' => [], 'total' => 0],
    '/whatsapp/engine/status' => [
        'online' => false,
        'loggedIn' => false,
        'lastSeen' => null,
        'staleThresholdMs' => 60000,
    ],
    '/whatsapp/campaigns' => ['items' => []],
];

if ($method === 'GET' && isset($stubsGet[$path])) {
    json_out($stubsGet[$path]);
}

// --- Serial generation (range / complex / import) on the remote panel ---
if ($method === 'POST' && (
    $path === '/serials/batch'
    || $path === '/serials/complex-batch'
    || $path === '/serials/import-batch'
)) {
    $body = body_json();
    if ($path === '/serials/batch') {
        sw_handle_range($conn, $body);
    } elseif ($path === '/serials/complex-batch') {
        sw_handle_complex($conn, $body);
    } else {
        sw_handle_import($conn, $body);
    }
}

if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    // Settings / club-auto / admin users / lottery preset / whatsapp config are handled above.
    $settingsWriteOk = starts_with($path, '/settings')
        || starts_with($path, '/club-auto-messages')
        || $path === '/whatsapp/config'
        || starts_with($path, '/lottery')
        || starts_with($path, '/admin/users')
        || $path === '/admin/sync-config'
        || $path === '/admin/cloud-sync/get'
        || $path === '/admin/cloud-sync/send'
        || $path === '/admin/cloud-sync/pull';

    // DELETE/PUT /serial-groups/{id} are handled above; extend remains LAN-only for now.
    if (!$settingsWriteOk && (
        (starts_with($path, '/serial-groups') && $method !== 'DELETE' && $method !== 'PUT')
        || starts_with($path, '/mass-sms')
        || starts_with($path, '/whatsapp')
        || starts_with($path, '/admin')
    )) {
        json_error('Not available on remote panel (use LAN app)', 501);
    }
}

json_error('Not found: ' . $method . ' ' . $path, 404);
