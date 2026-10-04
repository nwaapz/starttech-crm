<?php
/**
 * Perf diagnostics for the remote panel. Routed before ensure_remote_schema() so it still
 * answers while other requests are stuck on table locks.
 *
 *   GET  /api/debug/db            processlist + index status + table info
 *   POST /api/debug/kill-stuck    kill this DB user's queries running > minSeconds (default 30)
 *   POST /api/debug/add-index     build ONE missing perf index (call repeatedly)
 */
declare(strict_types=1);

function crm_debug_db_auth(mysqli $conn): void
{
    $token = bearer_token();
    if ($token === null || $token === '') {
        json_error('Missing token', 401);
    }
    $stmt = $conn->prepare('SELECT expires_at FROM crm_sessions WHERE token = ? LIMIT 1');
    if (!$stmt) {
        json_error('DB prepare failed: ' . $conn->error, 500);
    }
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    $stmt->close();
    if (!$row || (int) $row['expires_at'] < (int) round(microtime(true) * 1000)) {
        json_error('Invalid credentials', 401);
    }
}

/** @return list<array<string,mixed>> */
function crm_debug_processlist(mysqli $conn): array
{
    $self = 0;
    $r = $conn->query('SELECT CONNECTION_ID() AS id');
    if ($r && ($row = $r->fetch_assoc())) {
        $self = (int) $row['id'];
    }
    $out = [];
    $res = $conn->query('SHOW FULL PROCESSLIST');
    if (!$res) {
        return [['error' => $conn->error]];
    }
    while ($row = $res->fetch_assoc()) {
        $info = preg_replace('/\s+/', ' ', (string) ($row['Info'] ?? '')) ?? '';
        $info = function_exists('mb_substr') ? mb_substr($info, 0, 400) : substr($info, 0, 400);
        $out[] = [
            'id' => (int) $row['Id'],
            'self' => (int) $row['Id'] === $self,
            'command' => (string) ($row['Command'] ?? ''),
            'timeSec' => (int) ($row['Time'] ?? 0),
            'state' => (string) ($row['State'] ?? ''),
            'info' => $info,
        ];
    }
    usort($out, static fn ($a, $b) => $b['timeSec'] <=> $a['timeSec']);
    return $out;
}

$conn->query('SET SESSION lock_wait_timeout = 5');
crm_debug_db_auth($conn);
crm_debug_mark('debug_auth');

$table = table_exists($conn, 'old_serials') ? 'old_serials' : (table_exists($conn, 'new_serials') ? 'new_serials' : '');

if ($path === '/debug/db' && $method === 'GET') {
    $version = '';
    $r = $conn->query('SELECT VERSION() AS v');
    if ($r && ($row = $r->fetch_assoc())) {
        $version = (string) $row['v'];
    }
    $tableInfo = null;
    if ($table !== '') {
        $esc = $conn->real_escape_string($table);
        $r = $conn->query(
            "SELECT ENGINE, TABLE_ROWS, ROUND(DATA_LENGTH/1048576,1) AS data_mb, ROUND(INDEX_LENGTH/1048576,1) AS index_mb
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$esc'"
        );
        if ($r && ($row = $r->fetch_assoc())) {
            $tableInfo = [
                'name' => $table,
                'engine' => $row['ENGINE'],
                'approxRows' => (int) $row['TABLE_ROWS'],
                'dataMb' => (float) $row['data_mb'],
                'indexMb' => (float) $row['index_mb'],
            ];
        }
    }
    crm_debug_mark('debug_table_info');
    $processes = crm_debug_processlist($conn);
    crm_debug_mark('debug_processlist');
    $indexes = $table !== '' ? serial_perf_index_status($conn, $table) : [];
    crm_debug_mark('debug_indexes');
    json_out([
        'mysql' => $version,
        'table' => $tableInfo,
        'indexes' => $indexes,
        'processes' => $processes,
        'cacheDir' => crm_cache_dir() !== '' ? 'ok' : 'unavailable',
        'schemaCached' => crm_cache_get(crm_schema_cache_key(), 3600) !== null,
        'finishRequest' => function_exists('fastcgi_finish_request')
            ? 'fastcgi'
            : (function_exists('litespeed_finish_request') ? 'litespeed' : 'none'),
        'autoIndex' => [
            'done' => crm_cache_get(crm_perf_index_done_key(), 86400) !== null,
            'building' => crm_cache_get('perf_index_auto_building', 900) !== null,
            'failed' => crm_cache_get('perf_index_auto_failed', 3600),
        ],
    ]);
}

if ($path === '/debug/kill-stuck' && $method === 'POST') {
    $body = body_json();
    $minSec = max(10, (int) ($body['minSeconds'] ?? 30));
    $killed = [];
    $failed = [];
    foreach (crm_debug_processlist($conn) as $p) {
        if (!empty($p['self']) || ($p['command'] ?? '') !== 'Query' || (int) $p['timeSec'] < $minSec) {
            continue;
        }
        $id = (int) $p['id'];
        if ($conn->query('KILL ' . $id)) {
            $killed[] = ['id' => $id, 'timeSec' => $p['timeSec'], 'info' => $p['info']];
        } else {
            $failed[] = ['id' => $id, 'error' => $conn->error];
        }
    }
    json_out(['ok' => true, 'minSeconds' => $minSec, 'killed' => $killed, 'failed' => $failed]);
}

if ($path === '/debug/add-index' && $method === 'POST') {
    if ($table === '') {
        json_error('No serials table', 500);
    }
    $result = serial_add_next_perf_index($conn, $table);
    crm_debug_mark('debug_add_index', $result['name'] ?? 'none');
    if ($result['ok']) {
        crm_cache_delete('perf_index_auto_failed');
        if ($result['remaining'] === 0) {
            crm_cache_set(crm_perf_index_done_key(), ['at' => time()]);
        }
    }
    json_out($result + ['indexes' => serial_perf_index_status($conn, $table)]);
}

json_error('Not found: ' . $method . ' ' . $path, 404);
