<?php
/**
 * REST for club announcements (web CRM drip SMS).
 */
declare(strict_types=1);

require_once __DIR__ . '/announcement_runner.php';

function crm_ann_parse_body(array $body): array
{
    $ceiling = normalize_jalali_date_param((string) ($body['ceilingDateJalali'] ?? $body['ceiling_date_jalali'] ?? ''));
    if ($ceiling === '') {
        json_error('تاریخ سقف (جلالی) الزامی است', 400);
    }
    $message = trim((string) ($body['message'] ?? ''));
    if ($message === '') {
        json_error('متن اطلاعیه الزامی است', 400);
    }
    $enabled = array_key_exists('enabled', $body) ? (bool) $body['enabled'] : true;
    $status = $enabled ? 'idle' : 'paused';
    return [
        'title' => trim((string) ($body['title'] ?? '')),
        'message' => $message,
        'ceiling' => $ceiling,
        'dailyCap' => max(1, min(10000, (int) ($body['dailyCap'] ?? $body['daily_cap'] ?? 300))),
        'delaySeconds' => max(0, min(120, (int) ($body['delaySeconds'] ?? $body['delay_seconds'] ?? 2))),
        'enabled' => $enabled ? 1 : 0,
        'status' => $status,
    ];
}

function crm_handle_announcement_routes(mysqli $conn, string $method, string $path): bool
{
    if (!starts_with($path, '/announcements')) {
        return false;
    }
    crm_ann_ensure_schema($conn);

    if ($path === '/announcements' && $method === 'GET') {
        $res = $conn->query('SELECT * FROM club_announcements ORDER BY id DESC');
        $items = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $items[] = crm_ann_to_api($row);
            }
        }
        json_out(['items' => $items]);
    }

    if ($path === '/announcements' && $method === 'POST') {
        $parsed = crm_ann_parse_body(body_json());
        $now = (int) round(microtime(true) * 1000);
        $stmt = $conn->prepare(
            'INSERT INTO club_announcements
                (title, message, ceiling_date_jalali, cursor_date_jalali, daily_cap, delay_seconds,
                 enabled, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            json_error('DB prepare failed: ' . $conn->error, 500);
        }
        $stmt->bind_param(
            'ssssiiisii',
            $parsed['title'],
            $parsed['message'],
            $parsed['ceiling'],
            $parsed['ceiling'],
            $parsed['dailyCap'],
            $parsed['delaySeconds'],
            $parsed['enabled'],
            $parsed['status'],
            $now,
            $now
        );
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            json_error('Insert failed: ' . $err, 500);
        }
        $id = (int) $stmt->insert_id;
        $stmt->close();
        $got = $conn->query('SELECT * FROM club_announcements WHERE id = ' . $id)->fetch_assoc();
        json_out(crm_ann_to_api($got ?: []), 201);
    }

    if (preg_match('#^/announcements/(\d+)$#', $path, $m)) {
        $id = (int) $m[1];
        if ($method === 'GET') {
            $got = $conn->query('SELECT * FROM club_announcements WHERE id = ' . $id)->fetch_assoc();
            if (!$got) {
                json_error('Not found', 404);
            }
            json_out(crm_ann_to_api($got));
        }
        if ($method === 'PUT') {
            $existing = $conn->query('SELECT * FROM club_announcements WHERE id = ' . $id)->fetch_assoc();
            if (!$existing) {
                json_error('Not found', 404);
            }
            $parsed = crm_ann_parse_body(body_json());
            $now = (int) round(microtime(true) * 1000);
            $cursor = (string) ($existing['cursor_date_jalali'] ?? $parsed['ceiling']);
            $status = (string) ($existing['status'] ?? 'idle');
            $ceilingChanged = $parsed['ceiling'] !== (string) ($existing['ceiling_date_jalali'] ?? '');
            if ($ceilingChanged) {
                $cursor = $parsed['ceiling'];
                $status = $parsed['enabled'] ? 'idle' : 'paused';
            } elseif ($cursor > $parsed['ceiling']) {
                $cursor = $parsed['ceiling'];
            }
            if ($status !== 'completed') {
                $status = $parsed['enabled'] ? 'idle' : 'paused';
            }
            $stmt = $conn->prepare(
                'UPDATE club_announcements SET
                    title=?, message=?, ceiling_date_jalali=?, cursor_date_jalali=?,
                    daily_cap=?, delay_seconds=?, enabled=?, status=?, updated_at=?
                 WHERE id=?'
            );
            if (!$stmt) {
                json_error('DB prepare failed: ' . $conn->error, 500);
            }
            $stmt->bind_param(
                'ssssiiisii',
                $parsed['title'],
                $parsed['message'],
                $parsed['ceiling'],
                $cursor,
                $parsed['dailyCap'],
                $parsed['delaySeconds'],
                $parsed['enabled'],
                $status,
                $now,
                $id
            );
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                json_error('Update failed: ' . $err, 500);
            }
            $stmt->close();
            $got = $conn->query('SELECT * FROM club_announcements WHERE id = ' . $id)->fetch_assoc();
            json_out(crm_ann_to_api($got ?: $existing));
        }
        if ($method === 'DELETE') {
            $stmt = $conn->prepare('DELETE FROM club_announcements WHERE id = ?');
            if (!$stmt) {
                json_error('DB prepare failed: ' . $conn->error, 500);
            }
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected <= 0) {
                json_error('Not found', 404);
            }
            $conn->query('DELETE FROM club_announcement_sends WHERE announcement_id = ' . $id);
            http_response_code(204);
            exit;
        }
    }

    if (preg_match('#^/announcements/(\d+)/run-now$#', $path, $m) && $method === 'POST') {
        $id = (int) $m[1];
        $got = $conn->query('SELECT * FROM club_announcements WHERE id = ' . $id)->fetch_assoc();
        if (!$got) {
            json_error('Not found', 404);
        }
        crm_ann_run_one($conn, $got, true);
        $fresh = $conn->query('SELECT * FROM club_announcements WHERE id = ' . $id)->fetch_assoc();
        json_out(crm_ann_to_api($fresh ?: $got));
    }

    if (preg_match('#^/announcements/(\d+)/sends$#', $path, $m) && $method === 'GET') {
        $id = (int) $m[1];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;
        $total = 0;
        $tr = $conn->query(
            'SELECT COUNT(*) AS c FROM club_announcement_sends WHERE announcement_id = ' . $id
        );
        if ($tr && ($tc = $tr->fetch_assoc())) {
            $total = (int) ($tc['c'] ?? 0);
        }
        $items = [];
        $stmt = $conn->prepare(
            'SELECT id, phone, serial, sent_at, sent_date_jalali, status
             FROM club_announcement_sends
             WHERE announcement_id = ?
             ORDER BY id DESC
             LIMIT ? OFFSET ?'
        );
        if ($stmt) {
            $stmt->bind_param('iii', $id, $limit, $offset);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $items[] = [
                        'id' => (int) $row['id'],
                        'phone' => (string) $row['phone'],
                        'serial' => (string) ($row['serial'] ?? ''),
                        'sentAt' => (int) ($row['sent_at'] ?? 0),
                        'sentDateJalali' => (string) ($row['sent_date_jalali'] ?? ''),
                        'status' => (string) ($row['status'] ?? 'sent'),
                    ];
                }
            }
            $stmt->close();
        }
        json_out(['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    json_error('Not found', 404);
    return false;
}
