<?php
declare(strict_types=1);

require_once __DIR__ . '/lottery_store.php';

/**
 * Lottery API matching Vue / LAN shapes. Preset stays in settings_store.
 */
function crm_handle_lottery_routes(mysqli $conn, string $method, string $path): bool
{
    if ($path === '/lottery/preset') {
        return false; // settings_store
    }

    if ($path === '/lottery/participants' && $method === 'GET') {
        $city = trim((string) ($_GET['city'] ?? ''));
        $phone = trim((string) ($_GET['phone'] ?? ''));
        $items = lottery_list_participants(
            $conn,
            $city !== '' ? $city : null,
            $phone !== '' ? $phone : null
        );
        json_out(['items' => $items, 'total' => count($items)]);
    }

    if ($path === '/lottery/draw' && $method === 'POST') {
        $body = body_json();
        $city = trim((string) ($_GET['city'] ?? ($body['city'] ?? '')));
        $items = lottery_list_participants($conn, $city !== '' ? $city : null, null);
        $picked = lottery_draw_weighted($conn, $items);
        if ($picked === null) {
            json_error('شرکت‌کننده‌ای برای قرعه‌کشی وجود ندارد', 400);
        }
        json_out($picked);
    }

    if ($path === '/lottery/confirm' && $method === 'POST') {
        $body = body_json();
        $phone = trim((string) ($body['phone'] ?? ''));
        if ($phone === '') {
            json_error('phone is required', 400);
        }
        try {
            json_out(lottery_confirm_winner($conn, $phone));
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage(), 400);
        } catch (Throwable $e) {
            json_error($e->getMessage() ?: 'confirm failed', 500);
        }
    }

    if ($path === '/lottery/winners' && $method === 'GET') {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 30)));
        json_out(lottery_list_winners($conn, $page, $limit));
    }

    if ($path === '/lottery/suspended' && $method === 'GET') {
        json_out(lottery_list_suspended($conn));
    }

    return false;
}
