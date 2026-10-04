<?php
/**
 * Shared device sync token auth for /api/sync/* endpoints.
 */
declare(strict_types=1);

function require_sync_token(): void {
    global $PAYAMESH_SYNC_TOKEN;
    $token = bearer_token();
    if ($token === null || $token === '') {
        $token = trim((string) ($_SERVER['HTTP_X_SYNC_TOKEN'] ?? ''));
    }
    if ($token === '') {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    $expected = (string) ($PAYAMESH_SYNC_TOKEN ?? '');
    if ($expected !== '' && hash_equals($expected, $token)) {
        return;
    }
    $conn = payamesh_mysqli();
    $row = $conn->query("SELECT meta_value FROM crm_sync_meta WHERE meta_key='device_token' LIMIT 1");
    $assoc = ($row instanceof mysqli_result) ? $row->fetch_assoc() : null;
    $stored = is_array($assoc) ? (string) ($assoc['meta_value'] ?? '') : '';
    if ($stored !== '' && hash_equals($stored, $token)) {
        return;
    }
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

function sync_json_headers(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Sync-Token');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        exit;
    }
}
