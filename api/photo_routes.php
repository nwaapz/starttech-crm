<?php
declare(strict_types=1);

require_once __DIR__ . '/photo_verification.php';

/**
 * Admin photo review API — matches LAN /api/serial-photo-submissions*.
 */
function crm_handle_photo_routes(mysqli $conn, string $method, string $path): bool
{
    if ($path === '/serial-photo-submissions' && $method === 'GET') {
        $status = trim((string) ($_GET['status'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 30)));
        json_out(photo_list_submissions($conn, $status, $page, $limit));
    }

    if (preg_match('#^/serial-photo-submissions/(\d+)/image$#', $path, $m) && $method === 'GET') {
        $row = photo_find($conn, (int) $m[1]);
        if (!$row) {
            json_error('Image not found', 404);
        }
        $file = (string) ($row['local_path'] ?? '');
        if ($file === '' || !is_file($file)) {
            json_error('Image not found', 404);
        }
        $mime = (string) ($row['mime_type'] ?? 'image/jpeg');
        if (strpos($mime, 'png') !== false) {
            $mime = 'image/png';
        } elseif (strpos($mime, 'webp') !== false) {
            $mime = 'image/webp';
        } elseif (strpos($mime, 'gif') !== false) {
            $mime = 'image/gif';
        } else {
            $mime = 'image/jpeg';
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($file));
        header('Cache-Control: private, max-age=3600');
        readfile($file);
        exit;
    }

    if (preg_match('#^/serial-photo-submissions/(\d+)/approve$#', $path, $m) && $method === 'POST') {
        $body = body_json();
        try {
            json_out(photo_approve($conn, (int) $m[1], isset($body['note']) ? (string) $body['note'] : null));
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage(), 400);
        }
    }

    if (preg_match('#^/serial-photo-submissions/(\d+)/reject$#', $path, $m) && $method === 'POST') {
        $body = body_json();
        try {
            json_out(photo_reject(
                $conn,
                (int) $m[1],
                isset($body['reasonCode']) ? (string) $body['reasonCode'] : null,
                isset($body['note']) ? (string) $body['note'] : null
            ));
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage(), 400);
        }
    }

    return false;
}
